<?php

namespace App\Console\Commands;

use App\Support\ProductionConfiguration;
use Illuminate\Console\Command;

class CheckProductionReadiness extends Command
{
    protected $signature = 'ops:production-check';

    protected $description = 'Validate security-critical production configuration';

    public function handle(ProductionConfiguration $configuration): int
    {
        $errors = $configuration->errors();
        if ($errors !== []) {
            $this->components->error('Konfigurasi belum aman untuk production.');
            foreach ($errors as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->components->info('Konfigurasi security-critical lulus pemeriksaan awal production.');

        return self::SUCCESS;
    }
}
