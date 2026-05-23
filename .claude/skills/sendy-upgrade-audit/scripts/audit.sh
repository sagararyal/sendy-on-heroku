#!/usr/bin/env bash
# sendy-upgrade-audit/audit.sh
#
# Diff a Sendy override stack against two stock Sendy versions and print
# a Markdown report. Designed to run from the project root of a repo that
# follows the override-on-stock pattern (overrides/, deploy.sh, .env).
#
# Usage:
#   audit.sh [OLD_VERSION] NEW_VERSION
#
# If OLD_VERSION is omitted, it's read from .env's SENDY_VERSION.

set -euo pipefail

usage() {
	cat >&2 <<EOF
Usage: $0 [OLD_VERSION] NEW_VERSION

  OLD_VERSION  Defaults to SENDY_VERSION in .env if omitted.
  NEW_VERSION  Required. The Sendy version to compare against.

Run from the project root (the directory containing overrides/ and .env).
EOF
	exit 64
}

# --- Arg parsing ---------------------------------------------------------
case $# in
	1) OLD=""; NEW="$1" ;;
	2) OLD="$1"; NEW="$2" ;;
	*) usage ;;
esac

[[ -d overrides ]] || { echo "error: no overrides/ directory in $(pwd). Run from the project root." >&2; exit 1; }
[[ -f .env ]] || { echo "error: no .env in $(pwd). The audit needs SENDY_ARCHIVE_URL + SENDY_LICENSE_CODE." >&2; exit 1; }

# Load .env without polluting our own shell
set -a
# shellcheck disable=SC1091
source .env
set +a

[[ -z "$OLD" ]] && OLD="${SENDY_VERSION:-}"
[[ -z "$OLD" || -z "$NEW" ]] && { echo "error: both OLD and NEW versions required (OLD can come from .env)." >&2; usage; }
[[ -z "${SENDY_ARCHIVE_URL:-}" ]] && { echo "error: SENDY_ARCHIVE_URL not set in .env." >&2; exit 1; }

mkdir -p archives

# --- Resolve / download versions ----------------------------------------
# Accept either archives/$v/sendy or archives/v$v/sendy as a pre-extracted copy.
resolve_archive_dir() {
	local v="$1"
	for candidate in "archives/$v" "archives/v$v"; do
		if [[ -d "$candidate/sendy" ]]; then
			printf '%s' "$candidate"
			return 0
		fi
	done
	return 1
}

fetch_version() {
	local v="$1"
	if resolve_archive_dir "$v" >/dev/null; then
		return 0
	fi
	local target="archives/$v"
	echo "» downloading Sendy $v ..." >&2
	mkdir -p "$target"
	local zip="$target/sendy.zip"
	# Sendy's archive URL pattern: $URL?version=$V (license is encoded in URL or in headers
	# depending on how the user's archive proxy is set up; the deploy.sh pattern is followed here).
	if ! curl -fsSL -o "$zip" "${SENDY_ARCHIVE_URL}?version=${v}"; then
		echo "error: failed to download Sendy $v from $SENDY_ARCHIVE_URL" >&2
		exit 2
	fi
	# Verify it's a zip, not an error page
	if ! file "$zip" | grep -qi zip; then
		echo "error: downloaded file for $v is not a zip archive. Check SENDY_LICENSE_CODE and domain restrictions." >&2
		head -3 "$zip" >&2 || true
		exit 2
	fi
	( cd "$target" && unzip -q sendy.zip && rm sendy.zip )
}

fetch_version "$OLD"
fetch_version "$NEW"

OLD_ROOT="$(resolve_archive_dir "$OLD")/sendy"
NEW_ROOT="$(resolve_archive_dir "$NEW")/sendy"

[[ -d "$OLD_ROOT" ]] || { echo "error: archives/$OLD/sendy/ missing after extraction." >&2; exit 1; }
[[ -d "$NEW_ROOT" ]] || { echo "error: archives/$NEW/sendy/ missing after extraction." >&2; exit 1; }

# --- Audit --------------------------------------------------------------
report() { printf '%s\n' "$@"; }

report "# Sendy upgrade audit: $OLD → $NEW"
report ""

# (1) Changed paths we override --------------------------------------------
report "## 1. Stock changes in files we override"
report ""

CHANGED_COUNT=0
NEW_OVERRIDE_COUNT=0

