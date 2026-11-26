<?php

namespace App\Ashby\Game;

use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use App\Models\Team;
use App\Models\TeamYear;

/**
 * Where a team stands: which decision years it can play, its saved cards, and what the engine says about them.
 * Each decision year runs two years (H10); the last runs on to 2035 for the replay.
 */
final class PlayState
{
    public const YEARS = [2027, 2029, 2031];

    public const LAST_YEAR = 2035;

    public function __construct(private readonly AshbyEngine $engine) {}

    /** The team's row for a decision year, made on first visit with the cards it carries forward. */
    public function yearFor(Team $team, int $year): TeamYear
    {
        $row = TeamYear::query()->where('team_id', $team->id)->where('year', $year)->first();
        if ($row !== null) {
            return $row;
        }
        $previous = TeamYear::query()->where('team_id', $team->id)->where('year', '<', $year)->whereNotNull('confirmed_at')->orderByDesc('year')->first();
        $cards = $previous->cards ?? Cards::defaults();
        foreach (['lay_delivery', 'lay_delivery_experienced', 'lay_ops', 'lay_ops_experienced'] as $key) {
            $cards[$key] = 0; // layoffs happen once, in the year they are chosen
        }

        return TeamYear::query()->create(['team_id' => $team->id, 'year' => $year, 'cards' => $cards, 'checks' => [], 'revealed' => []]);
    }

    /**
     * The decision years and whether each can be played or has been confirmed.
     *
     * @return list<array{year: int, open: bool, confirmed: bool}>
     */
    public function years(Team $team): array
    {
        $confirmed = $this->confirmedYears($team);
        $out = [];
        $previousDone = true;
        foreach (self::YEARS as $year) {
            $open = $previousDone && $team->section->future !== null && $year <= $team->section->open_through;
            $done = in_array($year, $confirmed, true);
            $out[] = ['year' => $year, 'open' => $open, 'confirmed' => $done];
            $previousDone = $done;
        }

        return $out;
    }

    /** @return list<int> */
    public function confirmedYears(Team $team): array
    {
        $years = TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->orderBy('year')->pluck('year')->all();

        return array_values(array_map(fn (mixed $y): int => (int) $y, $years));
    }

    /**
     * The engine's decisions from every confirmed year, keyed by year.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function decisions(Team $team): array
    {
        $out = [];
        $rows = TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->orderBy('year')->get();
        foreach ($rows as $row) {
            $out[(string) $row->year] = Cards::toEngine($row->cards ?? [], $row->year);
        }

        return $out;
    }

    /**
     * The engine's record up to a year, in the class's future.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Team $team, int $through): array
    {
        return $this->engine->play($this->decisions($team), (string) $team->section->future, $through);
    }

    /**
     * The team's decisions held to 2035 in all three futures, with each overall result and the judgment.
     *
     * @return array{futures: array<string, array{cum_profit_m: float, end_bench: float, end_trust: float, overall: float}>, judgment: float, reference: array<string, float>}
     */
    public function replay(Team $team): array
    {
        $decisions = $this->decisions($team);
        $futures = [];
        foreach (ModelData::FUTURES as $future) {
            $s = $this->engine->summary($this->engine->play($decisions, $future));
            $futures[$future] = ['cum_profit_m' => $s['cum_profit_m'], 'end_bench' => $s['end_bench'], 'end_trust' => $s['end_trust'], 'overall' => $s['overall']];
        }
        $reference = [];
        foreach (['builder', 'waiter', 'middle', 'cutter'] as $name) {
            $json = file_get_contents(base_path('economics/teams/'.$name.'.json'));
            /** @var array<string, array<string, mixed>> $teamDecisions */
            $teamDecisions = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
            $reference[$name] = $this->engine->judgment($teamDecisions)['judgment'];
        }

        return ['futures' => $futures, 'judgment' => min(array_column($futures, 'overall')), 'reference' => $reference];
    }
}
