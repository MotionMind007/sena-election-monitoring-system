# Deployment & Operations Baseline

## Environments
- Local: development fixtures only.
- Staging: synthetic/non-sensitive data, production-like config.
- Production: internal restricted access, audited changes.

## Services
Laravel web + queue worker + scheduler; PostgreSQL; Redis; private S3-compatible storage; HTTPS reverse proxy. Start with one deployable Laravel app, not microservices.

## P3 production topology

- Web nodes use PostgreSQL for durable relational data and Redis for cache, rate limiting, encrypted sessions, and queues.
- `EVIDENCE_DISK=s3` stores TPS and official-recap evidence in a private bucket. Application-authorized download endpoints stream objects; the bucket must not expose a public policy or public ACL.
- S3-compatible storage must support the configured server-side encryption value (`AES256` by default). Provider-managed encryption keys and bucket versioning should be enabled where available.
- OCR workers download one object through a private stream into an operating-system temporary file, invoke Tesseract, and remove that file in a `finally` block.
- At least one queue worker and one scheduler process must be supervised. Start from [`deploy/supervisor/sena.conf.example`](../deploy/supervisor/sena.conf.example) and replace paths/users for the selected host.

## Build and release

Build an immutable release artifact in CI or staging, not by editing the live directory:

1. `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
2. `npm ci && npm run build`
3. `php artisan test --compact`
4. Run Composer and npm vulnerability audits.
5. Copy `.env.production.example` to the host's secret store and supply unique secrets; never package `.env` in the artifact.
6. Provision PostgreSQL, Redis, the private bucket, HTTPS proxy, worker, and scheduler before activation.

After the deployment platform has placed/switched the release, run `deploy/hooks/activate-release.sh /absolute/path/to/release`. It validates production configuration, enables maintenance mode, runs forward migrations, builds Laravel caches, and restarts workers. A failed activation intentionally leaves maintenance mode enabled; investigate or restore the previous compatible release before explicitly running `php artisan up`.

The hook does not install dependencies or build frontend assets. Those must already be inside the tested artifact.

## Readiness and background processes

`/up` is liveness only. `/ready` verifies database, cache, private evidence storage, and—when production heartbeats are required—fresh scheduler and queue-worker timestamps. The scheduler enqueues a heartbeat every minute; a worker must process it within `BACKGROUND_HEARTBEAT_MAX_AGE` or readiness returns HTTP 503 without naming the failed dependency.

Supervisor settings keep queue timeout (90 seconds) below Redis `retry_after` (120 seconds), preventing a slow OCR job from being delivered twice while still running. After configuration changes, run `supervisorctl reread`, `supervisorctl update`, and verify both programs are `RUNNING`.

## Rollback

1. Stop new approvals/uploads or enable maintenance mode.
2. Confirm a current, independently verified PostgreSQL and object-storage backup exists.
3. Decide whether the target application version is compatible with the current schema. Prefer a code-only rollback for backward-compatible migrations.
4. Have the deployment platform restore/switch to the previously tested release.
5. Run `deploy/hooks/activate-rollback.sh /absolute/path/to/restored-release` to clear/rebuild caches and restart workers.
6. Verify `/ready`, login, private evidence download, queue heartbeat, and a synthetic scoped report flow before reopening operations.

The rollback hook intentionally never runs `migrate:rollback`. A schema rollback requires a reviewed migration-specific plan and maintenance window; destructive or data-losing rollback is not automated.

## Required before launch
- Supported patched PHP/Laravel dependencies; lockfiles and vulnerability scan.
- Encrypted offsite backups for DB and object evidence, restoration test.
- MFA for Super Admin, secure secrets, access review, restricted admin paths.
- Health endpoints, error logs, queue failure monitoring, disk/storage alerts.
- Audit retention, evidence retention, access logs, export expiry.
- Database migrations rehearsed; rollback plan and maintenance window.
- Measure expected TPS submissions, concurrent users, upload throughput, and peak approval workload before sizing infrastructure.

Run `php artisan ops:production-check` after injecting secrets and before every release activation. It fails closed when PostgreSQL TLS, Redis-backed runtime services, encrypted secure sessions, S3 evidence encryption, or background heartbeats are not configured.

## Data integrity runbook
- Recompute all regional totals from active verified TPS results.
- Compare recomputed totals with cached aggregates and alert on mismatch.
- Verify evidence checksums and version chain for sampled approved results.
- Record result snapshot timestamp; dashboard clearly labels internal/provisional data.

## Incident handling
Freeze suspect approvals if integrity issue detected; preserve logs and evidence; restore only from verified backups; document corrective action and post-incident review.
