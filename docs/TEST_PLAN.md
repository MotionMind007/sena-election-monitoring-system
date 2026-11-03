# Test Plan & Quality Gates

## Automated unit
- Geography ancestry and event boundary logic.
- Contest-aware vote sum, totals, and zero/invalid values.
- Submitted vs verified unique TPS count.
- Status transitions and revision rules.

## Feature/integration
1. Witness submits assigned TPS; cannot submit unassigned TPS.
2. Coordinator submits within region; cannot submit outside region.
3. Coordinator recommends someone else's report; cannot recommend own.
4. Only Super Admin approves; Super Admin cannot approve own report.
5. Submitted-but-unapproved votes excluded from dashboard totals.
6. Duplicate submissions remain separate; counted as one submitted TPS.
7. Same idempotency key retry returns same submission.
8. Concurrent approvals cannot create two active verified results.
9. Corrected result replaces active verified result, old version preserved, totals reconciled.
10. Region drilldown totals equal sum of descendant verified TPS for same contest.
11. Multiple contests from same TPS do not mix.
12. Legislative counting follows approved ballot schema, never double counts.
13. Search, exports, evidence URLs, and activity respect scope.
14. Upload validation blocks malformed files and unauthorized access.
15. Dashboard filters and browser Back work without full reload.

## UI tests
- 1440×900 desktop and 390×844 mobile; no page overflow.
- Keyboard focus, screen reader labels, color contrast.
- Loading, empty, errors, unauthorized, stale data.
- Role-specific navigation; no approval action visible to coordinator/witness.

## Operations
- Queue retry and cache invalidation.
- Database backup and restoration drill.
- Basic load tests using approved TPS/concurrency assumptions; publish measured p95 latency and errors.

## Gate
Agent reports exact test commands, pass/fail counts, untested areas, migration risk, and screenshots for UI work. Do not claim production readiness from unit tests alone.
