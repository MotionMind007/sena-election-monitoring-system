<?php

namespace App\Console\Commands;

use App\Actions\Elections\SyncElectionMasterData as SyncElectionMasterDataAction;
use App\Enums\UserRole;
use App\Models\Election;
use App\Models\User;
use Illuminate\Console\Command;

class SyncElectionMasterData extends Command
{
    protected $signature = 'election:sync-master-data {--event= : Election ID; omit to sync every event} {--confirm : Apply missing region and TPS links}';
    protected $description = 'Append missing current master regions and TPS to event snapshots without altering existing reports';

    public function handle(SyncElectionMasterDataAction $action): int
    {
        if (! $this->option('confirm')) {
            $this->error('Tambahkan --confirm untuk menyinkronkan master wilayah dan TPS ke event.');
            return self::FAILURE;
        }
        $actor = User::query()->where('role', UserRole::SuperAdmin->value)->orderBy('id')->first();
        if (! $actor instanceof User) {
            $this->error('Super Admin diperlukan untuk sinkronisasi event.');
            return self::FAILURE;
        }
        $events = Election::query()->when($this->option('event'), fn ($query, $eventId) => $query->whereKey($eventId))->get();
        if ($events->isEmpty()) {
            $this->error('Event tidak ditemukan.');
            return self::FAILURE;
        }
        $rows = $events->map(function (Election $event) use ($action, $actor): array {
            $result = $action->handle($actor, $event);
            return [$event->id, $event->name, $result['regions'], $result['polling_stations'], $event->pollingStations()->count()];
        });
        $this->table(['ID', 'Event', 'Wilayah ditambah', 'TPS ditambah', 'Total TPS event'], $rows->all());
        return self::SUCCESS;
    }
}
