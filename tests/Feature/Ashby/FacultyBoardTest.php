<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\Ashby\EngineFixtureTest;

/**
 * PR 4's "done" test: an instructor runs a class of four teams end to end on the board.
 */
class FacultyBoardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{section: Section, instructor: User, teams: array<string, array{team: Team, chro: User}>} */
    private function classOfFour(): array
    {
        $section = Section::query()->create(['name' => 'Workshop', 'timezone' => 'America/New_York']);
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);
        $teams = [];
        foreach (ReferenceTeams::NAMES as $i => $name) {
            $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Team '.($i + 1)]);
            $chro = null;
            foreach (['chro', 'talent', 'learning', 'governance'] as $seat) {
                $user = User::factory()->create();
                $team->members()->attach($user->id, ['seat' => $seat]);
                $chro ??= $user;
            }
            $teams[$name] = ['team' => $team, 'chro' => $chro];
        }

        return ['section' => $section->fresh() ?? $section, 'instructor' => $instructor, 'teams' => $teams];
    }

    public function test_an_instructor_runs_a_class_of_four_teams_end_to_end(): void
    {
        ['section' => $section, 'instructor' => $instructor, 'teams' => $teams] = $this->classOfFour();
        $board = "/faculty/{$section->id}";

        $this->actingAs($instructor)->get('/dashboard')->assertRedirect('/faculty');
        $this->get('/faculty')->assertOk()->assertInertia(fn (Assert $p) => $p->component('ashby/faculty/Home')->has('sections', 1));
        $this->get($board)->assertOk()->assertInertia(fn (Assert $p) => $p->component('ashby/faculty/Board')->where('year', 2027)->where('years.0.state', 'not_open')->has('teams', 4));

        // 2027 needs a future first.
        $this->post("{$board}/years/2027/open")->assertSessionHasErrors('board');
        $this->post("{$board}/future", ['future' => 'complement'])->assertSessionHasNoErrors();
        $this->post("{$board}/years/2029/open")->assertSessionHasErrors('board');
        $this->post("{$board}/years/2027/open")->assertRedirect("{$board}/2027");

        foreach (ReferenceTeams::NAMES as $name) {
            $this->actingAs($teams[$name]['chro'])->get('/play')->assertRedirect('/play/2027');
        }

        foreach ([2027, 2029, 2031] as $year) {
            if ($year > 2027) {
                $this->actingAs($instructor)->post("{$board}/years/{$year}/open")->assertRedirect("{$board}/{$year}");
            }
            // Three teams confirm; the waiter team never does and is confirmed when the year closes.
            foreach (['builder', 'middle', 'cutter'] as $name) {
                $this->actingAs($teams[$name]['chro'])->post("/play/{$year}/confirm", ReferenceTeams::cards($name)[$year])->assertRedirect("/play/{$year}?step=4");
            }
            $this->actingAs($teams['waiter']['chro'])->get("/play/{$year}")->assertOk();
            $this->actingAs($instructor)->get("{$board}/{$year}")->assertInertia(fn (Assert $p) => $p
                ->where('years.'.array_search($year, [2027, 2029, 2031], true).'.state', 'open')
                ->where('teams.0.status', 'confirmed')
                ->where('teams.0.confirmed_by', 'Chief HR Officer')
                ->where('teams.3.status', 'at_table')
                ->where('panel.confirmed', 3));
            $this->post("{$board}/years/{$year}/close")->assertRedirect("{$board}/{$year}");
            $this->get("{$board}/{$year}")->assertInertia(fn (Assert $p) => $p
                ->where('teams.3.status', 'confirmed')
                ->where('teams.3.closed_by_staff', true)
                ->where('panel.confirmed', 4));
        }

        // The future is fixed now.
        $this->post("{$board}/future", ['future' => 'rapid'])->assertSessionHasErrors('board');

        // The replay on the board gives each team the reference team's judgment.
        $engine = new AshbyEngine(new ModelData);
        $this->get("{$board}/2031")->assertInertia(function (Assert $p) use ($engine) {
            $p->has('replay.teams', 4)->has('replay.reference', 4);
            foreach (ReferenceTeams::NAMES as $i => $name) {
                $expected = EngineFixtureTest::fixture($name)['judgment'];
                $p->where("replay.teams.{$i}.judgment", fn ($j) => abs((float) $j - (float) $expected) < 1e-6);
                $p->where("replay.reference.{$name}", fn ($j) => abs((float) $j - $engine->judgment(ReferenceTeams::decisions($name))['judgment']) < 1e-9);
            }

            return $p;
        });

        // Every participant is now at the replay.
        $this->actingAs($teams['waiter']['chro'])->get('/play')->assertRedirect('/play/replay');
    }

    public function test_threads_show_the_choice_and_what_came_back(): void
    {
        ['section' => $section, 'instructor' => $instructor, 'teams' => $teams] = $this->classOfFour();
        $section->update(['future' => 'rapid', 'open_through' => 2027]);
        $this->actingAs($teams['cutter']['chro'])->post('/play/2027/confirm', ReferenceTeams::cards('cutter')[2027])->assertRedirect();
        $this->actingAs($instructor)->get("/faculty/{$section->id}/2027")->assertInertia(fn (Assert $p) => $p
            ->where('teams.2.threads.0.id', 'offers')
            ->where('teams.2.threads.0.value', '81 offers to graduates')
            ->where('teams.2.threads.0.seen', null)
            ->where('teams.2.threads.1.id', 'plan')
            ->where('teams.2.threads.1.value', 'Took it as written')
            ->whereType('teams.2.threads.1.seen', 'string'));
    }

    public function test_an_instructor_reopens_one_teams_latest_year(): void
    {
        ['section' => $section, 'instructor' => $instructor, 'teams' => $teams] = $this->classOfFour();
        $section->update(['future' => 'rapid', 'open_through' => 2029]);
        $team = $teams['builder']['team'];
        $this->actingAs($teams['builder']['chro'])->post('/play/2027/confirm', ReferenceTeams::cards('builder')[2027])->assertRedirect();
        $this->post('/play/2029/confirm', ReferenceTeams::cards('builder')[2029])->assertRedirect();

        $this->actingAs($instructor)->post("/faculty/{$section->id}/teams/{$team->id}/years/2027/reopen")->assertSessionHasErrors('board');
        $this->post("/faculty/{$section->id}/teams/{$team->id}/years/2029/reopen")->assertRedirect();
        $this->assertNull(TeamYear::query()->where('team_id', $team->id)->where('year', 2029)->value('confirmed_at'));
        $this->actingAs($teams['builder']['chro'])->get('/play')->assertRedirect('/play/2029');
    }

    public function test_only_staff_of_the_class_see_its_board(): void
    {
        ['section' => $section, 'teams' => $teams] = $this->classOfFour();
        $this->actingAs($teams['builder']['chro'])->get('/faculty')->assertForbidden();
        $this->get("/faculty/{$section->id}")->assertForbidden();
        $other = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($other)->get("/faculty/{$section->id}")->assertForbidden();
        $this->post("/faculty/{$section->id}/future", ['future' => 'rapid'])->assertForbidden();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get("/faculty/{$section->id}")->assertOk();
    }

    public function test_the_sample_class_shows_what_participants_see(): void
    {
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $response = $this->actingAs($instructor)->post('/faculty/sample', ['future' => 'constrained']);
        $section = Section::query()->firstOrFail();
        $response->assertRedirect("/faculty/{$section->id}/2031");
        $this->get("/faculty/{$section->id}/2031")->assertInertia(fn (Assert $p) => $p
            ->where('section.future', 'constrained')
            ->has('teams', 4)
            ->where('teams.0.name', 'Builder')
            ->has('replay.teams', 4)
            ->where('replay.teams.2.judgment', fn ($j) => abs((float) $j - (float) EngineFixtureTest::fixture('cutter')['judgment']) < 1e-6));
        $team = Team::query()->where('section_id', $section->id)->firstOrFail();
        $this->get("/faculty/{$section->id}/teams/{$team->id}/years/2029")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('ashby/Play')
            ->where('viewOnly', true)
            ->where('confirmed', true)
            ->has('results.years', 2));
    }
}
