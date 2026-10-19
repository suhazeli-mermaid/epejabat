# Upgrade Manager Policy Transition — report

## Outcome
READY for controlled hosting activation review. No production deployment or production database write was performed by this development batch. The policy has been activated and rolled back in an isolated fixture; the resulting Upgrade Manager staged, checked, backed up and installed the exact eight-file Phase 3A payload and migrations 220/221 successfully without an additional account password. Existing 12-digit key verification remains mandatory.

Production activation remains unapproved. The Phase 3A ZIP is compatible with the tested post-transition Core 1.2.1 and is deliberately rejected by the current publisher-only policy. Do not upload the hosting transition ZIP to the ordinary Upgrade Manager.

## Pinned inputs and scope
Authenticated production baseline captured 2026-10-15T04:06:41Z, previously reconciled checker and production-matched Backup.php were reused. No full audit or Phase 3A rebuild. The prior 63 tests and 49 recovery checks were retained without repetition; new tests exercise the changed policy and actual install path. Only five installed Core files change: UpgradePolicy.php (new), UpgradePackage.php, LiveUpgradeManager.php, UpgradeWeb.php, ProductionBaseline.php. Exact before/after SHA256 hashes are in transition-manifest.json. Backup.php and InstallationKey.php remain unchanged.

Phase 3A retains its eight original source files, no Core payload. It declares eforms-v1 migration adapter, required ledger checksums and exact existing eForms manifest baseline. Module release version is 0.1.0; original seed briefly writes 0.1.0-rc1 within migration, then existing Versions::record records the final 0.1.0 on successful installation. Original RC1 bytes are unchanged.

## Owner-approved trust model
Default remains publisher signature mode until hosting activation. A logged-in active Super Admin with valid CSRF and Installation Key can issue a 10-minute one-use hosting approval. It is HMAC-bound using the existing application secret to the app root, exact transition manifest, actor, key epoch and account credential revision. The raw key and its password hash are never copied into the grant or logs. The hosting CLI operation is fixed to five paths, not a generic ZIP installer. The policy marker is written only after backup and verified file replacement. No new publisher or signing key is generated and existing trusted public keys are untouched.

After activation, epejabat-owner-upgrade-v1 packages are explicitly authorized by the Super Admin/key workflow. ZIP SHA256 and payload hashes establish integrity, **not publisher authenticity**. This is the system owner's deliberate policy transition. Ordinary uploaded ZIPs cannot replace UpgradePolicy, UpgradePackage, LiveUpgradeManager, UpgradeWeb, InstallationKey or trust/key configuration. Separate reviewed hosting maintenance is required for those components. Existing signed release catalogue/retrieval remains signed and is not automatically converted into trusted unsigned remote delivery.

## Guards and recovery
Manifest structure, archive limits, unsafe paths/symlinks, exact Phase 3A allowlist, baseline/new-file absence, version progression, ledger checksums and migration declarations remain enforced. Existing Core/Training adapters remain; eforms-v1 uses the existing EForms migration engine. Staged packages survive expired authorization, but installation is denied until key re-verification. Filesystem/advisory database locks serialize installation. A failure after modifications leaves recovery locks. File backups and database snapshots are created before replacement.

The transition has a separately authorized file rollback, with exact before/after hashes and no rollback over later successful deployments. It records activation and rollback in upgrade history/audit. Database rollback is not advertised as automatic. SQL backup restoration was actually executed into a different isolated database; table counts, complete values and CREATE TABLE hashes matched.

## Validation
74 targeted assertions/lints PASS, 0 FAIL, 0 BLOCKED. PHP 8.2.32, MariaDB 10.11.18, Apache 2.4.68. Actual cURL HTTP protection probes ran against Apache; sensitive maintenance/payload endpoints returned 403 for GET and HEAD. Tests include old-policy unsigned rejection; owner-mode acceptance; protected security-file rejection; payload tamper; missing/expired authorization; grant tamper/action substitution/expiry; CSRF; non-admin; baseline drift; incomplete backup prevention; activation and rollback; restore fidelity; dependency-aware rollback refusal; Phase 3A full install; migration application; preserved Core/Training versions; install replay rejection; no recovery lock after success.

Local installation result is VERIFIED_LOCAL_INSTALL, not production acceptance. Production activation/migrations, MySQL 8 restore execution, cPanel/LiteSpeed runtime and browser login/form end-to-end were NOT TESTED. The reduced synthetic fixture's optional post-install baseline capture reports BLOCKED because full baseline context is absent; payload verification, migration verification and installation passed. Live hosting preflight rechecks all required conditions and stops on mismatch. Browser operation and production smoke testing remain for an approved subsequent stage.

Existing Backup Engine refuses unsupported views, triggers, routines, events, spatial columns and incomplete metadata visibility. This restriction was preserved, including a tested BLOCKED path before file installation. No blind ALL PRIVILEGES requirement was added. No emails were sent and no real staff data was used.

## Deployment decision
Two purpose-specific ZIPs are intentional: one hosting policy transition, then one normal Phase 3A upload. They must not be combined into a package that changes its own verifier. After the one-time transition, ordinary compatible upgrades use https://pkdkb.epejabat.net/upgrade with the Installation Key and automatic checks/backup/install/verify.
