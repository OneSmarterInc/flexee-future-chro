<?php

namespace App\Ashby\Faculty;

use App\Ashby\Content\ContentPack;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;

/**
 * What the board shows for one class and decision year: each team's row, the class-wide spread,
 * the threads coming back, and the replay once every team has confirmed 2031.
 */
final class BoardView
{
    public function __construct(
        private readonly PlayState $state,
        private readonly AshbyEngine $engine,
        private readonly ContentPack $content,
    ) {}

    /** @return list<array<string, mixed>> */
    public function teams(Section $section, int $year): array
    {
        $seats = $this->content->file('common')['seats'];
        $out = [];
        foreach ($section->teams()->with('members')->orderBy('id')->get() as $team) {
            /** @var TeamYear|null $row */
            $row = TeamYear::query()->where('team_id', $team->id)->where('year', $year)->first();
            $confirmedBy = null;
            $closedByStaff = false;
            if ($row?->confirmed_at !== null) {
                $member = $team->members->firstWhere('id', $row->confirmed_by);
                $seat = $member instanceof User ? (string) $member->pivot?->getAttribute('seat') : '';
                $closedByStaff = $seat === '';
                $confirmedBy = $seat === '' ? null : ($seats[$seat] ?? $seat);
            }
            $figures = null;
            if ($row?->confirmed_at !== null) {
                $rows = $this->state->rows($team, min($year + 1, PlayState::LAST_YEAR));
                $last = $rows[count($rows) - 1];
                $figures = ['profit' => $last['profit'], 'trust' => $last['trust'], 'strain' => $last['strain'], 'year' => $last['year']];
            }
            $out[] = [
                'id' => $team->id,
                'name' => $team->name,
                'members' => $team->members->map(fn (User $u): array => ['name' => $u->name, 'seat' => $seats[(string) $u->pivot?->getAttribute('seat')] ?? ''])->values()->all(),
                'status' => $row === null ? 'not_started' : ($row->confirmed_at === null ? 'at_table' : 'confirmed'),
                'confirmed_by' => $confirmedBy,
                'closed_by_staff' => $closedByStaff,
                'can_reopen' => $row?->confirmed_at !== null && (int) TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->max('year') === $year,
                'minutes' => $row->minutes_used ?? 0,
                'checks' => count($row->checks ?? []),
                'cards' => $row?->cards,
                'moment' => $row?->moment,
                'paragraph' => $row?->paragraph,
                'figures' => $figures,
                'threads' => $this->threads($team, $year),
            ];
        }

        return $out;
    }

    /**
     * The spread of choices among teams that have confirmed the year.
     *
     * @param  list<array<string, mixed>>  $teams
     * @return array{confirmed: int, intake: list<int>, modes: array<string, int>, alone: int, checked: int}
     */
    public function panel(array $teams, int $year): array
    {
        $intake = [];
        $modes = ['people' => 0, 'assist' => 0, 'draft_check' => 0, 'alone' => 0];
        $alone = 0;
        $checked = 0;
        $confirmed = 0;
        foreach ($teams as $team) {
            if ($team['status'] !== 'confirmed' || ! is_array($team['cards'])) {
                continue;
            }
            $confirmed++;
            $cards = $team['cards'];
            $intake[] = (int) round($year === 2027 ? (float) $cards['offers'] / 2.3 : (float) $cards['delivery_intake']);
            $any = false;
            foreach ((array) $cards['workflows'] as $mode) {
                $modes[(string) $mode] = ($modes[(string) $mode] ?? 0) + 1;
                $any = $any || $mode === 'alone';
            }
            $alone += $any ? 1 : 0;
            $checked += $team['checks'] > 0 ? 1 : 0;
        }
        sort($intake);

        return ['confirmed' => $confirmed, 'intake' => $intake, 'modes' => $modes, 'alone' => $alone, 'checked' => $checked];
    }

