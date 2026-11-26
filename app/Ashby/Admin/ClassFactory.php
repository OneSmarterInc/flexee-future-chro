<?php

namespace App\Ashby\Admin;

use App\Ashby\Game\PlayState;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes classes and instructor logins. Used by the admin page and the console commands.
 */
final class ClassFactory
{
    public const FORMATS = ['workshop', 'term'];

    public function create(string $name, string $format, User $instructor, int $teams = 0, ?int $capacity = null, string $timezone = 'America/New_York', string $length = 'slice'): Section
    {
        if (! $instructor->isFaculty() && ! $instructor->isAdmin()) {
            throw new RuntimeException('needs_instructor');
        }
        if (! in_array($format, self::FORMATS, true) || ! in_array($length, PlayState::LENGTHS, true)) {
            throw new RuntimeException('not_a_format');
        }

        return DB::transaction(function () use ($name, $format, $instructor, $teams, $capacity, $timezone, $length): Section {
            $section = Section::query()->create([
                'name' => $name, 'format' => $format, 'length' => $length, 'timezone' => $timezone, 'capacity' => $capacity,
                'auto_run' => $format === 'term',
            ]);
            $section->staff()->attach($instructor->id, ['role' => 'owner']);
            for ($i = 1; $i <= $teams; $i++) {
                Team::query()->create(['section_id' => $section->id, 'name' => "Team {$i}"]);
            }

            return $section->fresh() ?? $section;
        });
    }

    /**
     * A new login with a one-time password, returned once and never stored in plain text.
     *
     * @return array{user: User, password: string}
     */
    public function login(string $name, string $email, string $role): array
    {
        $email = Str::lower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            throw new RuntimeException('email_taken');
        }
        $password = Str::password(16, symbols: false);
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'role' => $role]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return ['user' => $user, 'password' => $password];
    }

    /** Makes someone the lead instructor. The old lead stays on as a co-instructor. */
    public function setLead(Section $section, User $lead): void
    {
        DB::transaction(function () use ($section, $lead): void {
            $section->staff()->wherePivot('role', 'owner')->get()->each(fn (User $u) => $section->staff()->updateExistingPivot($u->id, ['role' => 'co']));
            $section->staff()->syncWithoutDetaching([$lead->id => ['role' => 'owner']]);
            $section->staff()->updateExistingPivot($lead->id, ['role' => 'owner']);
        });
    }

    public function addCo(Section $section, User $co): void
    {
        if (! $section->staff()->where('users.id', $co->id)->exists()) {
            $section->staff()->attach($co->id, ['role' => 'co']);
        }
    }

    public function removeCo(Section $section, User $co): void
    {
        $section->staff()->wherePivot('role', 'co')->detach($co->id);
    }

    /** A class can be deleted only before 2027 opens (RO4). */
    public function delete(Section $section): void
    {
        if ($section->open_through >= 2027) {
            throw new RuntimeException('started');
        }
        DB::transaction(function () use ($section): void {
            foreach ($section->teams as $team) {
                $team->members()->detach();
                $team->years()->delete();
                $team->messages()->delete();
                $team->delete();
            }
            $section->enrolments()->delete();
            $section->calendar()->delete();
            $section->staff()->detach();
            $section->delete();
        });
    }
}
