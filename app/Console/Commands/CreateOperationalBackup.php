<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class CreateOperationalBackup extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'Create a private database and evidence backup archive';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->error('Backup otomatis saat ini hanya dikonfirmasi untuk development SQLite. Gunakan pg_dump terenkripsi sesuai runbook production.');

            return self::FAILURE;
        }
        $database = config('database.connections.sqlite.database');
        if (! is_string($database) || ! is_file($database)) {
            throw new RuntimeException('File database SQLite tidak ditemukan.');
        }

        $directory = storage_path('app/private/backups');
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        $path = $directory.'/electcount-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        throw_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true, RuntimeException::class, 'Arsip backup tidak dapat dibuat.');
        $zip->addFile($database, 'database/database.sqlite');
        $files = [];
        $privateRoot = storage_path('app/private');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($privateRoot, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || str_starts_with($file->getPathname(), $directory)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($privateRoot) + 1));
            $zip->addFile($file->getPathname(), 'private/'.$relative);
            $files['private/'.$relative] = hash_file('sha256', $file->getPathname());
        }
        $manifest = ['created_at' => now()->toIso8601String(), 'database' => ['path' => 'database/database.sqlite', 'sha256' => hash_file('sha256', $database)], 'files' => $files];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $zip->close();
        $this->info($path);

        return self::SUCCESS;
    }
}
