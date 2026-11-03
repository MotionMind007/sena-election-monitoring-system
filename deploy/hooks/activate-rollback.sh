#!/usr/bin/env bash
set -Eeuo pipefail

release_dir="${1:?Usage: activate-rollback.sh /absolute/path/to/restored-release}"
cd "${release_dir}"

test -f artisan
test -f .env

php artisan ops:production-check
php artisan down --retry=60

# Database rollback is intentionally not automatic. Restore only after the
# migration compatibility decision and verified backup described in the runbook.
php artisan optimize:clear
php artisan optimize
php artisan queue:restart

php artisan up
