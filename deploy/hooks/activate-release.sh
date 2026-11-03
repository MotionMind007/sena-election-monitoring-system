#!/usr/bin/env bash
set -Eeuo pipefail

release_dir="${1:?Usage: activate-release.sh /absolute/path/to/release}"
cd "${release_dir}"

test -f artisan
test -f .env

php artisan ops:production-check
php artisan down --retry=60

php artisan migrate --force
php artisan optimize
php artisan queue:restart

php artisan up
