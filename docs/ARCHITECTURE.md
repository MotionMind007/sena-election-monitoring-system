# Architecture — Laravel Modular Monolith

## Runtime
```text
Browser (Vue 3 + Inertia)      Future Flutter (NOT V1)
             |                     |
             +-- HTTPS /api/v1 ----+
                       |
           Laravel (single codebase)
 Auth/Policy | Events | TPS Reports | Reviews | Approval | Aggregation | Audit
                       |
       PostgreSQL / Redis queue / private object storage
```

## Modules (logical, not separate services)
- `Identity`: auth, roles, event/region scopes.
- `Elections`: events, contests, participants, event-specific geography.
- `Assignments`: coordinator and witness assignments.
- `Reporting`: submission, evidence, validation, OCR review.
- `Verification`: coordinator recommendations, Super Admin approval, corrections.
- `Results`: verified versions, contest-aware aggregates, dashboard read models.
- `Audit`: immutable action records, export tracking.

## Request flow
Controller (HTTP validation) → Policy (role + scope) → Application action/service → DB transaction → domain event/outbox if necessary → queued aggregate/notification job → response. Controllers must remain thin.

## Frontend
- Vue 3 + Inertia pages for responsive web.
- Shared UI components: GlassCard, StatCard, RegionBreadcrumb, StatusBadge, EvidenceViewer, ResultChart, TPSDataTable.
- No SQL, approval logic, or vote arithmetic trusted from frontend.
- REST `/api/v1` supports core operations for future mobile. Inertia web pages may use Laravel routes, but invoke the **same application services** as API controllers; no duplicated business rules.

## Aggregation model
- TPS result is the sole vote source of truth.
- Query by `event_id`, `contest_id`, and a valid descendant region set.
- Use one active verified result per TPS+contest.
- Cache derived summaries; invalidate/update after approval and correction.
- Prefer straightforward SQL aggregates first. Add materialized aggregate tables only when profiling shows need.
- Legislative contest calculation requires approved ballot rules and separate party/candidate dimensions; do not implement guessed formulas.

## Concurrency and failure
- Idempotency key per submission request.
- Approval must lock the relevant result key and verify report status and no-self-approval in transaction.
- Database uniqueness ensures at most one active verified result for key.
- Queue jobs must be retryable; cache never source of truth.
- Track data freshness and recompute if aggregates stale.

## Future mobile
Use stable, versioned API schemas; no dependency on browser session for business logic. Future mobile token authentication via Sanctum, not implemented prematurely.

## Anti-overengineering
No DDD framework, event sourcing, CQRS, service mesh, microservices, Kafka, GraphQL, WebSockets or Kubernetes without measured necessity and owner approval.