# Walk overrides/ and check each file's stock counterpart
while IFS= read -r override_file; do
	rel="${override_file#overrides/}"
	old_path="$OLD_ROOT/$rel"
	new_path="$NEW_ROOT/$rel"

	# Override has no corresponding stock file in OLD (we introduced it)
	if [[ ! -f "$old_path" ]]; then
		if [[ -f "$new_path" ]]; then
			report "- **\`$rel\`** — new in stock $NEW (we already shadow it)"
		else
			report "- \`$rel\` — purely additive (no stock counterpart in either version)"
		fi
		((NEW_OVERRIDE_COUNT+=1)) || true
		continue
	fi

	# Compare stock-old vs stock-new
	if ! diff -q "$old_path" "$new_path" >/dev/null 2>&1; then
		if [[ ! -f "$new_path" ]]; then
			report "- **\`$rel\`** — REMOVED in stock $NEW (our override may be orphaned)"
		else
			report "- **\`$rel\`** — stock changed; review override for missed upstream fixes"
		fi
		((CHANGED_COUNT+=1)) || true
	fi
done < <(find overrides -type f \( -name '*.php' -o -name '*.po' -o -name '*.mo' -o -name '*.ini' \))

if [[ $CHANGED_COUNT -eq 0 && $NEW_OVERRIDE_COUNT -eq 0 ]]; then
	report "_None — every overridden stock path is unchanged between versions._"
fi
report ""

# (2) New files in directories we touch ------------------------------------
report "## 2. New files in directories we override"
report ""

NEW_FILE_COUNT=0
while IFS= read -r override_dir; do
	rel_dir="${override_dir#overrides/}"
	old_dir="$OLD_ROOT/$rel_dir"
	new_dir="$NEW_ROOT/$rel_dir"
	[[ -d "$old_dir" && -d "$new_dir" ]] || continue
	# Files present in NEW that aren't in OLD
	while IFS= read -r new_file; do
		report "- \`$rel_dir/$new_file\`"
		((NEW_FILE_COUNT+=1)) || true
	done < <(comm -23 <(ls "$new_dir" | sort) <(ls "$old_dir" | sort))
done < <(find overrides -mindepth 1 -type d)

if [[ $NEW_FILE_COUNT -eq 0 ]]; then
	report "_None — no new files in directories you currently override._"
fi
report ""

# (3) Schema-migration smell ----------------------------------------------
report "## 3. Schema-migration hints"
report ""

# Compare every PHP file between old and new for new SQL DDL / column-shaped strings.
# This is heuristic — false positives are fine, false negatives are the risk.
TMP=$(mktemp -d)
trap "rm -rf $TMP" EXIT

# Files added or changed in stock from old to new
diff -rq "$OLD_ROOT" "$NEW_ROOT" 2>/dev/null \
	| grep -E '\.php( and |$)' \
	| awk -F': ' '{print $2}' \
	| awk '{print $NF}' \
	| sed "s|^$NEW_ROOT/||; s|^$OLD_ROOT/||" \
	| sort -u > "$TMP/changed_php.txt" || true

SQL_HITS=0
while IFS= read -r php_rel; do
	[[ -z "$php_rel" ]] && continue
	new_file="$NEW_ROOT/$php_rel"
	old_file="$OLD_ROOT/$php_rel"
	[[ -f "$new_file" ]] || continue
	if [[ -f "$old_file" ]]; then
		hits=$(diff "$old_file" "$new_file" \
			| grep '^>' \
			| grep -E -i 'ALTER TABLE|CREATE TABLE|ADD COLUMN|DROP COLUMN|CREATE INDEX' \
			|| true)
	else
		hits=$(grep -E -i 'ALTER TABLE|CREATE TABLE|ADD COLUMN|DROP COLUMN|CREATE INDEX' "$new_file" || true)
	fi
	if [[ -n "$hits" ]]; then
		report "- \`$php_rel\`"
		((SQL_HITS+=1)) || true
	fi
done < "$TMP/changed_php.txt"

if [[ $SQL_HITS -eq 0 ]]; then
	report "_None detected. (Heuristic only — Sendy often runs migrations from its encoded loader, which this script can't read.)_"
else
	report ""
	report "On first request after deploy, Sendy usually self-migrates. If you want to trigger it deliberately, hit \`/_install.php\` on a staging environment."
fi
report ""

# (4) Top-line totals -----------------------------------------------------
report "## 4. Summary"
report ""
report "- Override paths with stock changes: **$CHANGED_COUNT**"
report "- New files in dirs you override: **$NEW_FILE_COUNT**"
report "- Schema hints: **$SQL_HITS**"
report ""

if [[ $CHANGED_COUNT -eq 0 && $NEW_FILE_COUNT -eq 0 && $SQL_HITS -eq 0 ]]; then
	report "Upgrade looks safe to bump. Still run the smoke checklist below."
else
	report "Review the items above before bumping \`SENDY_VERSION\` in \`.env\`."
fi
