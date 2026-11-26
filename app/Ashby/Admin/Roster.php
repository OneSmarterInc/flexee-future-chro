<?php

namespace App\Ashby\Admin;

use App\Ashby\Mail\Outgoing;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A class's participants and teams: who is enrolled, who sits in which seat on which team, and the ways in
 * (added by the instructor, or the join link). Logins are never deleted here: removing a participant takes
 * them off the roster and their team, and adding the same email again puts them back.
 */
final class Roster
{
    public function __construct(private readonly Outgoing $mail) {}

    /** Teams are formed in fours; three to five is fine (RO1). */
    public const TEAM_SIZE = 4;

    public const SMALLEST_TEAM = 3;

    public const LARGEST_TEAM = 5;

    /** Seats are given in this order; a fifth member shares the last seat (RO2). */
    public const SEATS = ['chro', 'talent', 'learning', 'governance'];

    /**
     * Turns what an instructor pasted or uploaded into [email, name] pairs. Each line may be an email,
     * "Name <email>", "email, name" or "name, email" (a CSV row). A header row is ignored. Lines with no
     * usable email come back under `rejected`.
     *
     * @return array{people: list<array{email: string, name: string}>, rejected: list<string>}
     */
    public static function parse(string $text): array
    {
        $people = [];
        $rejected = [];
        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $raw) {
            $line = trim($raw, " \t\"'");
            if ($line === '') {
                continue;
            }
            $email = null;
            $name = '';
            if (preg_match('/^(.*?)<\s*([^<>\s]+@[^<>\s]+)\s*>$/', $line, $m) === 1) {
                $email = $m[2];
                $name = trim($m[1], " \t\"'");
            } else {
                $parts = array_values(array_filter(array_map(fn (string $p): string => trim($p, " \t\"'"), preg_split('/[,;\t]/', $line) ?: []), fn (string $p): bool => $p !== ''));
                foreach ($parts as $i => $part) {
                    if (str_contains($part, '@')) {
                        $email = $part;
                        unset($parts[$i]);
                        break;
                    }
                }
                $name = implode(' ', $parts);
            }
            $email = Str::lower((string) $email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                if (! in_array(Str::lower(str_replace(' ', '', $line)), ['email', 'name', 'email,name', 'name,email', 'participant,email', 'student,email', 'firstname,lastname,email', 'lastname,firstname,email'], true)) {
                    $rejected[] = $line;
                }

                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $people[] = ['email' => $email, 'name' => $name !== '' ? $name : Str::before($email, '@')];
        }

        return ['people' => $people, 'rejected' => $rejected];
    }

