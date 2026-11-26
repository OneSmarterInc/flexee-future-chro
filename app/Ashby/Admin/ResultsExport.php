<?php

namespace App\Ashby\Admin;

use App\Ashby\Content\ContentPack;
use App\Ashby\Game\PlayState;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;

/**
 * The class's two spreadsheets: results by team and year, and the gradebook by participant.
 */
final class ResultsExport
{
    public function __construct(private readonly PlayState $state, private readonly ContentPack $content) {}

    /** @return list<list<string|int|float>> */
    public function results(Section $section): array
    {
        $rows = [['Team', 'Year', 'Decision year', 'Confirmed by', 'Table minutes', 'Plan checks', 'Revenue $M', 'Profit $M', 'People',
            'Trust', 'Strain', 'Errors reaching clients', 'Senior bench', 'Paragraph words', 'Events']];
        $seats = (array) $this->content->file('common')['seats'];
        foreach ($section->teams()->with('members')->orderBy('id')->get() as $team) {
            $years = TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->orderBy('year')->get()->keyBy('year');
            if ($years->isEmpty() || $section->future === null) {
                continue;
            }
            $latest = (int) $years->keys()->max();
            foreach ($this->state->rows($team, $latest === 2031 ? PlayState::LAST_YEAR : $latest + 1) as $r) {
                $y = (int) $r['year'];
                $decision = $y >= 2031 ? 2031 : ($y % 2 === 1 ? $y : $y - 1);
                /** @var TeamYear|null $record */
                $record = $years->get($decision);
                $first = $y === $decision;
                $rows[] = [
                    $team->name, $y, $decision,
                    $first ? $this->seatOf($team, $record?->confirmed_by, $seats) : '',
                    $first ? (int) ($record->minutes_used ?? 0) : '',
                    $first ? count($record->checks ?? []) : '',
                    round((float) $r['revenue'] / 1e6, 2), round((float) $r['profit'] / 1e6, 2), round((float) $r['headcount'], 1),
                    round((float) $r['trust'], 1), round((float) $r['strain'], 1), round((float) $r['incidents'], 2), round((float) $r['expert_bench'], 3),
                    $first ? str_word_count((string) ($record->paragraph ?? '')) : '',
                    implode('; ', array_map(fn ($n): string => (string) $n, (array) $r['notes'])),
                ];
            }
        }

        return $rows;
    }

    /** @return list<list<string|int|float>> */
    public function gradebook(Section $section): array
    {
        $rows = [['Name', 'Email', 'Team', 'Seat', 'Access', 'Paid', 'Years confirmed (of 3)', 'Paragraphs written', 'Overall in this future', 'Judgment (worst of three futures)']];
        $seats = (array) $this->content->file('common')['seats'];
        $replays = [];
        foreach ($section->enrolments()->with('user')->get()->sortBy(fn (Enrolment $e) => $e->user->name) as $enrolment) {
            $team = $enrolment->user->teams()->where('teams.section_id', $section->id)->first();
            $confirmed = $team === null ? [] : $this->state->confirmedYears($team);
            $replay = null;
            if ($team !== null && in_array(2031, $confirmed, true) && $section->future !== null) {
                $replay = $replays[$team->id] ??= $this->state->replay($team);
            }
            $seat = $team === null ? '' : (string) $team->members()->where('users.id', $enrolment->user_id)->first()?->pivot?->getAttribute('seat');
            $rows[] = [
                $enrolment->user->name, $enrolment->user->email, $team->name ?? '', (string) ($seats[$seat] ?? ''), $enrolment->status,
                $enrolment->paid_at === null ? 'no' : 'yes', count($confirmed),
                $team === null ? 0 : TeamYear::query()->where('team_id', $team->id)->whereNotNull('paragraph')->where('paragraph', '!=', '')->count(),
                $replay === null ? '' : round($replay['futures'][(string) $section->future]['overall'], 1),
                $replay === null ? '' : round($replay['judgment'], 1),
            ];
        }

        return $rows;
    }

    /**
     * Rows as CSV text. A cell that a spreadsheet would run as a formula is made plain text.
     *
     * @param  list<list<string|int|float>>  $rows
     */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => is_string($cell) && $cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) ? "'".$cell : $cell, $row), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @param  array<mixed>  $seats */
    private function seatOf(Team $team, ?int $userId, array $seats): string
    {
        $member = $team->members->firstWhere('id', $userId);
        $seat = $member === null ? '' : (string) $member->pivot?->getAttribute('seat');

        return $seat === '' ? 'Closed by the instructor' : (string) ($seats[$seat] ?? $seat);
    }
}
