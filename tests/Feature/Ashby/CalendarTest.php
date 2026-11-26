<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Admin\Calendar;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_term_opens_one_year_a_week_at_the_same_clock_time_across_the_clock_change(): void
    {
        $plan = Calendar::plan('term', CarbonImmutable::parse('2026-10-26 09:00', 'America/New_York'));
        $this->assertSame('2026-10-26 13:00', $plan[2027]['opens_at']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-02 14:00', $plan[2029]['opens_at']->format('Y-m-d H:i'), 'Clocks went back on 1 Nov; 9:00 local is now 14:00 UTC.');
        $this->assertSame('2026-11-09 14:00', $plan[2031]['opens_at']->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 14:00', $plan[2027]['closes_at']->format('Y-m-d H:i'));
    }

    public function test_a_workshop_runs_a_year_every_two_hours(): void
    {
        $plan = Calendar::plan('workshop', CarbonImmutable::parse('2026-12-03 09:00', 'Europe/London'));
        $this->assertSame(['09:00', '11:00', '13:00'], array_map(fn (array $t): string => $t['opens_at']->setTimezone('Europe/London')->format('H:i'), array_values($plan)));
        $this->assertSame('10:45', $plan[2027]['closes_at']->setTimezone('Europe/London')->format('H:i'));
    }

    public function test_the_instructor_sets_the_calendar_and_participants_read_the_time_in_local_time(): void
    {
        $section = Section::query()->create(['name' => 'Term class', 'format' => 'term', 'timezone' => 'America/Chicago']);
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);
        $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Team 1']);
        $chro = User::factory()->create();
        $team->members()->attach($chro->id, ['seat' => 'chro']);

        $this->actingAs($instructor)->post("/faculty/{$section->id}/calendar/fill", ['first' => '2027-01-11T18:30'])->assertSessionHasNoErrors();
        $this->get("/faculty/{$section->id}")->assertInertia(fn (Assert $p) => $p
            ->where('calendar.2027.opens_at', '2027-01-11T18:30')
            ->where('calendar.2029.opens_at', '2027-01-18T18:30')
            ->where('calendar.2031.closes_at', '2027-01-31T18:30'));
        $this->post("/faculty/{$section->id}/calendar", ['years' => [2027 => ['opens_at' => '2027-01-12T10:00', 'closes_at' => '2027-01-12T09:00']], 'auto_run' => true])->assertSessionHasErrors('board');
        $this->post("/faculty/{$section->id}/calendar", ['years' => [
            2027 => ['opens_at' => '2027-01-12T10:00', 'closes_at' => '2027-01-18T10:00'],
            2029 => ['opens_at' => '2027-01-19T10:00', 'closes_at' => '2027-01-25T10:00'],
            2031 => ['opens_at' => null, 'closes_at' => null],
        ], 'auto_run' => false])->assertSessionHasNoErrors();
        $this->assertFalse($section->fresh()?->auto_run);

        $this->actingAs($chro)->get('/play')->assertInertia(fn (Assert $p) => $p->component('ashby/NoTeam')->where('message', 'Your instructor opens 2027 at Tue 12 Jan, 10:00 am.'));
    }

    public function test_the_calendar_opens_draws_a_future_if_needed_and_closes_on_its_own(): void
    {
        $section = Section::query()->create(['name' => 'Workshop', 'format' => 'workshop', 'timezone' => 'UTC', 'auto_run' => true]);
        $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Team 1']);
        $section->calendar()->create(['year' => 2027, 'opens_at' => '2027-03-01 09:00:00', 'closes_at' => '2027-03-01 10:45:00']);
        $section->calendar()->create(['year' => 2029, 'opens_at' => '2027-03-01 11:00:00', 'closes_at' => '2027-03-01 12:45:00']);
        $calendar = app(Calendar::class);

        $this->assertSame([], $calendar->tick(CarbonImmutable::parse('2027-03-01 08:59', 'UTC')));
        $done = $calendar->tick(CarbonImmutable::parse('2027-03-01 09:00', 'UTC'));
        $this->assertSame(["{$section->id}: drew the future", "{$section->id}: opened 2027"], $done);
        $section->refresh();
        $this->assertNotNull($section->future);
        $this->assertTrue($section->future_drawn);
        $this->assertSame(2027, $section->open_through);

        $calendar->tick(CarbonImmutable::parse('2027-03-01 10:50', 'UTC'));
        $section->refresh();
        $this->assertSame(2027, $section->closed_through);
        $this->assertNotNull(TeamYear::query()->where('team_id', $team->id)->where('year', 2027)->value('confirmed_at'));
        $this->assertNull(TeamYear::query()->where('team_id', $team->id)->where('year', 2027)->value('confirmed_by'));

        // A class that doesn't run on its own is left alone.
        $section->update(['auto_run' => false]);
        $this->assertSame([], $calendar->tick(CarbonImmutable::parse('2027-03-01 13:00', 'UTC')));
        $this->assertSame(0, Artisan::call('ashby:tick'));
    }
}
