# Controlled installation — Phase3A RC1

This ZIP is UNSIGNED and is not an /upgrade package. Do not disable signature verification at https://pkdkb.epejabat.net/upgrade. Production installation remains user-managed. First validate in an isolated staging copy with synthetic data; real Safari/Chrome testing is pending.

1. Back up the database and affected files, including database/eforms-manifest.json. Extract the ZIP into the existing project root. It adds a protected phase3a_stage folder and read-only phase3a-preflight.php; payload extraction alone does NOT install the module. Log in as Super Admin and open https://pkdkb.epejabat.net/phase3a-preflight.php. Require PASS_BASELINE_ONLY; if BLOCKED, do not copy payload, rerun migrations or overwrite mismatched files. Confirm staging folder is HTTP-denied before continuing.
2. After staging acceptance and baseline PASS, copy ONLY phase3a_stage/payload contents into the same root, preserving relative paths. The only existing file replaced is database/eforms-manifest.json. Do not overwrite config, bootstrap, index.php, Core, Training or existing e-Forms assets. Use normal hosting-readable asset permissions; never 777. Open https://pkdkb.epejabat.net/eforms?visual_migration=1, review only pending 220/221, confirm backup and enter the existing 12-digit Installation Key. The existing engine performs backup and migrations. Stop on any error; do not manually mark ledger APPLIED.
3. Open https://pkdkb.epejabat.net/public-program.php?admin=1. Configure a closed program, age limits and dates, assign scoped permissions if needed, then complete the controlled checklist. Open public registration only after acceptance. Remove phase3a-preflight.php and phase3a_stage once backups/reports are retained privately. No real email or test child record without approval.

No changes are deployed by the assistant. Preflight is for initial install only: new-file absence deliberately fails after installation.

## Rollback
- Before migrations: remove only new files listed in FILE_MANIFEST.json and restore the exact backed-up eforms-manifest.json. Never remove a file that existed before installation.
- After successful migrations: close public entry/access and restore files/manifest. Preserve all new tables and the applied migration ledger; do not drop children's registrations. Reconcile a forward recovery release before reinstallation (initial preflight should refuse existing tables).
- On partial migration: stop writes and do not repeat the DDL or delete the ledger. Use the full pre-upgrade database backup only with explicit recovery approval and a plan for any later production changes; restoring it may discard later data. No automatic SQL rollback supplied or claimed.
- Config/SMTP/accounts/passwords are never part of payload or rollback.

## Consolidated post-install checklist (approval required for writes/email)
- Verify 220/221 checksums APPLIED; existing 200/201/210/211/212 unchanged.
- Confirm admin login and old e-Forms draft/template remain intact.
- At 320/375/390/430/768/1024/1440 widths, test on actual Safari iPhone and Chrome: add/remove children, submit, status fragment link, admin search/filter, CSV.
- Synthetic closed/open dates, one/two children, duplicate, full quota, FIFO promotion and invalid/expired status access.
- Verify public QR resolves to program only, no identification/token/child info.
- Verify unauthorised and cross-organisation admin/export rejection; staging/internal files HTTP-denied.
- Queue counts only; send one specifically approved test email, never flush the queue.
- Keep clinical/payment/place statuses separate; no implied subsidy or place confirmation.
