<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Admin\Calendar;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\Ashby\EngineFixtureTest;

/**
 * The full format (FF-D5): a decision every year from 2027 to 2034, then the replay.
 */
class FullFormatTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    public function test_each_reference_team_played_every_year_through_the_routes_matches_its_fixture(): void
    {
        $engine = new AshbyEngine(new ModelData);
        foreach (EngineFixtureTest::TEAMS as $name) {
            ['team' => $team, 'seats' => $seats] = $this->makeClass('rapid', 2034, 'full');
            $this->actingAs($seats['chro']);
            foreach (ReferenceTeams::cards($name, PlayState::FULL_YEARS) as $year => $cards) {
                $this->get('/play')->assertRedirect("/play/{$year}");
                $this->post("/play/{$year}/confirm", $cards)->assertRedirect("/play/{$year}?step=4");
            }
            $fixture = EngineFixtureTest::fixture($name);
            $played = app(PlayState::class)->decisions(Team::query()->findOrFail($team->id));
            $this->assertCount(8, $played);
            foreach (ModelData::FUTURES as $future) {
                $rows = $engine->play($played, $future);
                foreach ($fixture['futures'][$future]['rows'] as $i => $row) {
                    foreach (['profit', 'revenue', 'headcount', 'trust', 'strain', 'expert_bench'] as $field) {
                        $this->assertEqualsWithDelta((float) $row[$field], (float) $rows[$i][$field], max(1e-4, 1e-6 * abs((float) $row[$field])), "{$name} {$future} {$row['year']} {$field}.");
                    }
                    $this->assertSame($row['notes'], $rows[$i]['notes'], "{$name} {$future} {$row['year']} notes.");
                }
            }
            $this->get('/play/2028')->assertInertia(fn (Assert $p) => $p->has('results.years', 1)->where('results.years.0.year', 2028));
            $this->get('/play/2034')->assertInertia(fn (Assert $p) => $p->has('results.years', 2)->where('results.years.1.year', 2035));
            $this->get('/play')->assertRedirect('/play/replay');
            $this->get('/play/replay')->assertInertia(fn (Assert $p) => $p->where('replay.judgment', fn ($j) => abs((float) $j - (float) $fixture['judgment']) < 1e-6));
        }
    }

    public function test_the_new_years_have_a_shorter_table(): void
    {
        ['seats' => $seats] = $this->makeClass('complement', 2028, 'full');
        $this->actingAs($seats['chro'])->post('/play/2027/confirm', ReferenceTeams::cards('builder')[2027])->assertRedirect();
        $this->get('/play/2028')->assertInertia(fn (Assert $p) => $p
            ->where('minutesLeft', 12)
            ->where('checkingMinutes', 8)
            ->has('people', 3)
            ->where('people.2.key', 'tomas')
            ->where('words.brief.title', 'January 2028')
            ->where('standing.year', 2027));
        $this->post('/play/2028/check', ['check' => 'c7'])->assertSessionHasNoErrors();
        $this->post('/play/2028/check', ['check' => 'c8'])->assertSessionHasNoErrors();
        $this->post('/play/2028/check', ['check' => 'c9'])->assertSessionHasNoErrors();
        $this->post('/play/2028/ask', ['person' => 'claire', 'question' => 'Where did the rest of the savings go?'])->assertSessionHasNoErrors();
        $this->get('/play/2028')->assertInertia(fn (Assert $p) => $p->where('checkingUsed', 8)->where('minutesLeft', 3)->where('people.0.messages.0.new', true));
    }

    public function test_ruth_s_terms_are_set_in_the_2032_moment_and_change_the_numbers(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass('complement', 2032, 'full');
        $this->actingAs($seats['chro']);
        $cards = ReferenceTeams::cards('middle', PlayState::FULL_YEARS);
        foreach ([2027, 2028, 2029, 2030, 2031] as $year) {
            $this->post("/play/{$year}/confirm", $cards[$year])->assertRedirect();
        }
        $this->get('/play/2032')->assertInertia(fn (Assert $p) => $p->where('words.moment.sets', 'ruth')->has('words.moment.choices', 3));
        $this->post('/play/2032/moment', ['text' => 'Stay and teach.', 'ruth' => 'nope'])->assertSessionHasErrors('ruth');
        $this->post('/play/2032/moment', ['text' => 'Stay and teach.', 'ruth' => 'stay'])->assertSessionHasNoErrors();
        $this->get('/play/2032')->assertInertia(fn (Assert $p) => $p->where('cards.ruth', 'stay'));
        $this->post('/play/2032/confirm', array_merge($cards[2032], ['ruth' => 'stay']))->assertRedirect();

        $rows = app(PlayState::class)->rows(Team::query()->findOrFail($team->id), 2035);
        $notes = array_merge(...array_map(fn (array $r): array => $r['notes'], $rows));
        $this->assertContains('Ruth Adeyemi retired; 50% of her knowledge kept', $notes, 'She stays two years and hands over half of what she knows.');
        $this->assertSame([], $rows[5]['notes'], 'She is still at work in 2032.');
    }

    public function test_the_handover_runs_to_300_words_and_no_more(): void
    {
        ['seats' => $seats] = $this->makeClass('complement', 2034, 'full');
        $this->actingAs($seats['chro']);
        foreach (ReferenceTeams::cards('builder', PlayState::FULL_YEARS) as $year => $cards) {
            $this->post("/play/{$year}/confirm", $cards)->assertRedirect();
        }
        $this->post('/play/2033/paragraph', ['paragraph' => str_repeat('word ', 320)])->assertSessionHasNoErrors();
        $this->post('/play/2034/paragraph', ['paragraph' => str_repeat('word ', 320)])->assertSessionHasErrors('paragraph');
        $this->post('/play/2034/paragraph', ['paragraph' => str_repeat('word ', 290)])->assertSessionHasNoErrors();
    }

    public function test_the_board_runs_eight_years_and_the_workshop_calendar_takes_two_half_days(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lead = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($admin)->post('/admin/classes', ['name' => 'Full', 'format' => 'workshop', 'length' => 'full', 'instructor_id' => $lead->id, 'teams' => 1])->assertSessionHasNoErrors();
        $section = Section::query()->firstOrFail();
        $this->assertSame('full', $section->length);

        $this->actingAs($lead)->get("/faculty/{$section->id}")->assertInertia(fn (Assert $p) => $p->has('years', 8)->where('years.7.year', 2034)->where('lastYear', 2034));
        $this->post("/faculty/{$section->id}/future", ['future' => 'rapid']);
        $this->post("/faculty/{$section->id}/years/2027/open")->assertSessionHasNoErrors();
        $this->post("/faculty/{$section->id}/years/2029/open")->assertSessionHasErrors('board');
        $this->post("/faculty/{$section->id}/years/2028/open")->assertSessionHasNoErrors();
        $this->post("/faculty/{$section->id}/years/2027/close")->assertSessionHasNoErrors();
        $this->assertSame(2027, $section->fresh()?->closed_through);

        $plan = Calendar::plan('workshop', CarbonImmutable::parse('2027-03-01 09:00', 'UTC'), PlayState::FULL_YEARS);
        $local = array_map(fn (array $t): string => $t['opens_at']->format('D H:i'), $plan);
        $this->assertSame(['Mon 09:00', 'Mon 09:55', 'Mon 10:50', 'Mon 11:45', 'Tue 09:00', 'Tue 09:55', 'Tue 10:50', 'Tue 11:45'], array_values($local));
        $this->assertSame('09:45', $plan[2027]['closes_at']->format('H:i'));
        $this->post("/faculty/{$section->id}/calendar/fill", ['first' => '2027-03-01T09:00'])->assertSessionHasNoErrors();
        $this->get("/faculty/{$section->id}")->assertInertia(fn (Assert $p) => $p->where('calendar.2031.opens_at', '2027-03-02T09:00'));
    }

    public function test_the_full_format_briefs_say_one_year_has_passed(): void
    {
        ['seats' => $seats] = $this->makeClass('complement', 2029, 'full');
        $this->actingAs($seats['chro']);
        foreach ([2027, 2028] as $year) {
            $this->post("/play/{$year}/confirm", ReferenceTeams::cards('builder', PlayState::FULL_YEARS)[$year])->assertRedirect();
        }
        $this->get('/play/2029')->assertInertia(fn (Assert $p) => $p->where('words.brief.paragraphs.0', fn ($t) => str_starts_with((string) $t, 'Two years in.')));
    }
}
