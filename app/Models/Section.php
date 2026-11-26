<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class running the sim. The future is chosen or drawn by the instructor and shown to staff only until the replay.
 *
 * @property int $id
 * @property string $name
 * @property string|null $future
 * @property bool $future_drawn
 * @property string $timezone
 * @property int $open_through Last decision year the instructor has opened; 2026 means none.
 * @property int $closed_through Last decision year the instructor has closed; 2026 means none.
 */
#[Fillable(['name', 'future', 'future_drawn', 'timezone', 'open_through', 'closed_through'])]
class Section extends Model
{
    /** @return HasMany<Team, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'section_staff')->withPivot('role')->withTimestamps();
    }

    public function isStaff(User $user): bool
    {
        return $user->isAdmin() || $this->staff()->where('users.id', $user->id)->exists();
    }

    protected function casts(): array
    {
        return ['open_through' => 'integer', 'closed_through' => 'integer', 'future_drawn' => 'boolean'];
    }
}
