<?php

namespace App\Ashby\Verify;

use App\Ashby\Ai\Writer;
use App\Ashby\Content\ContentPack;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use App\Ashby\Faculty\Board;
use App\Ashby\Game\Cards;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\ReferenceTeams;
use App\Ashby\Help\Manual;
use App\Ashby\Mail\Outgoing;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The checks whoever releases runs on the server (`ashby:verify`), each printed with what was expected and what came
 * out. With the database option, a throwaway class runs through the real pipeline on this database and is rolled back.
 */
final class Battery
{
    /** @var list<array{group: string, name: string, expected: string, actual: string, pass: bool}> */
    private array $out = [];

    public function __construct(
        private readonly AshbyEngine $engine,
        private readonly PlayState $state,
        private readonly Board $board,
        private readonly ContentPack $content,
        private readonly Manual $manual,
        private readonly Writer $writer,
    ) {}

    /** @return list<array{group: string, name: string, expected: string, actual: string, pass: bool}> */
    public function run(bool $database): array
    {
        $this->out = [];
        $this->engineChecks();
        $this->lessons();
        $this->words();
        if ($database) {
            $this->pipeline();
        }
        $this->site();

        return $this->out;
    }

    private function engineChecks(): void
    {
        foreach (ReferenceTeams::NAMES as $name) {
            $fixture = $this->fixture($name);
            $decisions = ReferenceTeams::decisions($name);
            $worst = 0.0;
            $values = 0;
            foreach (ModelData::FUTURES as $future) {
                $rows = $this->engine->play($decisions, $future);
                foreach ((array) $fixture['futures'][$future]['rows'] as $i => $row) {
                    foreach ((array) $row as $field => $value) {
                        if (is_int($value) || is_float($value)) {
                            $values++;
                            $gap = abs((float) $rows[$i][$field] - (float) $value);
                            $worst = max($worst, $gap > 1e-4 ? $gap / max(1.0, abs((float) $value)) : 0.0);
                        }
                    }
                }
            }
            $this->add('The engine', "{$name}: every fixture value in three futures", 'gap under 1e-6', sprintf('%d values, worst gap %.1e', $values, $worst), $worst < 1e-6);
            $j = $this->engine->judgment($decisions)['judgment'];
            $this->add('The engine', "{$name}: judgment", number_format((float) $fixture['judgment'], 1), number_format($j, 1), abs($j - (float) $fixture['judgment']) < 1e-6);
        }
    }

    private function lessons(): void
    {
        $J = [];
        foreach (ReferenceTeams::NAMES as $name) {
            $J[$name] = $this->engine->judgment(ReferenceTeams::decisions($name))['judgment'];
        }
        arsort($J);
        $this->add('The lessons', 'The builder has the best worst case across the three futures', 'builder first', array_key_first($J).' first', array_key_first($J) === 'builder');

        $year = fn (string $n, string $f, int $y): array => $this->engine->play(ReferenceTeams::decisions($n), $f)[$y - 2027];
        $cut = $year('cutter', 'rapid', 2028)['profit'];
        $this->add('The lessons', 'Cutting pays first when AI arrives fast: the cutter out-earns the builder in 2028 (rapid)', 'cutter ahead',
            sprintf('cutter %s, builder %s', Writer::money((float) $cut), Writer::money((float) $year('builder', 'rapid', 2028)['profit'])), $cut > $year('builder', 'rapid', 2028)['profit']);

        $bench = fn (string $n, string $f): float => (float) $this->engine->summary($this->engine->play(ReferenceTeams::decisions($n), $f))['end_bench'];
        $this->add('The lessons', "...and costs later: by 2035 the cutter's pipeline is under half the builder's (complement)", 'under half',
            sprintf('%.2f against %.2f', $bench('cutter', 'complement'), $bench('builder', 'complement')), $bench('cutter', 'complement') < 0.5 * $bench('builder', 'complement'));

        $wall = null;
        foreach ($this->engine->play(ReferenceTeams::decisions('builder'), 'rapid') as $row) {
            if ($wall === null && $row['wall_ratio'] > 1) {
                $wall = (int) $row['year'];
            }
        }
        $this->add('The lessons', 'The verification wall arrives in the rapid future for the builder, after 2031', '2032 or 2033', (string) ($wall ?? 'never'), $wall !== null && $wall > 2031);

        $late = function (string $terms): float {
            $d = ReferenceTeams::decisions('middle');
            $d['2032'] = ['ruth' => $terms];

            return array_sum(array_map(fn (array $r): float => $r['year'] >= 2032 ? (float) $r['profit'] : 0.0, $this->engine->play($d, 'complement')));
        };
        $this->add('The lessons', 'Letting Ruth go costs more than keeping her (middle path, complement, 2032 to 2035)', 'stay ahead',
            sprintf('stay %s, go %s', Writer::money($late('stay')), Writer::money($late('go'))), $late('stay') > $late('go'));
    }

