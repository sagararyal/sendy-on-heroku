---
name: sendy-upgrade-audit
description: Audit whether an override-based Sendy customization still applies cleanly when Sendy releases a new version. Use whenever the user mentions a new Sendy release ("Sendy 7.0.4 just came out", "Sendy upgrade", "new Sendy version", "audit sendy upgrade", "check sendy version", "upgrade Sendy") in a repo that has an overrides/ folder + deploy.sh layered on top of stock Sendy. Diffs stock-old vs stock-new for every overridden path, lists new files Sendy added in directories we already touch, surfaces likely schema migrations, and prints a smoke-test checklist tailored to the override stack. Triggers in any repo whose layout matches the override pattern (overrides/, deploy.sh that downloads sendy.zip, .env with SENDY_ARCHIVE_URL).
---

# sendy-upgrade-audit

This skill answers one question: *"Sendy just released v7.x.y — do my overrides still survive it?"*

**Scope: the override layer only.** This is not release QA for Sendy. Whether Sendy's own new features work is Sendy's problem. The checklist this skill produces exists to confirm *our overrides still land*, nothing more — every item must trace back to a path under `overrides/`. Report new stock files and schema migrations as short factual notes, not as risk analysis or a test plan.

## When to run this

The user has a Sendy customization repo with this shape:
- `overrides/` — files that shadow stock Sendy paths (copied into `sendy/` at deploy time)
- `deploy.sh` — downloads `sendy.zip` for a given `SENDY_VERSION` and applies overrides
- `.env` — has `SENDY_LICENSE_CODE`, `SENDY_ARCHIVE_URL`, `SENDY_VERSION`

Run whenever Sendy ships a new release and the user wants to know what changed before bumping `SENDY_VERSION`.

## What this skill does

