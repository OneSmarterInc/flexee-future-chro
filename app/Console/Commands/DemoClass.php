<?php

namespace App\Console\Commands;

use App\Ashby\Engine\ModelData;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A practice class with an instructor and teams of four seats, for walking the screens.
 * The instructor chooses the future and opens 2027 on the board. Each password is random and shown once.
 */
class DemoClass extends Command
{
    protected $signature = 'ashby:demo-class {--future=complement : rapid, complement or constrained} {--teams=4 : how many teams of four seats} {--domain=example.test : the e-mail domain for the four logins}';

    protected $description = 'Make a practice class with an instructor and teams of four seats, and print a random password for each login';

    public function handle(): int
    {
        $future = (string) $this->option('future');
        if (! in_array($future, ModelData::FUTURES, true)) {
            $this->error('The future must be one of: '.implode(', ', ModelData::FUTURES).'.');

            return self::FAILURE;
        }
        $teams = max(1, min(12, (int) $this->option('teams')));
        $tag = Str::lower(Str::random(6));
        $logins = [];

        DB::transaction(function () use ($future, $teams, $tag, &$logins): void {
            $section = Section::query()->create(['name' => "Practice {$tag}", 'future' => $future, 'timezone' => 'America/New_York']);
            $instructor = $this->login('Practice instructor', "instructor-{$tag}", User::ROLE_FACULTY, $logins, 'instructor');
            $section->staff()->attach($instructor->id, ['role' => 'owner']);
            for ($n = 1; $n <= $teams; $n++) {
                $team = Team::query()->create(['section_id' => $section->id, 'name' => "Team {$n}"]);
                foreach (['chro', 'talent', 'learning', 'governance'] as $seat) {
                    $user = $this->login("Team {$n} {$seat}", "t{$n}-{$seat}-{$tag}", User::ROLE_STUDENT, $logins, "Team {$n} {$seat}");
                    $team->members()->attach($user->id, ['seat' => $seat]);
                    Enrolment::query()->create(['section_id' => $section->id, 'user_id' => $user->id, 'status' => Enrolment::ACTIVE]);
                }
            }
        });

        $this->table(['Login', 'E-mail', 'Password (shown once)'], $logins);

        return self::SUCCESS;
    }

    /** @param  list<array{string, string, string}>  $logins */
    private function login(string $name, string $local, string $role, array &$logins, string $label): User
    {
        $password = Str::password(16, symbols: false);
        $user = User::query()->create([
            'name' => $name,
            'email' => $local.'@'.$this->option('domain'),
            'password' => Hash::make($password),
            'role' => $role,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $logins[] = [$label, $user->email, $password];

        return $user;
    }
}