    private function words(): void
    {
        $found = 0;
        foreach ($this->content->strings() as $text) {
            foreach (ContentPack::BANNED as $word) {
                $found += preg_match('/\b'.preg_quote($word, '/').'\b/i', $text);
            }
        }
        $this->add('Words', 'No banned word on any participant screen', '0', (string) $found, $found === 0);
        $problems = $this->manual->problems();
        $this->add('Words', 'The manuals agree with the product', '0 problems', count($problems).' problems'.($problems === [] ? '' : ': '.$problems[0]), $problems === []);
        $this->add('Words', 'The What changed log is current', 'current', $this->manual->logIsCurrent() ? 'current' : 'out of date', $this->manual->logIsCurrent());
    }

    /** A throwaway class of four reference teams, played through the real pipeline on this database and rolled back. */
    private function pipeline(): void
    {
        try {
            DB::beginTransaction();
            $tag = Str::lower(Str::random(6));
            $instructor = User::query()->create(['name' => 'Verify', 'email' => "verify-{$tag}@verify.invalid", 'password' => Str::password(32), 'role' => User::ROLE_FACULTY]);
            $section = Section::query()->create(['name' => "Verify {$tag}", 'length' => 'slice', 'timezone' => 'UTC']);
            $section->staff()->attach($instructor->id, ['role' => 'owner']);
            $teams = [];
            foreach (ReferenceTeams::NAMES as $name) {
                $teams[$name] = Team::query()->create(['section_id' => $section->id, 'name' => $name]);
            }
            $section = $section->fresh() ?? $section;
            $this->board->setFuture($section, 'complement');
            foreach ($section->years() as $year) {
                $this->board->open($section, $year);
                foreach ($teams as $name => $team) {
                    if ($name === 'waiter') {
                        continue; // the waiter never confirms: closing the year confirms it with what it saved
                    }
                    $row = $this->state->yearFor($team, $year);
                    $row->cards = array_merge(Cards::defaults(), ReferenceTeams::cards($name)[$year]);
                    $row->confirmed_at = now();
                    $row->save();
                }
                $this->board->close($section, $year, $instructor);
            }
            $confirmed = TeamYear::query()->whereIn('team_id', array_map(fn (Team $t): int => $t->id, $teams))->whereNotNull('confirmed_at')->count();
            $this->add('The pipeline (rolled back)', 'Every team has a confirmed row for each year', (string) (4 * count($section->years())), (string) $confirmed, $confirmed === 4 * count($section->years()));
            foreach ($teams as $name => $team) {
                $j = $this->state->replay($team->fresh() ?? $team)['judgment'];
                $expected = (float) $this->fixture($name)['judgment'];
                $this->add('The pipeline (rolled back)', "{$name}: the replay's judgment", number_format($expected, 1), number_format($j, 1), abs($j - $expected) < 1e-6);
            }
        } catch (Throwable $e) {
            $this->add('The pipeline (rolled back)', 'The throwaway class ran', 'no error', mb_substr($e->getMessage(), 0, 160), false);
        } finally {
            DB::rollBack();
        }
        $left = Section::query()->where('name', 'like', 'Verify %')->count();
        $this->add('The pipeline (rolled back)', 'Nothing was left behind', '0 classes', "{$left} classes", $left === 0);
    }

    private function site(): void
    {
        $this->add('The site (for information)', 'AI features', 'on or off', $this->writer->available() ? 'on' : 'off: fixed replies only', true);
        $this->add('The site (for information)', 'Outgoing mail', 'on or off', Outgoing::enabled() ? 'on' : 'off: the roster says so', true);
        $this->add('The site (for information)', 'Reporting connector', 'on or off', config('ashby.reporting.enabled') ? 'on' : 'off', true);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $json = file_get_contents(base_path('economics/fixtures/'.$name.'.json'));
        if ($json === false) {
            throw new RuntimeException("No fixture for {$name}.");
        }
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $fixture;
    }

    private function add(string $group, string $name, string $expected, string $actual, bool $pass): void
    {
        $this->out[] = ['group' => $group, 'name' => $name, 'expected' => $expected, 'actual' => $actual, 'pass' => $pass];
    }
}
