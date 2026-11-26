<?php

namespace Tests\Unit\Ashby;

use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use PHPUnit\Framework\TestCase;

/**
 * The lessons the engine must teach, in plain English. These mirror economics/check.py
 * and run on the PHP engine, so a port that drifts from the model fails here by name.
 */
class EconomicRulesTest extends TestCase
{
    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private static array $plays = [];

    private static AshbyEngine $engine;

    public static function setUpBeforeClass(): void
    {
        self::$engine = new AshbyEngine(new ModelData);
        foreach (EngineFixtureTest::TEAMS as $team) {
            foreach (ModelData::FUTURES as $future) {
                self::$plays[$team][$future] = self::$engine->play(EngineFixtureTest::decisions($team), $future);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function year(string $team, string $future, int $year): array
    {
        foreach (self::$plays[$team][$future] as $row) {
            if ($row['year'] === $year) {
                return $row;
            }
        }
        self::fail("No {$year} for {$team} {$future}.");
    }

    private static function cumulative(string $team, string $future): float
    {
        return self::$engine->summary(self::$plays[$team][$future])['cum_profit_m'];
    }

    public function test_the_firm_as_you_find_it_earns_a_healthy_margin_in_2027(): void
    {
        $row = self::year('waiter', 'complement', 2027);
        $margin = $row['profit'] / $row['revenue'];
        $this->assertGreaterThanOrEqual(0.18, $margin);
        $this->assertLessThanOrEqual(0.32, $margin);
        $this->assertGreaterThan(0.98, $row['met_demand']);
    }

    public function test_doing_nothing_gets_squeezed_when_ai_moves_fast(): void
    {
        $this->assertLessThan(0, self::year('waiter', 'rapid', 2035)['profit']);
    }

    public function test_cutting_pays_in_2028_when_ai_arrives_fast(): void
    {
        foreach (['builder', 'middle'] as $team) {
            $this->assertGreaterThan(self::year($team, 'rapid', 2028)['profit'], self::year('cutter', 'rapid', 2028)['profit'], $team);
        }
    }

    public function test_cutting_ahead_of_slow_ai_leaves_the_firm_short_of_people(): void
    {
        foreach (['complement', 'constrained'] as $future) {
            $this->assertLessThan(0.6, self::year('cutter', $future, 2033)['met_demand'], $future);
        }
    }

    public function test_no_single_plan_wins_in_every_future(): void
    {
        $winners = [];
        foreach (ModelData::FUTURES as $future) {
            $best = null;
            $bestOverall = -INF;
            foreach (EngineFixtureTest::TEAMS as $team) {
                $overall = self::$engine->summary(self::$plays[$team][$future])['overall'];
                if ($overall > $bestOverall) {
                    [$best, $bestOverall] = [$team, $overall];
                }
            }
            $winners[$future] = $best;
        }
        $this->assertGreaterThan(1, count(array_unique($winners)));
    }

    public function test_the_builder_shows_the_best_judgment(): void
    {
        $judgments = [];
        foreach (EngineFixtureTest::TEAMS as $team) {
            $judgments[$team] = self::$engine->judgment(EngineFixtureTest::decisions($team))['judgment'];
        }
        arsort($judgments);
        $this->assertSame('builder', array_key_first($judgments));
    }

    public function test_cutting_is_not_simply_wrong_when_ai_moves_fast(): void
    {
        foreach (['middle', 'waiter'] as $team) {
            $this->assertGreaterThan(self::cumulative($team, 'rapid'), self::cumulative('cutter', 'rapid'), $team);
        }
    }

    public function test_checking_ai_is_hidden_work(): void
    {
        $row = self::year('middle', 'complement', 2028);
        $this->assertGreaterThanOrEqual(0.10 * $row['ai_units'], $row['review_units']);
    }

    public function test_review_load_wears_people_down(): void
    {
        foreach (ModelData::FUTURES as $future) {
            $this->assertGreaterThan(self::year('builder', $future, 2029)['strain'], self::year('middle', $future, 2029)['strain'], $future);
        }
    }

    public function test_knowledge_in_a_firm_owned_system_outlasts_knowledge_in_people(): void
    {
        foreach (ModelData::FUTURES as $future) {
            $this->assertGreaterThan(self::year('middle', $future, 2035)['knowledge'], self::year('builder', $future, 2035)['knowledge'], $future);
        }
    }

    public function test_the_verification_wall_comes_only_after_2031(): void
    {
        foreach (EngineFixtureTest::TEAMS as $team) {
            foreach (ModelData::FUTURES as $future) {
                foreach (self::$plays[$team][$future] as $row) {
                    if ($row['year'] <= 2031) {
                        $this->assertLessThanOrEqual(1.0, $row['wall_ratio'], "{$team} {$future} {$row['year']}");
                    }
                }
            }
        }
    }

    public function test_headcount_never_goes_negative(): void
    {
        foreach (self::$plays as $team => $futures) {
            foreach ($futures as $future => $rows) {
                foreach ($rows as $row) {
                    foreach (array_merge($row['delivery_levels'], $row['operations_levels']) as $people) {
                        $this->assertGreaterThanOrEqual(0, $people, "{$team} {$future} {$row['year']}");
                    }
                }
            }
        }
    }
}
