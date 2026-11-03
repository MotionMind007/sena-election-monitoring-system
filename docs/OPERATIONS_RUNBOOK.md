# ElectCount V1 Operations Runbook

## Required production services

- HTTPS reverse proxy and PHP-FPM/web process.
- Supervised queue worker and scheduler based on `deploy/supervisor/sena.conf.example`.
- PostgreSQL, Redis, and private S3-compatible storage must not be public.
- Tesseract binary path is configured with `TESSERACT_BINARY`.

## Deploy

1. Put the application in maintenance mode and back up the current release.
2. Install locked Composer/NPM dependencies and build assets.
3. Run `php artisan migrate --force`, clear/cache configuration, then restart queue workers.
4. Check `/up`, login, one scoped dashboard, private evidence access, queue health, and scheduler output.
5. Exit maintenance mode. Roll back application code if checks fail; never roll back destructive data blindly.

## Backup and restore

Development SQLite backup is available through `php artisan ops:backup`; verify with `php artisan ops:backup-verify <archive>`.

Production PostgreSQL must use an encrypted `pg_dump` plus a versioned private-object backup. Restore into an isolated database, run migrations only when required, verify audit-log counts/checksums, evidence object counts, active verified-result uniqueness, and dashboard reconciliation before switching traffic.

## Pre-deploy configuration check

Run `php artisan ops:production-check` before migrations or traffic switching. Production HTTP boot fails closed when the security-critical checks do not pass. Use `/up` for liveness and `/ready` for database, cache, evidence-storage, scheduler, and queue-worker readiness; do not expose internal dependency errors through either endpoint.

Retention, off-site destination, RPO, and RTO remain unset until the owner confirms hosting and legal retention requirements. Do not delete backups automatically before those decisions.

## Security checklist

- Super Admin MFA and at least two separately controlled Super Admin accounts.
- Secure cookies, HTTPS, CSRF, rate limiting, Argon2id, least-privilege database credentials.
- Private storage and short-lived authenticated evidence access.
- Review failed logins, mass exports, repeated submissions, conflicts, OCR failures, and unusual approvals.
- Confirm no coordinator/witness can access sibling regions or Super Admin routes.

## Load test gate

Use the guarded scenarios in `scripts/load/README.md` only against disposable staging. Production capacity cannot be signed off until target TPS count, concurrent users, photo volume, server size, acceptable latency/error rate, and queue lag are confirmed by the owner.
