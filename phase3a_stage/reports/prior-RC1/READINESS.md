# Phase 3A — Public Registration & Participant Management

Release: public_programs 0.1.0-rc1. Status: PARTIAL — development candidate, not production verified.
Date: 15 October 2026. No production access, deployment, migration or email delivery performed.

## Implemented
- Reusable organisation-owned programs: event date/location, opening/closing in Malaysia time, quota, min/max age at event date, manual open/close and waiting-list switch.
- Anonymous registration: guardian name/email/phone, one to ten children per request, acknowledgement, honeypot, minimum fill time, session CSRF and database-backed IP rate limit. No staff account, OTP or mailbox verification.
- Unique participant code per child; encrypted identity, guardian contacts and socioeconomic declaration. Program-scoped keyed HMAC supports duplicate detection without storing a plain identification search index. Identity numbers are never included in QR, URL, audit metadata, notification body or CSV export.
- Program row lock serialises registrations, quota changes and FIFO promotions. One child consumes one capacity allocation; a family can split between received and waiting. Waiting-disabled over-capacity requests reject the whole request. Existing waiting applicants retain priority after quota increase.
- Registration RECEIVED/WAITLIST separate from clinical NOT_ASSESSED, payment NOT_ASSESSED and place UNCONFIRMED. Promotion does not confirm a place.
- Existing e-Forms Schema validation and published version binding; existing Smart Form Studio can author extra fields without PDF. No guardian submission is forged under a staff account.
- Initial receipt and promotion notifications queued to exact guardian email in existing encrypted notification queue. No automatic queue flush. No preferred staff-email substitution.
- Random 256-bit access token, hashed in database, 30-day expiry, only status fields of the bound household. Token carried in URL fragment then POSTed; no token query string, no-referrer and no-store responses. Public link possession is not verified identity or a clinical consent mechanism.
- Admin list/search/status filter, FIFO promotion, separate permission for CSV export; CSV formula-prefix protection. Existing scoped roles UI may assign new public_programs permissions. Super Admin has access; no existing staff role is automatically granted privileges.
- Public QR reuses unchanged Training QR class, contains public program URL only. No external QR service.

## Integration and schema
New module directory app/Modules/PublicPrograms; new entry public-program.php using existing bootstrap, authentication, maintenance and installer locks. Existing Core/Training/Registration/e-Forms PHP/JS files are not replaced.
Only existing replacement: database/eforms-manifest.json, pinned to Phase2C RC1 + migration-page fix + Studio startup fix + iPhone stream fix. Missing or differing baseline stops preflight.
Migrations 220_public_programs.sql and 221_public_program_seed.php run under existing e-Forms migration engine and ledger, not a new installer. Existing migration page requires Super Admin, CSRF, Installation Key and automatic database backup before applying. Schema conflicts/started migrations stop retries.
New tables: public_programs (organisation and eforms version FKs), public_guardians (program FK), public_participants (program and guardian FKs), public_program_attempts. No changes to existing table definitions. Application/Core/Training versions are unchanged; module_version.public_programs records candidate version.
MySQL DDL is not transactionally reversible. No automatic database rollback is claimed.

## Validation and evidence
See TEST_RESULTS.json and DOM_TEST_RESULTS.json. PHP 8.2.32 and MariaDB 10.11 isolated synthetic database. Eight concurrent independent PHP processes prove one received/seven waiting for quota one. Queue only, no SMTP dispatch. PHP lint and JavaScript syntax passed.
DOM tests use jsdom, not Chrome or Safari. Real Chrome, Safari/WebKit/iPhone, viewport visual tests, public HTTP entry integration and hosting access-denial tests: NOT TESTED. MySQL 8 specifically: NOT TESTED.
Previous Phase2C tests were not relabelled as new tests or repeated. Targeted e-Forms schema/version reuse was exercised; full Training regression was not repeated because no Training files changed.

## Actual limitations / remaining gates
1. Production current source hashes have NOT been captured in this request. Read-only preflight compares pinned development dependencies and exact latest local manifest; PASS means baseline only, not operational acceptance. If mismatched, retain existing production files and obtain only mismatch details/current affected source through authorised browser baseline workflow.
2. This is an unsigned controlled manual deployment candidate. It is NOT accepted by /upgrade and does not replace publisher trust. No new signing service, bridge or installer.
3. Real desktop/mobile browser acceptance is pending; do not expose the public service until these tests pass. The browser preflight itself has lint validation but has not run on hosting.
4. Extra e-Forms fields currently support text, textarea, number, date/time, email/tel, dropdown/radio, checkbox and section, without conditional logic or staff autofill. Unsupported fields fail explicitly at program save. Tables/file/staff controls are not exposed to anonymous users. Core guardian/child fields are fixed integrity fields; no separate PDF package is required.
5. Date, age policy and bound form version lock after first registration; create a new annual program to change them. Quota/open-close/location/title may be edited with optimistic revision protection. Schedule changes and clinical decisions belong to 3B/3C.
6. Registry currently lists child ID/name/age/category/status; no full guardian contact/detail editor or cancellation/reinstatement UI. No clinical, payment, receipt, scheduling or certificate features in 3A.
7. Access tokens expire after 30 days; no public recovery/resend UI or token rotation interface in this release. No child identities/clinical data are returned through public status access. Staff must handle expiry via a future authorised recovery action; never bypass access checks.
8. Queue delivery requires the existing approved worker schedule; a QUEUED notification does not prove inbox delivery. Guardian email is intentionally unverified. Contact typos and abuse remain operational risks; rate limiting is 12 POST attempts per IP/15-minute bucket, shared across registration/status.
9. Duplicate identification remains reserved after cancellation, preventing silent re-registration. Government identity authenticity is not verified; the 12-digit format is checked only.
10. Retention/deletion policy, child-data privacy notice, authorised roles and contact for public support need operational sign-off. No clinical eligibility thresholds or official subsidy rules are inferred.

## Status register
Phase2C: existing installed state reported by user, untouched except planned additive manifest integration.
Phase3A: code implemented as RC1, targeted service/DOM tests PASS; browser and production compatibility acceptance pending.
Phase3B/3C: NOT IMPLEMENTED in this release. Production baseline: NOT LOCKED.
