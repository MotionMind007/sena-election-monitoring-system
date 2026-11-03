# ADR-0001 — ElectCount V1 baseline

**Status:** Accepted (user decisions); details subject to Phase 0 review.

## Context
Internal single-party TPS reporting needs fast implementation, controlled access, verifiable tallies, and later mobile reuse.

## Decision
Use Laravel modular monolith + Vue 3/Inertia + Tailwind, PostgreSQL, Redis, private object storage; API-first services. Single dashboard geographic drilldown with dark glass UI. Real Count only in V1; Super Admin final approval; 3 roles.

## Consequences
One maintainable repository and shared business logic; future Flutter client can reuse REST API. Requires careful separation of Inertia controllers and application services, contest-specific validation, and high-integrity approval/versioning.

## Rejected for V1
Microservices, split frontend/backend repositories, public results portal, Quick Count, Flutter implementation, offline sync.
