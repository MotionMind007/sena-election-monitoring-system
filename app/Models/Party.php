<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'abbreviation', 'logo_path', 'active'])]
class Party extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function electionParties(): HasMany
    {
        return $this->hasMany(ElectionParty::class);
    }
}
