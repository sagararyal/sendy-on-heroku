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

# deploy.sh may strip some override paths before copying them into sendy/
# (stock ships instead). Parse `rm -rf overrides/<path>` so we don't flag
# those as needing review — our file never wins for them.
DELETED_PATHS=()
if [[ -f deploy.sh ]]; then
	while IFS= read -r p; do
		[[ -n "$p" ]] && DELETED_PATHS+=("$p")
	done < <(grep -oE 'rm[[:space:]]+-rf[[:space:]]+overrides/[^[:space:]]+' deploy.sh \
		| sed -E 's#^rm[[:space:]]+-rf[[:space:]]+overrides/##; s#/\*+$##; s#/+$##')
fi

is_deploy_deleted() {
	local rel="$1" d
	for d in "${DELETED_PATHS[@]+"${DELETED_PATHS[@]}"}"; do
		[[ "$rel" == "$d" || "$rel" == "$d"/* ]] && return 0
	done
	return 1
}

# An override file can declare how it relates to stock with a marker comment:
#   [OVERRIDE-STRATEGY: marker-diff]  preserves stock structure -> port upstream changes
#   [OVERRIDE-STRATEGY: clobber]      fully replaces stock      -> stock changes are moot
override_strategy() {
	local f="$1"
	if grep -qE '\[OVERRIDE-STRATEGY:[[:space:]]*clobber\]' "$f" 2>/dev/null; then
		echo clobber
	elif grep -qE '\[OVERRIDE-STRATEGY:[[:space:]]*marker-diff\]' "$f" 2>/dev/null; then
		echo marker-diff
	else
		echo unknown
	fi
}

# For a clobber override, the real risk is stock adding a define() the override
# doesn't carry (a constant the rest of Sendy now needs at boot). List defines
# that are new in stock-new and absent from our override.
clobber_missing_defines() {
	local old="$1" new="$2" ovr="$3" sym
	comm -13 \
		<(grep -hoE "define\('[A-Za-z0-9_]+'" "$old" 2>/dev/null | sed -E "s/define\('//" | sort -u) \
		<(grep -hoE "define\('[A-Za-z0-9_]+'" "$new" 2>/dev/null | sed -E "s/define\('//" | sort -u) \
	| while IFS= read -r sym; do
		sym="${sym%\'}"
		[[ -z "$sym" ]] && continue
		grep -qF "define('$sym'" "$ovr" 2>/dev/null || echo "$sym"
	done
}

report "# Sendy upgrade audit: $OLD → $NEW"
report ""

# (1) Changed paths we override --------------------------------------------
report "## 1. Stock changes in files we override"
report ""

CHANGED_COUNT=0
NEW_OVERRIDE_COUNT=0
DROPPED_COUNT=0
CLOBBER_MOOT_COUNT=0

# Walk overrides/ and check each file's stock counterpart
while IFS= read -r override_file; do
	rel="${override_file#overrides/}"
	old_path="$OLD_ROOT/$rel"
	new_path="$NEW_ROOT/$rel"

	# Paths deploy.sh strips before copying: stock ships, our file never wins.
	if is_deploy_deleted "$rel"; then
		if [[ -f "$old_path" ]] && ! diff -q "$old_path" "$new_path" >/dev/null 2>&1; then
			report "- \`$rel\` — stock changed, but deploy.sh deletes this from overrides before copying → stock ships, no action"
			((DROPPED_COUNT+=1)) || true
		fi
		continue
	fi

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
			((CHANGED_COUNT+=1)) || true
			continue
		fi
		case "$(override_strategy "$override_file")" in
			clobber)
				missing="$(clobber_missing_defines "$old_path" "$new_path" "$override_file" | paste -sd, - )"
				if [[ -n "$missing" ]]; then
					report "- **\`$rel\`** — stock changed; override is a full clobber, BUT stock added define(s) you lack: \`$missing\`. Port these into the override."
					((CHANGED_COUNT+=1)) || true
				else
					report "- \`$rel\` — stock changed, but your override fully replaces it (clobber) → change is moot, no action"
					((CLOBBER_MOOT_COUNT+=1)) || true
				fi
				;;
			marker-diff)
				report "- **\`$rel\`** — stock changed; override is marker-diff (preserves stock structure) → PORT the upstream change"
				((CHANGED_COUNT+=1)) || true
				;;
			*)
				report "- **\`$rel\`** — stock changed; review override for missed upstream fixes (no [OVERRIDE-STRATEGY] marker found)"
				((CHANGED_COUNT+=1)) || true
				;;
		esac
	fi
done < <(find overrides -type f \( -name '*.php' -o -name '*.po' -o -name '*.mo' -o -name '*.ini' \))

if [[ $CHANGED_COUNT -eq 0 && $NEW_OVERRIDE_COUNT -eq 0 && $DROPPED_COUNT -eq 0 && $CLOBBER_MOOT_COUNT -eq 0 ]]; then
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

# Files added or changed in stock from old to new.
# diff -rq emits two line shapes; both need parsing, and neither is ": "-delimited
# in a way a single awk can handle:
#   Files <old>/<rel> and <new>/<rel> differ
#   Only in <dir>: <name>            <- <name> may be a file or a whole directory
diff -rq "$OLD_ROOT" "$NEW_ROOT" 2>/dev/null \
	| while IFS= read -r line; do
		case "$line" in
			"Files "*" differ")
				p=${line#Files }
				p=${p% differ}
				p=${p%% and *}
				printf '%s\n' "${p#"$OLD_ROOT"/}"
				;;
			"Only in "*)
				rest=${line#Only in }
				name=${rest##*: }
				dir=${rest%%: *}
				p="$dir/$name"
				# Only "new in NEW_ROOT" matters; things only in OLD are removals.
				case "$p" in
					"$NEW_ROOT"/*) ;;
					*) continue ;;
				esac
				if [[ -d "$p" ]]; then
					find "$p" -type f -name '*.php' 2>/dev/null \
						| sed "s|^$NEW_ROOT/||"
				else
					printf '%s\n' "${p#"$NEW_ROOT"/}"
				fi
				;;
		esac
	done \
	| grep -E '\.php$' \
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
		# Show the actual DDL so the user can judge migration weight, not just its existence.
		while IFS= read -r ddl; do
			ddl=${ddl#> }
			ddl=$(printf '%s' "$ddl" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
			[[ -z "$ddl" ]] && continue
			report "    - \`${ddl:0:160}\`"
		done <<< "$hits"
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
report "- Override paths needing action (port upstream): **$CHANGED_COUNT**"
report "- Stock changes neutralized by clobber overrides: **$CLOBBER_MOOT_COUNT**"
report "- Override paths dropped at deploy (stock wins): **$DROPPED_COUNT**"
report "- New files in dirs you override: **$NEW_FILE_COUNT**"
report "- Schema hints: **$SQL_HITS**"
report ""

if [[ $CHANGED_COUNT -eq 0 ]]; then
	extra=""
	[[ $NEW_FILE_COUNT -gt 0 ]] && extra="$NEW_FILE_COUNT new stock file(s) ship untouched. "
	[[ $SQL_HITS -gt 0 ]] && extra="${extra}Possible schema migration on first request. "
	report "No override edits required. ${extra}Still run the smoke checklist below."
else
	report "**$CHANGED_COUNT override path(s) need attention** before bumping \`SENDY_VERSION\` in \`.env\`. See section 1."
fi