    /**
     * The threads that have started by this year, with the team's choice and, once the engine has run it, what came back.
     *
     * @return list<array{id: string, from: int, returns: int, value: string, seen: string|null}>
     */
    public function threads(Team $team, int $year): array
    {
        $words = $this->content->file('faculty')['threads'];
        $v = $words['values'];
        $cards = [];
        foreach (TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->get() as $row) {
            $cards[$row->year] = $row->cards ?? [];
        }
        if ($cards === []) {
            return [];
        }
        $latest = max(array_keys($cards));
        $through = $latest === 2031 ? PlayState::LAST_YEAR : $latest + 1;
        $rows = [];
        foreach ($this->state->rows($team, $through) as $r) {
            $rows[(int) $r['year']] = $r;
        }
        $checks2027 = count(TeamYear::query()->where('team_id', $team->id)->where('year', 2027)->value('checks') ?? []);
        $held = fn (int $upTo): array => $cards[max(array_filter(array_keys($cards), fn (int $y): bool => $y <= $upTo) ?: [2027])] ?? [];
        $fill = fn (string $text, array $vars): string => (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => (string) ($vars[$m[1]] ?? $m[0]), $text);
        $note = function (int $y, string $starts) use ($rows): ?int {
            foreach ((array) ($rows[$y]['notes'] ?? []) as $n) {
                if (str_starts_with((string) $n, $starts) && preg_match('/(\d+)/', (string) $n, $m) === 1) {
                    return (int) $m[1];
                }
            }

            return null;
        };

        $out = [];
        foreach ($words['list'] as $thread) {
            $from = (int) $thread['from'];
            if ($from > $year || ! isset($cards[$from])) {
                continue;
            }
            $c = $held($from);
            $returns = (int) $thread['returns'];
            $r = $rows[$returns] ?? null;
            [$value, $seen] = match ($thread['id']) {
                'offers' => [$fill($v['offers'], ['offers' => (int) round((float) ($cards[2027]['offers'] ?? 230))]),
                    $r ? $fill($v['offers_seen'], ['seniors' => (int) round((float) $r['delivery_levels'][1])]) : null],
                'plan' => [$checks2027 > 0 ? $fill($v['plan_checked'], ['count' => $checks2027]) : $v['plan_taken'],
                    $r ? $fill($v['plan_seen'], ['strain' => (int) round((float) $r['strain'])]) : null],
                'outage' => [$fill($v['outage'], ['oversight' => $v['oversight'][(string) $c['oversight']] ?? '', 'alone' => count(array_filter((array) $c['workflows'], fn ($m) => $m === 'alone'))]),
                    $r ? $fill($v['outage_seen'], ['amount' => $note($returns, 'Outage') ?? 0]) : null],
                'trust' => [$c['transparency'] ? $v['trust_yes'] : $v['trust_no'],
                    $r ? $fill($v['trust_seen'], ['trust' => (int) round((float) $r['trust'])]) : null],
                'knowledge' => [$fill($v['knowledge'], ['where' => $v['where'][(string) $c['knowledge']] ?? '']),
                    $r ? (($n = $note($returns, 'Vendor')) !== null ? $fill($v['knowledge_seen'], ['amount' => $n]) : $v['knowledge_none']) : null],
                'wall' => [$fill($v['wall'], ['drafts' => count(array_filter((array) $c['workflows'], fn ($m) => $m === 'draft_check')), 'checking' => $c['checking'] ? $v['yes'] : $v['no']]),
                    $r ? $fill($v['wall_seen'], ['pct' => (int) round(100 * (float) $r['wall_ratio'])]) : null],
                default => ['', null],
            };
            $out[] = ['id' => (string) $thread['id'], 'from' => $from, 'returns' => $returns, 'value' => $value, 'seen' => $seen];
        }

        return $out;
    }

    /**
     * Every team's replay once all have confirmed 2031, with the reference teams for comparison.
     *
     * @return array{teams: list<array<string, mixed>>, reference: array<string, float>}|null
     */
    public function replay(Section $section): ?array
    {
        $teams = $section->teams()->orderBy('id')->get();
        if ($teams->isEmpty()) {
            return null;
        }
        $out = [];
        foreach ($teams as $team) {
            if (! in_array(2031, $this->state->confirmedYears($team), true)) {
                return null;
            }
            $replay = $this->state->replay($team);
            $out[] = ['id' => $team->id, 'name' => $team->name, 'futures' => array_map(fn (array $f): float => $f['overall'], $replay['futures']), 'judgment' => $replay['judgment']];
        }
        $reference = [];
        foreach (ReferenceTeams::NAMES as $name) {
            $reference[$name] = $this->engine->judgment(ReferenceTeams::decisions($name))['judgment'];
        }

        return ['teams' => $out, 'reference' => $reference];
    }
}
