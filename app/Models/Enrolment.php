<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person in a class. Paused means they see a plain page; paid is a mark for the gradebook and blocks nothing.
 *
 * @property int $id
 * @property int $section_id
 * @property int $user_id
 * @property string $status
 * @property CarbonImmutable|null $paid_at
 * @property-read User $user
 * @property-read Section $section
 */
#[Fillable(['section_id', 'user_id', 'status', 'paid_at'])]
class Enrolment extends Model
{
    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime'];
    }
}