    /**
     * Enrols people by email. A new email gets a participant login with a one-time password, returned once
     * and never stored in plain text. An existing participant is enrolled as they are. Stops at the class limit.
     *
     * @param  list<array{email: string, name: string}>  $people
     * @return list<array{email: string, name: string, password: string|null, note: string}>
     */
    public function add(Section $section, array $people, ?Team $team = null): array
    {
        if ($team !== null) {
            $this->assertTeam($section, $team);
        }
        $out = [];
        foreach ($people as $person) {
            $user = User::query()->where('email', $person['email'])->first();
            if ($user !== null && ! $user->isStudent()) {
                $out[] = ['email' => $person['email'], 'name' => $user->name, 'password' => null, 'note' => 'instructor'];

                continue;
            }
            $existing = $user !== null && Enrolment::query()->where('section_id', $section->id)->where('user_id', $user->id)->exists();
            if (! $existing && ! $section->hasRoom()) {
                $out[] = ['email' => $person['email'], 'name' => $person['name'], 'password' => null, 'note' => 'full'];

                continue;
            }
            $password = null;
            if ($user === null) {
                $password = Str::password(12, symbols: false);
                $user = User::query()->create(['name' => $person['name'], 'email' => $person['email'], 'password' => Hash::make($password), 'role' => User::ROLE_STUDENT]);
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            Enrolment::query()->updateOrCreate(['section_id' => $section->id, 'user_id' => $user->id], ['status' => Enrolment::ACTIVE]);
            if ($team !== null && $this->teamOf($section, $user) === null) {
                $this->seat($team, $user);
            }
            if ($password !== null) {
                $this->mail->login($section, $user, $password);
            } elseif (! $existing) {
                $this->mail->added($section, $user);
            }
            $mailed = Outgoing::enabled() && ! $existing ? '_mailed' : '';
            $out[] = ['email' => $user->email, 'name' => $user->name, 'password' => $password, 'note' => ($password !== null ? 'new' : ($existing ? 'already' : 'existing')).$mailed];
        }

        return $out;
    }

    /** A participant who followed the join link and has no login yet: a new login, enrolled. */
    public function join(Section $section, string $name, string $email, string $password): User
    {
        if (! $section->hasRoom()) {
            throw new RuntimeException('full');
        }
        $user = User::query()->create(['name' => $name, 'email' => Str::lower($email), 'password' => Hash::make($password), 'role' => User::ROLE_STUDENT]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Enrolment::query()->create(['section_id' => $section->id, 'user_id' => $user->id, 'status' => Enrolment::ACTIVE]);

        return $user;
    }

    /** Enrols a participant who already has a login and followed the join link. */
    public function joinExisting(Section $section, User $user): void
    {
        if (! $user->isStudent()) {
            throw new RuntimeException('not_for_you');
        }
        if (Enrolment::query()->where('section_id', $section->id)->where('user_id', $user->id)->exists()) {
            return;
        }
        if (! $section->hasRoom()) {
            throw new RuntimeException('full');
        }
        Enrolment::query()->create(['section_id' => $section->id, 'user_id' => $user->id, 'status' => Enrolment::ACTIVE]);
    }

    /** Makes (or replaces) the class's join code. The old link stops working. */
    public function newJoinCode(Section $section): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (Section::query()->where('join_code', $code)->exists());
        $section->update(['join_code' => $code]);

        return $code;
    }

    /**
     * Puts every unplaced participant on a team. Teams with people but fewer than three are topped up first.
     * The rest go into teams of four (five or three when the numbers need it): empty teams first, then new ones.
     *
     * @return int how many participants were placed
     */
    public function formTeams(Section $section): int
    {
        return DB::transaction(function () use ($section): int {
            $placedIds = DB::table('team_members')->whereIn('team_id', $section->teams()->select('id'))->pluck('user_id')->all();
            $waiting = $section->enrolments()->where('status', Enrolment::ACTIVE)->whereNotIn('user_id', $placedIds)->pluck('user_id')->shuffle()->all();
            $placed = 0;
            foreach ($section->teams()->withCount('members')->orderBy('id')->get() as $team) {
                for ($size = (int) $team->getAttribute('members_count'); $size > 0 && $size < self::SMALLEST_TEAM && $waiting !== []; $size++) {
                    $this->seat($team, $this->user((int) array_shift($waiting)));
                    $placed++;
                }
            }

            $count = count($waiting);
            if ($count === 0) {
                return $placed;
            }
            // One or two left over join the smallest teams that have room, as fifth members.
            $staffed = $section->teams()->withCount('members')->orderBy('members_count')->orderBy('id')->get()
                ->filter(fn (Team $t): bool => (int) $t->getAttribute('members_count') > 0 && (int) $t->getAttribute('members_count') < self::LARGEST_TEAM)->values();
            if ($count <= 2 && $staffed->count() >= $count) {
                foreach ($staffed as $team) {
                    if ($waiting === []) {
                        break;
                    }
                    $this->seat($team, $this->user((int) array_shift($waiting)));
                    $placed++;
                }

                return $placed;
            }

            $wanted = (int) max(1, intdiv($count, self::TEAM_SIZE));
            while ($wanted > 1 && $count / $wanted < self::SMALLEST_TEAM) {
                $wanted--;
            }
            while ($count / $wanted > self::LARGEST_TEAM) {
                $wanted++;
            }
            $empty = $section->teams()->withCount('members')->orderBy('id')->get()->filter(fn (Team $t): bool => (int) $t->getAttribute('members_count') === 0)->values()->all();
            $into = [];
            for ($i = 0; $i < $wanted; $i++) {
                $into[] = array_shift($empty) ?? $this->newTeam($section);
            }
            for ($i = 0; $waiting !== []; $i++) {
                $this->seat($into[$i % $wanted], $this->user((int) array_shift($waiting)));
                $placed++;
            }

            return $placed;
        });
    }

    /** Adds an empty team with the next free number. */
    public function newTeam(Section $section): Team
    {
        $taken = $section->teams()->pluck('name')->all();
        for ($i = 1; ; $i++) {
            if (! in_array("Team {$i}", $taken, true)) {
                return Team::query()->create(['section_id' => $section->id, 'name' => "Team {$i}"]);
            }
        }
    }

    public function renameTeam(Section $section, Team $team, string $name): void
    {
        $this->assertTeam($section, $team);
        $name = trim($name);
        if ($section->teams()->where('name', $name)->whereKeyNot($team->id)->exists()) {
            throw new RuntimeException('team_name_taken');
        }
        $team->update(['name' => $name]);
    }

    /** Moves a participant to a team, or off every team when $team is null. */
    public function move(Section $section, User $user, ?Team $team): void
    {
        $this->assertEnrolled($section, $user);
        if ($team !== null) {
            $this->assertTeam($section, $team);
        }
        $current = $this->teamOf($section, $user);
        if ($current !== null && $team !== null && $current->id === $team->id) {
            return;
        }
        if ($current !== null) {
            $this->unseat($current, $user);
        }
        if ($team !== null) {
            $this->seat($team, $user);
        }
    }

    /** Gives a member a seat. Every team keeps one CHRO: the one who held it moves to the seat given up. */
    public function setSeat(Section $section, User $user, string $seat): void
    {
        if (! in_array($seat, self::SEATS, true)) {
            throw new RuntimeException('not_a_seat');
        }
        $team = $this->teamOf($section, $user);
        if ($team === null) {
            throw new RuntimeException('not_on_team');
        }
        $old = (string) $team->members()->where('users.id', $user->id)->first()?->pivot?->getAttribute('seat');
        if ($old === $seat) {
            return;
        }
        DB::transaction(function () use ($team, $user, $seat, $old): void {
            if ($seat === 'chro') {
                $current = $team->members()->wherePivot('seat', 'chro')->first();
                if ($current !== null) {
                    $team->members()->updateExistingPivot($current->id, ['seat' => $old]);
                }
            }
            $team->members()->updateExistingPivot($user->id, ['seat' => $seat]);
            if ($old === 'chro') {
                $this->keepChro($team);
            }
        });
    }

    /** Pauses or restores a participant's access. A paused participant sees a plain page that says so. */
    public function pause(Section $section, User $user, bool $paused): void
    {
        $this->assertEnrolled($section, $user)->update(['status' => $paused ? Enrolment::PAUSED : Enrolment::ACTIVE]);
    }

    /** Marks a participant paid or not paid. It blocks nothing: the mark is a record for the gradebook. */
    public function markPaid(Section $section, User $user, bool $paid): void
    {
        $this->assertEnrolled($section, $user)->update(['paid_at' => $paid ? now() : null]);
    }

    /** Takes a participant off the roster and their team. Their login and anything their team did stay. */
    public function remove(Section $section, User $user): void
    {
        $enrolment = $this->assertEnrolled($section, $user);
        $team = $this->teamOf($section, $user);
        if ($team !== null) {
            $this->unseat($team, $user);
        }
        $enrolment->delete();
    }

    /** A new one-time password for a participant, returned once. */
    public function resetPassword(Section $section, User $user): string
    {
        $this->assertEnrolled($section, $user);
        if (! $user->isStudent()) {
            throw new RuntimeException('only_participants');
        }
        $password = Str::password(12, symbols: false);
        $user->forceFill(['password' => Hash::make($password)])->save();

        return $password;
    }

    /** Deletes a team that has no members and has never confirmed a year. */
    public function deleteTeam(Section $section, Team $team): void
    {
        $this->assertTeam($section, $team);
        if ($team->members()->exists() || TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->exists()) {
            throw new RuntimeException('team_in_use');
        }
        DB::transaction(function () use ($team): void {
            TeamYear::query()->where('team_id', $team->id)->delete();
            $team->messages()->delete();
            $team->delete();
        });
    }

    /** The team a participant is on in this class, if any. */
    public function teamOf(Section $section, User $user): ?Team
    {
        return $user->teams()->where('teams.section_id', $section->id)->first();
    }

    /** Puts a user on a team in the first free seat, or the last seat when all four are taken (RO2). */
    private function seat(Team $team, User $user): void
    {
        $taken = $team->members()->pluck('team_members.seat')->all();
        $seat = self::SEATS[count(self::SEATS) - 1];
        foreach (self::SEATS as $s) {
            if (! in_array($s, $taken, true)) {
                $seat = $s;
                break;
            }
        }
        $team->members()->attach($user->id, ['seat' => $seat]);
    }

    private function unseat(Team $team, User $user): void
    {
        $team->members()->detach($user->id);
        $this->keepChro($team);
    }

    /** When a team has members but no CHRO, its longest-standing member takes the seat. */
    private function keepChro(Team $team): void
    {
        if ($team->members()->wherePivot('seat', 'chro')->exists()) {
            return;
        }
        $next = $team->members()->orderBy('team_members.id')->first();
        if ($next !== null) {
            $team->members()->updateExistingPivot($next->id, ['seat' => 'chro']);
        }
    }

    private function user(int $id): User
    {
        return User::query()->findOrFail($id);
    }

    private function assertEnrolled(Section $section, User $user): Enrolment
    {
        $enrolment = Enrolment::query()->where('section_id', $section->id)->where('user_id', $user->id)->first();
        if ($enrolment === null) {
            throw new RuntimeException('not_enrolled');
        }

        return $enrolment;
    }

    private function assertTeam(Section $section, Team $team): void
    {
        if ($team->section_id !== $section->id) {
            throw new RuntimeException('other_class');
        }
    }
}
