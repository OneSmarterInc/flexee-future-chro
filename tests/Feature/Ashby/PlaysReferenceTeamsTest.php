<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Unit\Ashby\EngineFixtureTest;

/**
 * PR 2's "done" test: each reference team plays 2027, 2029 and 2031 through the real routes,
 * and what the screens record gives back the golden fixtures and the judgment.
 */
class PlaysReferenceTeamsTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    public function test_each_reference_team_played_through_the_routes_matches_its_fixture(): void
    {
        $engine = new AshbyEngine(new ModelData);
        foreach (EngineFixtureTest::TEAMS as $name) {
            ['team' => $team, 'seats' => $seats] = $this->makeClass('rapid');
            $this->actingAs($seats['chro']);
            foreach (ReferenceTeams::cards($name) as $year => $cards) {
                $this->get("/play/{$year}")->assertOk();
                if ($year === 2027) {
                    $this->post('/play/2027/moment', ['text' => 'Our reasons.', 'offers' => $cards['offers']])->assertSessionHasNoErrors();
                }
                if ($year === 2029) {
                    $this->post('/play/2029/moment', ['text' => 'Our reasons.', 'planning' => $cards['planning']])->assertSessionHasNoErrors();
                }
                $this->post("/play/{$year}/confirm", $cards)->assertRedirect("/play/{$year}?step=4");
                $this->post("/play/{$year}/paragraph", ['paragraph' => "What we expect after {$year}."])->assertSessionHasNoErrors();
            }

            $fixture = EngineFixtureTest::fixture($name);
            $played = app(PlayState::class)->decisions(Team::query()->findOrFail($team->id));
            foreach (ModelData::FUTURES as $future) {
                $rows = $engine->play($played, $future);
                $expected = $fixture['futures'][$future]['rows'];
                $this->assertCount(count($expected), $rows);
                foreach ($expected as $i => $row) {
                    foreach (['profit', 'revenue', 'headcount', 'trust', 'strain', 'expert_bench', 'incidents'] as $field) {
                        $this->assertEqualsWithDelta((float) $row[$field], (float) $rows[$i][$field], max(1e-4, 1e-6 * abs((float) $row[$field])), "{$name} {$future} {$row['year']} {$field}.");
                    }
                }
            }

            $this->get('/play')->assertRedirect('/play/replay');
            $this->get('/play/replay')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('ashby/Replay')
                ->where('future', 'rapid')
                ->where('replay.judgment', fn ($j) => abs((float) $j - (float) $fixture['judgment']) < 1e-6)
                ->has('replay.futures', 3)
                ->has('replay.reference', 4)
                ->where('paragraphs.2031', 'What we expect after 2031.'));
        }
    }
}
