<?php

namespace Tests\Feature\Ashby;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoClassTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_practice_class_has_an_instructor_teams_of_four_and_no_known_password(): void
    {
        $this->artisan('ashby:demo-class', ['--future' => 'rapid', '--teams' => 2])->assertSuccessful();
        $this->assertSame(2, Team::query()->count());
        $team = Team::query()->with('members', 'section.staff')->firstOrFail();
        $this->assertSame('rapid', $team->section->future);
        $this->assertSame(2026, $team->section->open_through);
        $instructor = $team->section->staff->firstOrFail();
        $this->assertTrue($instructor->isFaculty());
        $this->assertFalse(Hash::check('password', $instructor->password));
        $this->assertEqualsCanonicalizing(['chro', 'talent', 'learning', 'governance'], $team->members->map(fn (User $u) => $u->pivot?->getAttribute('seat'))->all());
        foreach ($team->members as $user) {
            $this->assertFalse(Hash::check('password', $user->password));
        }
    }

    public function test_an_unknown_future_is_refused(): void
    {
        $this->artisan('ashby:demo-class', ['--future' => 'sunny'])->assertFailed();
    }
}
