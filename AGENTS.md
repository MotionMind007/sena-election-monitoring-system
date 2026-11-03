# AGENTS.md — Binding Instructions for AI Coding Agents

## Mission
Build ElectCount V1 **exactly** to the approved documentation. Prefer minimal correct, secure, maintainable code. The human owner controls scope and phase transitions.

## Instruction priority
1. Latest explicit human instruction.
2. This `AGENTS.md` and `docs/DECISIONS.md`.
3. `docs/PRD.md`, `docs/SECURITY.md`, `docs/WORKFLOWS.md`.
4. Supporting design and implementation documents.
If documents conflict, **stop and ask**; never silently choose.

## Hard scope boundaries
**Build:** one-party, multi-event internal real-count system, geographic drilldown, witness/coordinator submissions, human review, Super Admin final approval, immutable evidence/version history, reports, and role-scoped dashboards.

**Do not build in V1:** quick count, sampling, public results portal, public API, mobile apps, offline sync, political campaign tools, voter profiling, predictive targeting, multi-tenant SaaS, chat, GPS tracking, blockchain, microservices, event sourcing, CQRS, Kubernetes, AI auto-approval, automated election-result prediction.

## Locked stack
- Laravel modular monolith; PHP version compatible with chosen supported Laravel release.
- Vue 3 + Inertia.js + Tailwind CSS in the **same Laravel repository**.
- PostgreSQL, Redis for queue/cache, private S3-compatible object storage.
- Laravel Sanctum: secure web session and future mobile token support; do not prematurely build mobile token flows.
- REST `/api/v1` contracts for reusable operations; business logic belongs to backend services/actions, not controllers or Vue.
- Flutter reserved for V2; no Flutter project in V1.
- No unnecessary repository splitting, services, packages, abstractions, or infrastructure.

## Non-negotiable business invariants
1. A result is keyed by `(event, contest, event_tps)`.
2. Multiple submissions may exist; at most **one active verified result** for each key.
3. Only `super_admin` may approve/reject as final authority, and **approver must not be the report submitter**.
4. Coordinators can review/recommend, **never final-approve**. A coordinator cannot independently recommend a submission they created.
5. Only approved verified results contribute votes to official **internal** dashboard totals.
6. Every approved correction creates a new version, preserving prior version and evidence.
7. No raw overwrite/delete of approved records, evidence, or audit trail through normal UI/API.
8. Every query and mutation enforces event scope and region/TPS scope on the server.
9. Vote totals must be contest-aware (especially legislative ballots); never double-count candidate + party totals.
10. Dashboard 'submitted TPS' and 'verified TPS' are distinct and measured per contest; neither may exceed total eligible TPS.

## Agent execution rules
- Read relevant docs and inspect existing code before changing anything.
- Make the smallest coherent change; no opportunistic refactors.
- Write a short plan with files to change, assumptions, and tests **before coding**.
- Work on **one approved milestone** only; stop after delivering it.
- Do not alter locked requirements or stack without written owner approval.
- For unresolved rules (forms, candidate data, TPS import source, OCR engine, hosting), write an explicit question; do not invent a regulation or source.
- Do not use real candidate images, real vote data, or real election results as placeholders. Use clearly labeled synthetic fixtures.
- Never hardcode credentials, secrets, role bypasses, or production access.
- Never bypass failing tests, suppress authorization errors, or silently relax validation.
- Do not claim a test passed unless executed; record actual results.
- Database migrations must be reversible where feasible; never destructively reset production data.

## Definition of done per task
- Meets named PRD acceptance criteria.
- Role and geographic authorization tests included.
- Success, failure, duplicate submission, concurrency, and audit cases tested where relevant.
- Mobile-width and desktop-width UI reviewed for UI tasks.
- Docs updated when behavior changes; no undocumented scope growth.
- Report: files changed, migration impact, commands run, test results, risks, and **stop for approval**.

## Phase-gate rule
Phase 0 = documentation review only. No code. At the end of every phase, stop and request permission for the next phase.
