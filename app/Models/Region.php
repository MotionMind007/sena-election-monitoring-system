<?php

namespace App\Models;

use App\Enums\RegionLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'level', 'parent_id', 'source', 'source_version', 'valid_from', 'valid_to'])]
class Region extends Model
{
    protected function casts(): array
    {
        return ['level' => RegionLevel::class, 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function pollingStations(): HasMany
    {
        return $this->hasMany(PollingStation::class);
    }

    public function electionRegions(): HasMany
    {
        return $this->hasMany(ElectionRegion::class);
    }

    public function electoralDistricts(): BelongsToMany
    {
        return $this->belongsToMany(ElectoralDistrict::class, 'electoral_district_regions');
    }
}
