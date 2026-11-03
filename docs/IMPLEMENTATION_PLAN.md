# Implementation Plan — Strict Phase Gates

**Rule:** do not automatically continue. At the end of each phase deliver evidence and wait for owner approval.

## Phase 0 — Documentation audit (NO CODE)
- Read all documents; list contradictions and open decisions.
- Validate first-event form requirements, TPS data source, estimated scale, and hosting constraints.
- Present implementation plan and acceptance checklist. **STOP**.

## Phase 1 — Project skeleton and identity
- Laravel + Vue/Inertia + Tailwind setup; PostgreSQL; Redis connection; environment example.
- Login/logout, exactly 3 roles, policies, tests, base glass design tokens.
- No TPS reports or dashboards yet. **STOP**.

## Phase 2 — Event/geography/assignments
- Event/contest setup, region hierarchy, TPS import, assignments, scoped queries.
- Import preview, validation, duplicate detection; event/TPS snapshots.
- Test event/region isolation. **STOP**.

## Phase 3 — TPS reporting (manual first)
- Witness/coordinator reporting, evidence storage, validation, idempotency, versions.
- Mobile-friendly form; tests for duplicate and scope. **STOP**.

## Phase 4 — Review and final approval
- Coordinator recommendations, Super Admin approval, no self-approval, conflict resolution, correction flow, audit.
- Concurrency tests and DB uniqueness. **STOP**.

## Phase 5 — Verified real-count and drilldown
- Contest-aware aggregation, submitted/verified KPI, region drilldown, monitoring TPS, internal results dashboard.
- UI follows approved mockup; use only synthetic fixtures in development. **STOP**.

## Phase 6 — OCR assistance (only after owner confirms engine)
- Evidence OCR background processing, suggested values, confidence, mandatory human confirmation, failure fallback.
- OCR must never auto-submit or auto-approve. **STOP**.

## Phase 7 — Export, alerts, operational hardening
- Admin export, relevant alerts, backups, restore test, load testing, access review, deployment runbook.
- Confirm security and acceptance checklist. **STOP**.

## Deferred V2+
Flutter mobile app; Quick Count; offline sync; public-facing portal. None is an implicit V1 task.

## Phase report template
1. Scope delivered and PRD IDs.
2. Files changed and migrations.
3. Test commands and real results.
4. Screenshots for UI changes.
5. Known limitations/open decisions.
6. Request explicit approval for next phase.
