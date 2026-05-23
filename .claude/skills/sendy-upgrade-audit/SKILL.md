---
name: sendy-upgrade-audit
description: Audit whether an override-based Sendy customization still applies cleanly when Sendy releases a new version. Use whenever the user mentions a new Sendy release ("Sendy 7.0.4 just came out", "Sendy upgrade", "new Sendy version", "audit sendy upgrade", "check sendy version", "upgrade Sendy") in a repo that has an overrides/ folder + deploy.sh layered on top of stock Sendy. Diffs stock-old vs stock-new for every overridden path, lists new files Sendy added in directories we already touch, surfaces likely schema migrations, and prints a smoke-test checklist tailored to the override stack. Triggers in any repo whose layout matches the override pattern (overrides/, deploy.sh that downloads sendy.zip, .env with SENDY_ARCHIVE_URL).
---

# sendy-upgrade-audit

This skill answers one question: *"Sendy just released v7.x.y — do my overrides still work, and what do I need to test?"*

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
   - **Changed paths**: stock files that changed between versions AND that we override → review needed
   - **New files in overridden dirs**: potential new hooks, helpers, entry points
   - **Schema hints**: PHP files that gained `ALTER TABLE`, new column references, or new SQL — likely DB migration
4. Generates a smoke-test checklist scoped to what the override stack does (S3 uploads, filemanager disablement, etc.).

## How to run

The deterministic part lives in `scripts/audit.sh`. From the project root:

```bash
~/.claude/skills/sendy-upgrade-audit/scripts/audit.sh OLD_VERSION NEW_VERSION
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

- **Changed paths section:** for each one, decide whether our override needs to port the upstream change. Common patterns:
  - Pure cosmetic upstream change (whitespace, comment) → no action
  - New parameter / function signature change → port it
  - New security check upstream → definitely port it
  - For files we keep "diffable with markers" (like our `upload.php` with `// [S3] stock:` comments), update the commented-out lines to match the new stock too, so future diffs stay accurate.
- **New files section:** ask the user whether the new feature is wanted. If yes, decide whether to override (e.g., to disable) or just let it be.
- **Schema hints:** mention to the user that on the first prod request after deploy, Sendy will likely auto-migrate. They should hit `/_install.php` once on staging to trigger migration outside of normal traffic if they want to be safe.

## Smoke-test checklist (generic for any override stack)

After the audit, generate a checklist sized to what the user's overrides actually touch. Read the override paths and infer:

- If `overrides/includes/create/upload.php` exists → test image upload in both GrapesJS editor and CKEditor classic
- If `overrides/includes/filemanager/` exists → confirm filemanager UI behaves as expected (works / 410s / etc.)
- If `overrides/includes/config.php` has `HTTP_X_FORWARDED_PROTO` handling → confirm HTTPS detection via the prod proxy (no mixed-content warnings in DevTools console)
- If `overrides/locale/` has translations → spot-check a translated string on the relevant locale
- Always: login, dashboard render, create a campaign, send a test email

Present the checklist as Markdown checkboxes the user can copy.

## Stuff to never assume

- **Never invent a stock path.** Only diff a stock path that actually exists in `archives/$OLD/sendy/`. New files we override (`overrides/includes/filemanager/sendy-config.php` is shadowing a v7-new file) shouldn't trip "missing in old" — flag them as "new file we already override".
- **License downloads can fail.** If `curl` returns a non-zip response (HTML error page, empty body), tell the user — don't pretend the diff succeeded.
- **Archives might already be unzipped.** Skip the download step if `archives/$V/sendy/` already exists.

## Failure modes to mention to the user

- If `.env` is missing → say so and ask for the values
- If the new version's zip download returns a redirect to a Sendy error page → likely a stale or domain-restricted license. Tell the user to verify their license in Sendy's portal.
- If the audit finds zero changed paths AND zero new files → upgrade is probably free. Still run the smoke tests.

## Closing the loop

End the conversation by:
1. Showing the report
2. Showing the smoke-test checklist
3. Asking the user whether they want to proceed: bump `SENDY_VERSION` in `.env`, run `bash deploy.sh`, and walk through the checklist together.
