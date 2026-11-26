<?php

namespace Tests\Unit\Ashby;

use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use PHPUnit\Framework\TestCase;

/**
 * Holds the PHP engine to every value in economics/fixtures: four reference teams, three futures, nine years.
 * Numbers must agree to a relative 1e-6 or an absolute 1e-4; headcounts by level are rounded to one decimal.
 */
class EngineFixtureTest extends TestCase
{
    public const TEAMS = ['builder', 'middle', 'cutter', 'waiter'];

    public function test_the_engine_reproduces_every_fixture_value(): void
    {
        $engine = new AshbyEngine(new ModelData);
        $checked = 0;

        foreach (self::TEAMS as $team) {
            $fixture = self::fixture($team);
            foreach (ModelData::FUTURES as $future) {
                $rows = $engine->play(self::decisions($team), $future);
                $expectedRows = $fixture['futures'][$future]['rows'];
                $this->assertCount(count($expectedRows), $rows, "{$team} {$future} should play every year.");

                foreach ($expectedRows as $index => $expected) {
                    $year = $expected['year'];
                    $this->assertSame($this->sorted(array_keys($expected)), $this->sorted(array_keys($rows[$index])), "{$team} {$future} {$year} has different fields.");
                    foreach ($expected as $field => $value) {
                        $actual = $rows[$index][$field];
                        $checked++;
                        if (is_array($value)) {
                            $this->assertIsArray($actual);
                            foreach ($value as $i => $item) {
                                if (is_string($item)) {
                                    $this->assertSame($item, $actual[$i], "{$team} {$future} {$year} {$field}.");
                                } else {
                                    $this->assertEqualsWithDelta((float) $item, (float) $actual[$i], 0.051, "{$team} {$future} {$year} {$field}[{$i}].");
                                }
                            }
                        } elseif (is_string($value)) {
                            $this->assertSame($value, $actual, "{$team} {$future} {$year} {$field}.");
                        } else {
                            $difference = abs((float) $actual - (float) $value);
                            $this->assertTrue(
                                $difference <= 1e-4 || $difference <= 1e-6 * abs((float) $value),
                                "{$team} {$future} {$year} {$field}: expected {$value}, got {$actual}.",
                            );
                        }
                    }
                }

                foreach ($fixture['futures'][$future]['summary'] as $field => $value) {
                    $this->assertEqualsWithDelta($value, $engine->summary($rows)[$field], 1e-6, "{$team} {$future} summary {$field}.");
                }
            }
            $this->assertEqualsWithDelta($fixture['judgment'], $engine->judgment(self::decisions($team))['judgment'], 1e-6, "{$team} judgment.");
        }

        $this->assertSame(2808, $checked, 'The fixtures hold 2,808 values.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function decisions(string $team): array
    {
        return self::json('economics/teams/'.$team.'.json');
    }

    /**
     * @return array{team: string, judgment: float, futures: array<string, array{rows: list<array<string, mixed>>, summary: array<string, float>}>}
     */
    public static function fixture(string $team): array
    {
        return self::json('economics/fixtures/'.$team.'.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $path): array
    {
        $json = file_get_contents(dirname(__DIR__, 3).'/'.$path);

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<int|string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
