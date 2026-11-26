<?php

namespace App\Models;

use App\Ashby\Game\PlayState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class running the sim. The future is chosen or drawn by the instructor and shown to staff only until the replay.
 *
 * @property int $id
 * @property string $name
 * @property string $format workshop or term (RO3)
 * @property string $length slice or full (FF-D5)
 * @property string|null $join_code
 * @property int|null $capacity Most participants the class takes; null for no limit.
 * @property bool $auto_run Whether the calendar opens and closes years on its own.
 * @property list<string>|null $ai_off AI features the instructor has turned off for this class.
 * @property string|null $debrief
 * @property string|null $debrief_note
 * @property string|null $future
 * @property bool $future_drawn
 * @property string $timezone
 * @property int $open_through Last decision year the instructor has opened; 2026 means none.
 * @property int $closed_through Last decision year the instructor has closed; 2026 means none.
 */
#[Fillable(['name', 'format', 'length', 'future', 'future_drawn', 'timezone', 'join_code', 'capacity', 'auto_run', 'ai_off', 'debrief', 'debrief_note', 'open_through', 'closed_through'])]
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

    /**
     * The decision years this class plays.
     *
     * @return non-empty-list<int>
     */
    public function years(): array
    {
        return PlayState::yearsFor($this->length);
    }

    /** The class's last decision year: 2031 in the slice, 2034 in the full format. */
    public function lastYear(): int
    {
        $years = $this->years();

        return $years[count($years) - 1];
    }

    /** @return HasMany<Enrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }

    /** @return HasMany<SectionYear, $this> */
    public function calendar(): HasMany
    {
        return $this->hasMany(SectionYear::class);
    }

    /** True while the class has a free place, or no limit. */
    public function hasRoom(): bool
    {
        return $this->capacity === null || $this->enrolments()->count() < $this->capacity;
    }

    /** The lead instructor, if any. */
    public function owner(): ?User
    {
        /** @var User|null $owner */
        $owner = $this->staff()->wherePivot('role', 'owner')->orderBy('section_staff.id')->first();

        return $owner;
    }

    /** Whether an AI feature is on for this class. The server must also have AI on. */
    public function aiOn(string $feature): bool
    {
        return ! in_array($feature, $this->ai_off ?? [], true);
    }

    public function isStaff(User $user): bool
    {
        return $user->isAdmin() || ($user->isFaculty() && $this->staff()->where('users.id', $user->id)->exists());
    }

    protected function casts(): array
    {
        return ['open_through' => 'integer', 'closed_through' => 'integer', 'future_drawn' => 'boolean', 'capacity' => 'integer', 'auto_run' => 'boolean', 'ai_off' => 'array'];
    }
}
