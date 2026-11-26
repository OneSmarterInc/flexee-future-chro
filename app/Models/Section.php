<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class running the sim.
 *
 * @property int $id
 * @property string $name
 * @property string $future
 * @property string $timezone
 * @property int $open_through
 */
#[Fillable(['name', 'future', 'timezone', 'open_through'])]
class Section extends Model
{
    /** @return HasMany<Team, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    protected function casts(): array
    {
        return ['open_through' => 'integer'];
    }
}
