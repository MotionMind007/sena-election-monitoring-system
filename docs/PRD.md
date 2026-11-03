# PRD — ElectCount V1

## Problem
An internal party organization needs reliable, traceable, role-restricted reporting of TPS vote tallies and progressive geographic aggregation for election events. Existing manual aggregation risks duplicate submissions, transcription errors, delayed verification, and opaque corrections.

## Goals
- Collect TPS contest results with original photographic evidence.
- Let witness or coordinator submit within assigned scope.
- Require human review and exclusive Super Admin final approval.
- Aggregate **only active verified TPS results** to desa/kelurahan → kecamatan/distrik → kabupaten/kota → provinsi, stopping at event scope.
- Monitor both submitted and verified coverage separately, per contest.
- Provide readable, mobile-responsive internal dashboards with drilldown and auditability.
- Expose reusable backend operations for a future Flutter client.

## Non-goals
Quick count, official KPU certification, public portal, voter-level data, campaign analytics, mobile app delivery, offline operation, multi-party tenants, real-time GPS tracking.

## Users
- **Super Admin:** full configuration and visibility; sole final approval authority.
- **Coordinator:** regional oversight, alternate input, review and recommendation; never final approval.
- **Witness:** assigned TPS input and report status; read-only results for assigned TPS.

## Functional requirements
| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-01 | Authentication and three roles | unauthenticated users redirected; unauthorized operations denied server-side |
| FR-02 | Event management | Super Admin can create/configure events and choose province or kabupaten/kota scope |
| FR-03 | Contest management | event has separate contests, participants, ballot/form schemas |
| FR-04 | Master geography/TPS | parent-child geography, event TPS assignment, import validation and audit |
| FR-05 | User assignment | assign coordinator region and witness TPS; scope enforced on every API |
| FR-06 | Manual TPS reporting | numeric entries, required totals, evidence; draft/submit; idempotent retry |
| FR-07 | OCR assistance | user confirms extracted values; preserve OCR confidence and source |
| FR-08 | Coordinator review | recommend, request correction, flag conflict; never final approve |
| FR-09 | Super Admin approval | final approve/reject/revision; no self-approval; audit logged |
| FR-10 | Correction/versioning | prior approved versions immutable; one active verified result per key |
| FR-11 | Hierarchical real count | aggregate only active verified results; correct contest/dapil grouping |
| FR-12 | Dashboard drilldown | event/contest/region filter updates KPI, charts, map, tables, TPS list |
| FR-13 | Monitoring | separate submitted/verified counts; status and anomaly filters |
| FR-14 | Alerts/activity | pending approval, duplicate, mismatch, missing reports; role-scoped |
| FR-15 | Export | Super Admin export includes event, contest, region, timestamp, status |
| FR-16 | Audit | critical actions recorded with actor, timestamp, before/after reference |

## Core business definitions
- **TPS total:** eligible TPS for the selected event + contest + authorized region.
- **TPS submitted:** unique eligible TPS with ≥1 valid submitted report (regardless of approval), counted once.
- **TPS verified:** unique eligible TPS with an active verified result for selected contest.
- **Real Count:** sum of active verified result items; percentages use verified valid votes as denominator.
- **Not-yet-verified:** never included in verified vote totals.

## UX requirements
- Dark Glassmorphism per `docs/UI_UX.md` and `ui/SCREEN_SPECS.md`.
- Single dashboard drilldown; click geographic child to navigate deeper; breadcrumb to return.
- Super Admin desktop command center; Coordinator regional dashboard; Witness mobile-first minimal entry UI.
- Evidence and numeric values side-by-side for approval on desktop; stacked on mobile.
- Display explicit “Internal, sementara, bukan hasil resmi KPU” context on results.

## Quality requirements
- Enforce authorization in backend for every route and query.
- Reliable concurrent approvals and aggregate updates; transactional consistency.
- Never silently lose submitted reports; retry safe and observable.
- Accessible contrast, keyboard navigation, responsive tables and input controls.
- Operational readiness validated against measured target load, not invented TPS counts.

## Release acceptance
All mandatory FRs implemented or explicitly deferred by owner; integration and security tests pass; backups/restore verified; real count reconciles against TPS-level source; UI approved at desktop and mobile widths.