1. Figures out the old version (currently in `.env`'s `SENDY_VERSION`) and the new version (argument, or asks the user).
2. Downloads both versions into `archives/{old}/` and `archives/{new}/` if they're not already there. `archives/` should be in `.gitignore`.
3. Runs `scripts/audit.sh` which prints a structured report:
   - **Changed paths**: stock files that changed between versions AND that we override. The script classifies each by the override's declared strategy (see "Interpreting the report"): `marker-diff` → port the change; `clobber` → moot unless stock added a new required `define()`; dropped-at-deploy → stock ships, no action.
   - **New files in overridden dirs**: potential new hooks, helpers, entry points
   - **Schema hints**: PHP files that gained `ALTER TABLE`, new column references, or new SQL — likely DB migration
4. Generates a smoke-test checklist scoped to what the override stack does (S3 uploads, filemanager disablement, etc.) — one item per override, no general release testing.

## How to run

The deterministic part lives in `scripts/audit.sh`. From the project root:

```bash
# Run from the project root, using the path where this skill is installed.
# It may be repo-local (.claude/skills/...) or user-global (~/.claude/skills/...).
.claude/skills/sendy-upgrade-audit/scripts/audit.sh OLD_VERSION NEW_VERSION
```

If `OLD_VERSION` is omitted, the script reads `SENDY_VERSION` from `.env`. If `NEW_VERSION` is omitted, the script exits with usage text — the user has to name a target.

The script:
- Sources `.env` for `SENDY_ARCHIVE_URL` and `SENDY_LICENSE_CODE`
- Downloads each missing version via `curl "$SENDY_ARCHIVE_URL?version=$V"` and unzips into `archives/$V/` (mirrors `deploy.sh`'s pattern)
- Walks `overrides/**` and for each PHP file with a corresponding stock path, does `diff -q` between `archives/$OLD/sendy/$path` and `archives/$NEW/sendy/$path`
- For each directory under `overrides/` that maps to a stock dir, does `diff` of `ls` output to surface new files
- Greps the diff range for SQL-migration smell (`ALTER TABLE`, `CREATE TABLE`, new `INSERT INTO settings`, fresh column names)
- Prints a Markdown report on stdout

## Interpreting the report

After the script prints, walk through it with the user:

- **Changed paths section:** the script classifies each changed override by an `[OVERRIDE-STRATEGY: ...]` marker it reads from the override file's header comment:
  - `marker-diff` (e.g. `upload.php`, which preserves stock structure with `// [S3] stock:` comments) → **port the upstream change**, and update the commented-out `stock:` lines to match the new stock so future diffs stay accurate. Cosmetic-only upstream changes (whitespace, comments) still need no action; new params, signature changes, and security checks must be ported.
  - `clobber` (e.g. `config.php`, `filemanager/index.php`, which fully replace stock and exit) → the change is **moot**, no action. The one exception the script checks for: a `clobber` of a config-style file where stock added a **new `define()`** the override lacks — Sendy may need that constant at boot, so port just the new define. The script lists any such missing defines inline.
  - No marker → the script falls back to "review manually". Add an `[OVERRIDE-STRATEGY: ...]` marker comment to the override so future audits self-classify.
  - **Dropped at deploy:** if `deploy.sh` strips an override path before copying (e.g. `rm -rf overrides/locale/en_US/*`), the script reports it as "stock ships, no action" — stock always wins there, so upstream changes to it can't affect you. Do not chase these.
- **New files section:** ask the user whether the new feature is wanted. If yes, decide whether to override (e.g., to disable) or just let it be.
- **Schema hints:** state them as fact and stop — which tables/columns change, and that Sendy self-migrates on the first request after deploy. One line. Don't estimate migration risk, don't build a verification plan, don't offer to query the database. If a migration looks genuinely heavy (a backfill over a large table), one sentence flagging it is the ceiling; the user can ask for more.

## Smoke-test checklist (override survival only)

After the audit, generate a checklist from the override paths. **Every item must trace back to a file under `overrides/`** — if you can't name the override an item is testing, drop it. Read the override paths and infer:

- If `overrides/includes/create/upload.php` exists → test image upload in both GrapesJS editor and CKEditor classic, and confirm the asset lands in the configured bucket
- If `overrides/includes/filemanager/` exists → confirm filemanager UI behaves as the override intends (works / 410s / etc.)
- If `overrides/includes/config.php` has `HTTP_X_FORWARDED_PROTO` handling → confirm HTTPS detection via the prod proxy (no mixed-content warnings in DevTools console)
- If `overrides/locale/` has translations → spot-check a translated string on the relevant locale
- If `overrides/.user.ini` sets PHP limits → confirm an upload at the intended size ceiling succeeds

Reaching an override may require ordinary app steps (log in, open the campaign editor). Those are the route to the test, not test items — don't list them separately. Do **not** add general release checks: dashboard rendering, sending campaigns, or exercising Sendy's new features are out of scope.

Present the checklist as Markdown checkboxes the user can copy.

## Stuff to never assume

- **Never invent a stock path.** Only diff a stock path that actually exists in `archives/$OLD/sendy/`. New files we override (`overrides/includes/filemanager/index.php` shadows a file stock only introduced in 7.1.1) shouldn't trip "missing in old" — flag them as "new file we already override".
- **An override can be orphaned by an upstream rename.** If a stock path an override shadows is **deleted** in the new version, the override becomes dead weight: `deploy.sh` still copies it, but nothing loads it, so whatever it was protecting now ships as stock. The script reports this as "REMOVED in stock". Always check where the functionality moved.
- **Overriding a path is not the same as disabling a feature.** The script only inspects paths already under `overrides/`, so it cannot see a *new* entry point stock adds in a file you don't shadow. When an override exists to disable something, grep the new stock tree for fresh references to that subsystem. 7.1.x is the cautionary case: it added a second file-manager link in `includes/sidebar.php`, which no amount of diffing the overridden paths would reveal.
- **License downloads can fail.** If `curl` returns a non-zip response (HTML error page, empty body), tell the user — don't pretend the diff succeeded.
- **Archives might already be unzipped.** Skip the download step if `archives/$V/sendy/` already exists.

## Failure modes to mention to the user

- If `.env` is missing → say so and ask for the values
- If the new version's zip download returns a redirect to a Sendy error page → likely a stale or domain-restricted license. Tell the user to verify their license in Sendy's portal.
- If the audit finds zero changed paths AND zero new files → upgrade is probably free. Still run the override checklist to confirm the overrides land.

## Closing the loop

End the conversation by:
1. Showing the report, led by the override verdict (needs-porting / clobber-moot / dropped-at-deploy)
2. Showing the override survival checklist
3. Asking the user whether they want to proceed: bump `SENDY_VERSION` in `.env`, run `bash deploy.sh`, and walk through the checklist together.

Keep the report proportional. A clean audit is a short answer — "no override edits required, here's the checklist" — not an essay.

> **The version bump is usually not a git commit.** If `SENDY_VERSION` lives only in `.env` (gitignored) and tracked files (`deploy.sh`, `docker-compose.yml`) just read it as an env var, the upgrade is an environment/ops change: edit `.env` locally, or update the host's config var (Heroku/Coolify), then redeploy. `deploy.sh` re-downloads stock and re-applies `overrides/` every run, so the version is never checked in. Only commit when an override file actually needed editing (a `marker-diff` port, or a new `define()` added to a `clobber` config).
