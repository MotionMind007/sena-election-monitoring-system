# Staging load harness

These k6 scripts are intentionally gated by `TARGET_ENV=staging`. Write scenarios also require `ENABLE_WRITE_LOAD=yes` and disposable synthetic fixtures. Never point them at production.

Required common values: `BASE_URL`, `TARGET_ENV=staging`, `EMAIL`, and `PASSWORD`. Optional owner-approved gates are `P95_MS` and `MAX_ERROR_RATE`; without them the scripts measure but do not claim pass/fail capacity.

- `k6 run scripts/load/login.k6.js`
- `k6 run scripts/load/dashboard.k6.js` with optional `EVENT_ID`, `CONTEST_ID`, and `REGION_ID`.
- `k6 run scripts/load/report-upload.k6.js` with `ENABLE_WRITE_LOAD=yes`, event/contest/TPS IDs, and comma-separated `PARTICIPANT_IDS`. Proxy users also supply `SOURCE_NAME` and `SOURCE_METHOD`.
- `k6 run scripts/load/approval.k6.js` with `ENABLE_WRITE_LOAD=yes` and comma-separated pending `SUBMISSION_IDS`. Reusing one ID intentionally exercises concurrent decision locking; verify the database still has one active result afterward.

Record test date, release hash, topology, PostgreSQL/Redis/object-storage sizes, fixture scale, VUs, duration, p50/p95/p99, throughput, errors, queue lag, CPU, memory, and database saturation in the P4 result sheet.

## Approved fixture profile (P4)

- Province event: 5,000 eligible TPS; regency/city event: 600 eligible TPS.
- Run up to three active events. Test 500 then 1,000 concurrent users. At 1,000 VUs, provision synthetic accounts in a 700 Witness / 280 Coordinator (200 review, 80 dashboard-only) / 20 Super Admin split; use the same proportions at 500 VUs.
- Ramp for 10 minutes, sustain 40 report uploads per minute for 30 minutes, burst to 80 reports/minute for 10 minutes, then observe recovery for 10 minutes.
- One private photo per TPS report, maximum 10 MB original. Use only synthetic files on disposable staging; the standard harness keeps a small synthetic image to avoid copying real evidence.
- Use gates of `P95_MS=1500` for login, `P95_MS=2000` for dashboard/approval, and `P95_MS=10000` for report upload. Set `MAX_ERROR_RATE=0.01`; reject a run if queue lag exceeds 60 seconds or reconciliation detects an integrity failure.
