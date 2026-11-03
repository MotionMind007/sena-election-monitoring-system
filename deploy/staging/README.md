# Isolated P4 staging stack

This Compose stack is disposable. It runs PostgreSQL, Redis, MinIO (S3-compatible private evidence), PHP-FPM behind Caddy, one queue worker, and the scheduler without using production credentials or data.

## Start

1. Copy `.env.staging.example` to `.env.staging.local` in this directory.
2. Generate a unique Laravel app key with `php artisan key:generate --show` and set `APP_KEY`.
3. Set unique local-only values for `STAGING_DB_PASSWORD`, `STAGING_MINIO_ACCESS_KEY`, and `STAGING_MINIO_SECRET_KEY`; copy the MinIO credentials into `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`.
4. Run `docker compose --env-file .env.staging.local up --build` from this directory.
5. Seed only synthetic P4 fixtures, run the guarded k6 scenarios, record results in `docs/PERFORMANCE.md`, then remove the stack with `docker compose --env-file .env.staging.local down -v`.

The stack does not seed demo data automatically. This prevents a load run from silently using unreviewed records. The disposable local MinIO instance has no KMS, so its `AWS_SERVER_SIDE_ENCRYPTION` value must remain empty; the production configuration check still requires KMS-backed server-side encryption. Never place real credentials, election data, or evidence in `.env.staging.local`; it is intentionally excluded from version control.
