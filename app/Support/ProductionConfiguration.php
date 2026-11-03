<?php

namespace App\Support;

use RuntimeException;

class ProductionConfiguration
{
    /** @return array<int, string> */
    public function errors(): array
    {
        $errors = [];

        if ((bool) config('app.debug')) {
            $errors[] = 'APP_DEBUG wajib false.';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $errors[] = 'APP_URL wajib menggunakan HTTPS.';
        }
        if (blank(config('app.key'))) {
            $errors[] = 'APP_KEY wajib diisi.';
        }
        if (config('database.default') !== 'pgsql') {
            $errors[] = 'DB_CONNECTION production wajib pgsql.';
        }
        if (! in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true)) {
            $errors[] = 'DB_SSLMODE production wajib require, verify-ca, atau verify-full.';
        }
        if (config('cache.default') !== 'redis') {
            $errors[] = 'CACHE_STORE production wajib redis.';
        }
        if (config('queue.default') !== 'redis') {
            $errors[] = 'QUEUE_CONNECTION production wajib redis.';
        }
        if (config('cache.limiter') !== 'redis') {
            $errors[] = 'RATE_LIMITER_STORE production wajib redis.';
        }
        if (config('session.driver') !== 'redis') {
            $errors[] = 'SESSION_DRIVER production wajib redis.';
        }
        if (config('filesystems.evidence') !== 's3') {
            $errors[] = 'EVIDENCE_DISK production wajib s3.';
        }
        if (blank(config('filesystems.disks.s3.bucket')) || blank(config('filesystems.disks.s3.region'))) {
            $errors[] = 'AWS_BUCKET dan AWS_DEFAULT_REGION wajib diisi.';
        }
        if (blank(config('filesystems.disks.s3.options.ServerSideEncryption'))) {
            $errors[] = 'AWS_SERVER_SIDE_ENCRYPTION wajib diisi.';
        }
        if (! (bool) config('queue.connections.redis.after_commit')) {
            $errors[] = 'Redis queue wajib menggunakan after_commit.';
        }
        if (! (bool) config('health.require_background_heartbeats')) {
            $errors[] = 'REQUIRE_BACKGROUND_HEARTBEATS production wajib true.';
        }
        if (! (bool) config('session.encrypt')) {
            $errors[] = 'SESSION_ENCRYPT production wajib true.';
        }
        if (! (bool) config('session.secure')) {
            $errors[] = 'SESSION_SECURE_COOKIE production wajib true.';
        }
        if (! (bool) config('malware-scan.enabled') || blank(config('malware-scan.host'))) {
            $errors[] = 'Pemeriksaan malware ClamAV production wajib aktif dan memiliki host.';
        }
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            $errors[] = 'Ekstensi GD dengan dukungan WebP wajib tersedia untuk pratinjau bukti foto.';
        }

        return $errors;
    }

    public function assertSafe(): void
    {
        $errors = $this->errors();
        if ($errors !== []) {
            throw new RuntimeException("Konfigurasi production tidak aman:\n- ".implode("\n- ", $errors));
        }
    }
}
