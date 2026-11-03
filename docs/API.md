# API Contract — `/api/v1`

**Status:** conceptual contract, finalize request schemas against approved election form rules before implementation.

## Principles
- JSON; HTTPS only; versioned routes; no public endpoints for private results.
- Session authentication for web; future mobile tokens via Sanctum (V2).
- All read and write endpoints require role + event + region/TPS policy checks.
- Pagination for collections, bounded filters, consistent validation errors.
- Use ISO-8601 timestamps, UUID identifiers, integer vote counts.
- Mutations that may be retried accept `Idempotency-Key` header.

## Endpoint inventory
| Method | Route | Role |
|---|---|---|
| GET | `/events` | authenticated, scoped |
| POST | `/events` | super_admin |
| GET | `/events/{event}/contests` | authenticated, scoped |
| GET | `/events/{event}/regions/{region}/children` | authenticated, scoped |
| GET | `/events/{event}/dashboard?contest_id=&region_id=` | authenticated, scoped |
| GET | `/events/{event}/tps?contest_id=&region_id=&status=` | authenticated, scoped |
| GET | `/events/{event}/tps/{eventTps}` | authenticated, scoped |
| POST | `/events/{event}/submissions` | witness/coordinator in scope; optional admin policy |
| GET | `/events/{event}/submissions/{submission}` | scoped |
| POST | `/events/{event}/submissions/{submission}/evidence` | submitter, valid status |
| POST | `/events/{event}/submissions/{submission}/recommendation` | coordinator not submitter |
| POST | `/events/{event}/submissions/{submission}/approval` | super_admin, not submitter |
| GET | `/events/{event}/approval-queue` | super_admin |
| GET | `/events/{event}/audit` | super_admin |
| POST | `/events/{event}/exports` | super_admin |

## Sample dashboard response (synthetic)
```json
{
  "event_id": "event-uuid",
  "contest_id": "contest-uuid",
  "region_id": "region-uuid",
  "data_as_of": "2029-01-01T10:00:00+09:00",
  "total_tps": 100,
  "submitted_tps": 80,
  "verified_tps": 60,
  "valid_votes_verified": 12000,
  "invalid_votes_verified": 100,
  "participants": [
    {"id": "participant-1", "votes": 7000},
    {"id": "participant-2", "votes": 5000}
  ]
}
```

## Approval request (conceptual)
```json
{"decision": "approve", "reason": "Evidence and figures matched", "expected_submission_version": 2}
```

## Errors
- `401` unauthenticated; `403` unauthorized; `404` unknown/out-of-scope resource where appropriate.
- `409` stale version, conflicting concurrent approval, duplicate key.
- `422` invalid form, missing evidence, inconsistent counts.
- Response: `{ "error": { "code": "...", "message": "...", "details": {} } }`.

## Contract tests
Scope checks, idempotent retry, no self-approval, optimistic concurrency, no unverified votes in result endpoint, event/contest isolation.
