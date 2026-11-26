<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a decision year opens and closes on the class calendar, in UTC.
 *
 * @property int $id
 * @property int $section_id
 * @property int $year
 * @property CarbonImmutable|null $opens_at
 * @property CarbonImmutable|null $closes_at
 * @property string|null $reading
 * @property string|null $reading_note
 */
#[Fillable(['section_id', 'year', 'opens_at', 'closes_at', 'reading', 'reading_note'])]
class SectionYear extends Model
{
    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    protected function casts(): array
    {
        return ['year' => 'integer', 'opens_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime'];
    }
}
