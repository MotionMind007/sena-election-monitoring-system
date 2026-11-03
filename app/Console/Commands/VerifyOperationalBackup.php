<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use ZipArchive;

class VerifyOperationalBackup extends Command
{
    protected $signature = 'ops:backup-verify {archive}';

    protected $description = 'Verify backup manifest and every archived checksum without restoring production';

    public function handle(): int
    {
        $archive = (string) $this->argument('archive');
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            $this->error('Arsip tidak dapat dibuka.');

            return self::FAILURE;
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (! is_array($manifest) || ! isset($manifest['database']['sha256'])) {
            $this->error('Manifest backup tidak valid.');

            return self::FAILURE;
        }
        $checksums = [$manifest['database']['path'] => $manifest['database']['sha256'], ...($manifest['files'] ?? [])];
        foreach ($checksums as $path => $expected) {
            $content = $zip->getFromName($path);
            if ($content === false || ! hash_equals($expected, hash('sha256', $content))) {
                $this->error('Checksum gagal: '.$path);

                return self::FAILURE;
            }
        }
        $restoredDatabase = tempnam(sys_get_temp_dir(), 'electcount-restore-');
        file_put_contents($restoredDatabase, $zip->getFromName($manifest['database']['path']));
        $pdo = new PDO('sqlite:'.$restoredDatabase);
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $auditTable = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='audit_logs'")->fetchColumn();
        unset($pdo);
        @unlink($restoredDatabase);
        if ($integrity !== 'ok' || (int) $auditTable !== 1) {
            $this->error('Database hasil restore gagal pemeriksaan integritas.');

            return self::FAILURE;
        }
        $zip->close();
        $this->info('Backup valid: '.count($checksums).' file terverifikasi dan database restore lulus integrity check.');

        return self::SUCCESS;
    }
}
