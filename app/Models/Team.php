<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team: the HR leadership team of Ashby Calder in one class.
 *
 * @property int $id
 * @property int $section_id
 * @property string $name
 * @property-read Section $section
 */
#[Fillable(['section_id', 'name'])]
class Team extends Model
{
    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')->withPivot('seat')->withTimestamps();
    }

    /** @return HasMany<TeamYear, $this> */
    public function years(): HasMany
    {
        return $this->hasMany(TeamYear::class);
    }

    /** @return HasMany<TableMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(TableMessage::class);
    }
}
