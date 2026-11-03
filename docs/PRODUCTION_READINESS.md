# Production Readiness Phases

Each phase is delivered and approved separately. A completed code phase does not by itself authorize production launch.

## P1 — Application security and performance baseline (completed)

- Global browser security headers; HSTS and CSP activate only in production.
- Per-user rate limits for report writes, OCR, review/approval, admin writes, exports, and API calls.
- `/ready` verifies database and cache without exposing dependency details; `/up` remains the liveness endpoint.
- Production boot fails closed for debug, non-HTTPS URL, empty key, non-PostgreSQL database, non-Redis cache/queue, or insecure sessions.
- Composite indexes cover approval queue, event/contest/TPS dashboard lookups, verified-result timelines, and event TPS scope.
- `.env.production.example` documents secure baseline values without secrets.

Evidence: 73 tests passed (585 assertions), frontend production build passed, dependency audits reported no known vulnerabilities.

Known follow-up: CSP still permits inline script/style for current Ziggy/Vue bootstrap compatibility. Replace with nonces or hashes after a staging CSP report-only observation period. The Papua map bundle also needs lazy loading and measured performance validation.

## P2 — Recap comparison integrity (completed)

- Store official district, regency/city, and province recap evidence separately from TPS source results.
- Compute automatic approved-TPS aggregates and immutable comparison snapshots.
- Flag participant and total discrepancies without changing source votes.
- Restrict writes to Super Admin; Coordinator access remains read-only and region-scoped, while Witness access is denied.
- Serialize recap snapshots with final approvals through a shared contest lock so a snapshot cannot contain a partially applied approval.
- Keep evidence private, validate its type and size, record its SHA-256 checksum, and audit each immutable version.

Evidence: 80 tests passed (719 assertions), including 7 P2 feature tests (134 assertions); frontend production build passed; desktop and 390 px mobile browser checks passed with no console errors.

Known follow-up: the production object-storage adapter and short-lived evidence delivery are part of P3. True PostgreSQL concurrency and target-scale load measurements remain part of P4; SQLite feature tests do not replace those checks.

## P3 — Production storage and services (completed)

- PostgreSQL TLS, Redis cache/rate-limit/queue/session, and encrypted private S3-compatible evidence are enforced by the production configuration check.
- TPS and recap evidence use the configurable private evidence disk; failed database transactions remove newly uploaded objects.
- Private evidence responses stream through authorized application endpoints; OCR copies remote streams to short-lived temporary files and cleans them on success or failure.
- Readiness covers database, cache, evidence storage, and supervised scheduler/queue heartbeats without exposing the failed dependency.
- Supervisor templates and provider-neutral release/rollback hooks are documented under `deploy/`; automatic database rollback is intentionally prohibited.

Evidence: 84 tests passed (737 assertions), including configurable S3 evidence, orphan cleanup, remote-stream OCR cleanup, heartbeat failure/recovery, and safe production-configuration cases. Frontend production build and PHP formatting passed; Composer and npm audits reported no known vulnerabilities; both deployment hooks passed Bash syntax validation.

Infrastructure acceptance still required before launch: rehearse these controls against the selected staging PostgreSQL, Redis, and S3-compatible provider. No real provider credentials or hosting target are committed to the repository.

## P4 — Performance and load validation

- Capacity profile now defines 5,000 TPS for a province event, 600 TPS for a regency/city event, and 500–1,000 simultaneous province users.
- Every TPS report accepts exactly one original photo at up to 10 MB. A private WebP preview is generated for the application while download/audit retain the original evidence; malformed, low-resolution, or unsafe-pixel-count images are rejected before storage.
- Production configuration now requires GD with WebP support because the preview pipeline depends on it.
- P4 acceptance target defines three concurrent active events, up to 1,000 concurrent users, a 40 reports/minute sustained run plus an 80 reports/minute burst, p95 targets of 1.5–10 seconds by flow, a 1% maximum HTTP error rate, and 60-second maximum queue lag.
- Guarded k6 scenarios now cover login, dashboard reads, synthetic report uploads, and concurrent approvals on disposable staging.
- Approval scope filtering now runs in SQL before 30-row pagination; regression tests enforce a bounded query count.
- Dashboard aggregates use an optional five-second, user-scoped Redis cache in production and dashboard monitoring payloads are capped.
- Map code/geometry is lazy: overview loads the 337.83 kB map chunk, districts load at zoom 8, and villages load at zoom 11 instead of one 1.40 MB eager chunk.

Current status: implementation and local verification complete; production-like PostgreSQL/Redis/S3 staging load evidence is the remaining P4 acceptance blocker. See `docs/PERFORMANCE.md`.

An isolated local staging Compose stack is available at `deploy/staging/`. It is intentionally unseeded and requires unique local-only credentials before use.

## P5 — Security completion

- Owner deferred MFA for Super Admin.
- ClamAV upload malware scanning is integrated as a separate service. Evidence is streamed to it before persistence; malware, scanner failures, and timeouts reject the upload. Production configuration requires it to be enabled and configured.
- CSP uses one nonce per production response for Ziggy and Vite script tags; `unsafe-inline` is removed from the script policy. Inline style remains a deliberate compatibility exception pending a dedicated style-attribute audit.
- Automated access review covers role boundaries on sensitive web routes; remaining operational access review is a release procedure.
- IDOR, CSRF, XSS, file upload, brute force, concurrency, and dependency security tests.

## P6 — Backup, monitoring, and disaster recovery

- Encrypted off-site PostgreSQL and object backups.
- Isolated restore drill and result/audit/evidence reconciliation.
- Queue, error, storage, database, suspicious access, and export alerting.

## P7 — Staging UAT and go-live rehearsal

- Production-like staging with synthetic data at target scale.
- Full role UAT, operational runbook rehearsal, migration rollback rehearsal, and owner sign-off.
- Production launch only after every release acceptance item has evidence.
