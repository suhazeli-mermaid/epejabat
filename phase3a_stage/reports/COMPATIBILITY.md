# Phase3A RC1 — Integrated Deployment Checker

Status: PARTIAL. Checker implemented; AUTOMATIC INSTALLATION BLOCKED.
No production deployment, migration or email processing performed.

## Scope preserved
The complete Phase3A payload is byte-identical to the prior RC1. Its 45 targeted service/DOM tests are retained as prior evidence, not rerun. New code consists of phase3a-checker.php and the protected stage Checker.php. No new installer, publisher, signing key or trust changes.

## Concrete blockers found
1. The artifact is unsigned. Existing UpgradePackage::verify requires manifest.sig and a publisher already present in trustPolicy. Hash agreement and a correct Installation Key cannot replace that check. A new public_programs module and file paths may also be absent from the existing upgrade allowlist; checker reports each one without changing policy.
2. The available dependency app/Core/Backup.php exposes verify() only, while the existing e-Forms migration page calls Backup::create(). This is a demonstrated local interface mismatch, not a claim about current production. Checker uses method_exists on hosting to establish the actual state. If missing, do not run the migration page: it cannot complete the mandatory backup. No fallback SQL import is supplied.
3. Production hashes and installed versions remain unverified. The checker reports observed versions plus exact pinned file and migration checks, not user-reported versions as PASS evidence.

## What the checker does
Requires existing authenticated active Super Admin. It checks pinned Phase2C/hotfix hashes, staged payload integrity, target file absence, symlink/path safety, PHP/extensions, writable parents, installer/maintenance locks, database version, migration status/checksums, new table absence, and existing backup/key/trust interfaces. After-install mode checks target hashes, applied ledger, expected column names and module version. It reads recent e-Forms/public-program release history. A JSON download contains no credentials, key hashes, signing material or participant records.
Key verification is an explicit CSRF-protected POST using existing InstallationKey rate limiting and auditing. Checking files/database is read-only; key verification writes only the existing security attempt/audit/session records. No backup, installation, file replacement, migration or install-history write is implemented. Unsupported install/rollback actions are rejected.
The UI presents PASS/WARNING/BLOCKED. Overall automatic install stays BLOCKED for this unsigned bundle. There is no misleading enabled Install button.

## Backup / recovery truthfulness
Writable storage is not proof of a complete database backup. Restore testing and private-storage HTTP denial remain WARNING/unverified. Checker does not infer privilege sufficiency from ALL PRIVILEGES and never asks for database grants. Existing backup implementation, if present, must complete its own checks on an authorised installation path. Existing history is displayed, not invented or labelled installed by the checker.

## Test boundary
18 targeted checker tests PASS: baseline, mismatch, new-file conflicts, payload tampering, symlinks, path traversal, before/after modes, duplicate-install prevention, anonymous access rejection, wrong/leading-zero key, CSRF, unsigned package refusal, missing backup interface, no secret output, no migration mutation and no fabricated smoke results.
PHP lint passed. No prior 45 tests rerun. Actual browser UI and production execution NOT TESTED; no claim of Safari/iPhone/Chrome acceptance. The checker is not an active end-to-end smoke runner. Its downloadable report marks the write/browser/email workflows NOT TESTED; the controlled checklist is supplied separately.

## Supported next path
Use this bundle for compatibility diagnosis in an isolated copy, then through authorised browser checks on hosting if desired. Do not install Phase3A while any critical compatibility/backup check is blocked. Automated upgrade requires the original trusted signing path and allowed manifest, with policy changes separately approved if required. If unavailable, the only alternative is the already documented user-managed manual payload deployment, but ONLY after baseline, backup interface, backup evidence and staging browser acceptance are resolved. This is not an automatic install fallback and must not bypass the signature verifier. No additional snapshot ZIP or manual hash entry is requested: download one checker JSON to identify exact conflicts.
