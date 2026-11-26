<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Game\Cards;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;

/** A class with one team of four seats, for the play tests. */
trait MakesAClass
{
    /** @return array{section: Section, team: Team, seats: array<string, User>} */
    protected function makeClass(string $future = 'complement', int $openThrough = 2031, string $length = 'slice'): array
    {
        $section = Section::query()->create(['name' => 'Test class', 'future' => $future, 'timezone' => 'America/New_York', 'open_through' => $openThrough, 'length' => $length]);
        $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Team 1']);
        $seats = [];
        foreach (['chro', 'talent', 'learning', 'governance'] as $seat) {
            $user = User::factory()->create();
            $team->members()->attach($user->id, ['seat' => $seat]);
            $seats[$seat] = $user;
        }

        return ['section' => $section, 'team' => $team, 'seats' => $seats];
    }

    /** @return array<string, mixed> */
    protected function cards(array $changes = []): array
    {
        return array_replace_recursive(Cards::defaults(), $changes);
    }
}
