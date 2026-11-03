<?php

namespace App\Services;

use App\Models\Region;

class RegionHierarchy
{
    /** @return array<int, int> */
    public function descendantIds(int $rootId): array
    {
        $ids = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = Region::query()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }

    public function contains(int $rootId, int $regionId): bool
    {
        return in_array($regionId, $this->descendantIds($rootId), true);
    }
}
