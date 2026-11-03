# P4 Performance Validation

## Repository baseline

- The approval queue is filtered in SQL and paginated at 30 rows. A synthetic 35-row feature test caps the first page at 30 and the request at 10 database queries.
- Dashboard result aggregation can use a user/event/contest/region-scoped Redis cache. Production TTL is five seconds; local/test default is disabled. Cache is an acceleration layer only, never a result source.
- The dashboard sends at most 25 monitoring rows; the dedicated monitoring page remains the full operational view.
- The map component is route-lazy. Its initial production chunk fell from 1,403.68 kB (328.88 kB gzip) to 337.83 kB (101.83 kB gzip). District geometry is a separate 243.72 kB chunk loaded at zoom 8; village geometry is a separate 822.70 kB chunk loaded at zoom 11.
- Browser verification confirmed neither district nor village geometry downloads at overview, district downloads at zoom 8, village downloads at zoom 11, and no console errors occur.

## Harness

[`scripts/load/README.md`](../scripts/load/README.md) documents guarded k6 scenarios for login, dashboard reads, synthetic report uploads, and concurrent approvals. Write scenarios require `TARGET_ENV=staging` plus `ENABLE_WRITE_LOAD=yes`.

A one-VU, two-second local SQLite smoke run confirmed the login and dashboard harness flows and all correctness checks. The mixed-request HTTP p95 was approximately 451 ms for the login smoke and 403 ms for the dashboard smoke. These figures are development-machine diagnostics only—not capacity evidence or a release gate.

## Approved P4 capacity profile

- Province-scoped event: up to 5,000 eligible TPS per event.
- Regency/city-scoped event: up to 600 eligible TPS per event.
- Province peak concurrency: 500–1,000 simultaneous users.
- TPS report: exactly one original evidence photo, maximum 10 MB. The original remains private evidence; an optimized preview is generated only for application viewing.

## P4 acceptance targets

- Run up to three active events at once: one 5,000-TPS province event and up to two 600-TPS regency/city events.
- At 1,000 concurrent users, use this existing-role mix: 700 Witnesses, 280 Coordinators (200 reviewing and 80 dashboard-only), and 20 Super Admins. The 500-user scenario uses the same proportions.
- Sustain 40 TPS reports per minute for 30 minutes after a 10-minute ramp. Test an 80 reports/minute burst for 10 minutes, followed by a 10-minute recovery observation. Use one photo per report; include a representative mix of ordinary compressed photos and 10 MB maximum-size fixtures.
- p95 targets: login ≤1.5 seconds; dashboard ≤2 seconds; report upload ≤10 seconds; approval ≤2 seconds.
- Maximum HTTP error rate: 1%. Maximum queue lag: 60 seconds. A correctness failure, unauthorized response, duplicate active result, missing audit row, lost evidence object, or aggregate mismatch fails the run regardless of latency.

## Staging target still required

P4 cannot issue a capacity pass/fail decision until the scenarios run against a disposable, production-like staging environment with PostgreSQL, Redis, private S3-compatible storage, and the selected PHP worker topology. Local SQLite results remain functional smoke checks only.

## Staging result sheet

Record release identifier, timestamp, topology, PHP-FPM workers, PostgreSQL/Redis/object-storage sizing, fixture counts, VUs, duration, throughput, p50/p95/p99, HTTP errors, queue lag, CPU, memory, database connections/locks, slow queries, and object-storage latency. Reconcile submissions, approvals, active verified results, audit rows, and evidence objects after every write test.
