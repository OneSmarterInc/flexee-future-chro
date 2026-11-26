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

    public function test_the_practice_class_has_four_seats_and_no_known_password(): void
    {
        $this->artisan('ashby:demo-class', ['--future' => 'rapid'])->assertSuccessful();
        $team = Team::query()->with('members', 'section')->firstOrFail();
        $this->assertSame('rapid', $team->section->future);
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
