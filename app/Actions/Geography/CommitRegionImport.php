<?php

namespace App\Actions\Geography;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\ImportStatus;
use App\Models\Election;
use App\Models\ElectionRegion;
use App\Models\GeographyImport;
use App\Models\Region;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommitRegionImport
{
    public function __construct(
        private readonly ParseRegionCsv $parser,
        private readonly WriteAuditLog $audit,
    ) {}

    public function handle(GeographyImport $import): GeographyImport
    {
        if ($import->status !== ImportStatus::Previewed) {
            throw ValidationException::withMessages(['import' => 'Batch import ini tidak dapat diproses lagi.']);
        }

        $parsed = $this->parser->handle(Storage::disk('local')->path($import->storage_path));

        return DB::transaction(function () use ($import, $parsed): GeographyImport {
            $pending = collect($parsed['rows'])->keyBy('code');
            $resolved = [];

            while ($pending->isNotEmpty()) {
                $progress = false;

                foreach ($pending as $code => $row) {
                    $parent = $row['parent_code'] === null
                        ? null
                        : ($resolved[$row['parent_code']] ?? Region::query()->where('code', $row['parent_code'])->first());

                    if ($row['parent_code'] !== null && $parent === null) {
                        continue;
                    }

                    $region = Region::query()
                        ->where('level', $row['level'])
                        ->where('parent_id', $parent?->id)
                        ->whereLike('name', $row['name'], caseSensitive: false)
                        ->first();

                    if (! $region) {
                        $region = Region::query()->create([
                            'code' => $code,
                            'name' => $row['name'],
                            'level' => $row['level'],
                            'parent_id' => $parent?->id,
                            'source' => $import->source,
                            'source_version' => $import->source_version,
                        ]);
                    } elseif ($import->source === 'BPS' && ! Str::startsWith($row['code'], 'IMP-')) {
                        // A previously manual row may be the same real area.
                        // Preserve its ID (and all event/TPS relations), but pin it
                        // to the authoritative BPS code and provenance.
                        $region->update([
                            'code' => $row['code'],
                            'name' => $row['name'],
                            'source' => $import->source,
                            'source_version' => $import->source_version,
                        ]);
                    }

                    $resolved[$code] = $region;
                    $pending->forget($code);
                    $progress = true;
                }

                if (! $progress) {
                    throw ValidationException::withMessages(['import' => 'Hierarchy import tidak dapat diselesaikan.']);
                }
            }

            $import->update(['status' => ImportStatus::Committed, 'committed_at' => now(), 'summary' => $parsed['summary']]);
            $this->syncDraftElectionSnapshots();
            $this->audit->handle($import->actor, 'geography_import.committed', $import, after: $import->toArray());

            return $import->refresh();
        });
    }

    private function syncDraftElectionSnapshots(): void
    {
        Election::query()->whereIn('status', ['draft', 'preparing'])->each(function (Election $election): void {
            $regions = collect([$election->scopeRegion]);
            $parentIds = [$election->scope_region_id];

            while ($parentIds !== []) {
                $children = Region::query()->whereIn('parent_id', $parentIds)->get();
                $regions = $regions->concat($children);
                $parentIds = $children->pluck('id')->all();
            }

            foreach ($regions as $region) {
                ElectionRegion::query()->firstOrCreate(
                    ['election_id' => $election->id, 'region_id' => $region->id],
                    [
                        'code_snapshot' => $region->code,
                        'name_snapshot' => $region->name,
                        'level_snapshot' => $region->level->value,
                        'source_snapshot' => $region->source,
                        'source_version_snapshot' => $region->source_version,
                    ],
                );
            }
        });
    }
}
