<?php

namespace App\Console\Commands;

use App\Ashby\Engine\ModelData;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A practice class with one team of four seats, for walking the screens. Each password is random and shown once.
 */
class DemoClass extends Command
{
    protected $signature = 'ashby:demo-class {--future=complement : rapid, complement or constrained} {--through=2031 : the last decision year open} {--domain=example.test : the e-mail domain for the four logins}';

    protected $description = 'Make a practice class with one team of four seats and print a random password for each seat';

    public function handle(): int
    {
        $future = (string) $this->option('future');
        if (! in_array($future, ModelData::FUTURES, true)) {
            $this->error('The future must be one of: '.implode(', ', ModelData::FUTURES).'.');

            return self::FAILURE;
        }
        $through = (int) $this->option('through');
        $tag = Str::lower(Str::random(6));
        $logins = [];

        DB::transaction(function () use ($future, $through, $tag, &$logins): void {
            $section = Section::query()->create(['name' => "Practice {$tag}", 'future' => $future, 'timezone' => 'America/New_York', 'open_through' => $through]);
            $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Practice team']);
            foreach (['chro', 'talent', 'learning', 'governance'] as $seat) {
                $password = Str::password(16, symbols: false);
                $user = User::query()->create([
                    'name' => "Practice {$seat}",
                    'email' => "{$seat}-{$tag}@".$this->option('domain'),
                    'password' => Hash::make($password),
                    'role' => User::ROLE_STUDENT,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $team->members()->attach($user->id, ['seat' => $seat]);
                $logins[] = [$seat, $user->email, $password];
            }
        });

        $this->table(['Seat', 'E-mail', 'Password (shown once)'], $logins);

        return self::SUCCESS;
    }
}
