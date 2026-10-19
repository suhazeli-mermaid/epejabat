# Phase3A RC1 — one ZIP / browser checker

IMPORTANT: This bundle is for checking and controlled preparation. Automatic installation is BLOCKED by absent publisher signature. A missing Backup::create on hosting also blocks any migration. Do not interpret PASS file rows as permission to install.

1. Back up existing files/database. In a staging copy first, extract this ONE ZIP into the project root. It adds phase3a-checker.php and a protected phase3a_stage folder; it does not copy the application payload over existing files. Preserve any pre-existing folder with the same name before extraction.
2. Log in as Super Admin and open https://pkdkb.epejabat.net/phase3a-checker.php. Review PASS/WARNING/BLOCKED, optionally verify the existing 12-digit Installation Key, and download the JSON. No manual SHA-256 is required. Check that /phase3a_stage/Checker.php is denied over HTTP.
3. STOP if critical checks are BLOCKED. This unsigned package has no Install button and must not be uploaded to https://pkdkb.epejabat.net/upgrade as a signed release. Resolve the original signing/allowlist path and backup compatibility first. A separately authorised manual installation remains conditional on baseline, tested backup and staging acceptance; no payload copying or migration is requested at this stage.
4. After a separately approved supported installation, open https://pkdkb.epejabat.net/phase3a-checker.php?mode=after and complete SMOKE_TEST.md. Retain reports privately, then remove the checker and staging folder. Do not repeat applied migrations.

## Rollback
Checker-only extraction: remove phase3a-checker.php and the new phase3a_stage folder, or restore a previously backed-up folder of the same name. Existing application files/database remain unchanged. Key verification security audit entries are retained.
If Phase3A was installed via a separately approved path: restore affected files from the pre-install backup, preserving all participant tables and migration ledger. Do not drop registrations or auto-restore the database. A database restore requires explicit approval and reconciliation of later writes. Existing RC1 rollback notes are retained under reports/prior-RC1/.
