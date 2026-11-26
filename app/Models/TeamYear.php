<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's decision year: its cards, its time at the table, its checks, its moment and its paragraph.
 *
 * @property int $id
 * @property int $team_id
 * @property int $year
 * @property array<string, mixed>|null $cards
 * @property int $minutes_used
 * @property int $checking_used
 * @property list<string>|null $checks
 * @property list<string>|null $revealed
 * @property string|null $moment
 * @property string|null $paragraph
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 */
#[Fillable(['team_id', 'year', 'cards', 'minutes_used', 'checking_used', 'checks', 'revealed', 'moment', 'paragraph', 'confirmed_at', 'confirmed_by'])]
class TeamYear extends Model
{
    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'cards' => 'array',
            'minutes_used' => 'integer',
            'checking_used' => 'integer',
            'checks' => 'array',
            'revealed' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
