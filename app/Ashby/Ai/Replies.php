<?php

namespace App\Ashby\Ai;

use App\Ashby\Content\ContentPack;
use App\Ashby\Faculty\BoardView;
use App\Ashby\Game\PlayState;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\Team;
use App\Models\TeamYear;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The three AI texts besides the table: Daniel's reply to a team's paragraph (AI3), the class reading for a
 * closed year (AI4), and the debrief note after the replay (AI5). Each is checked by Writer before it is kept.
 */
final class Replies
{
    public function __construct(
        private readonly Writer $writer,
        private readonly ContentPack $content,
        private readonly PlayState $state,
        private readonly BoardView $board,
    ) {}

    /** Daniel's reply to the paragraph. Once per team per year; a dropped reply is not tried again. */
    public function paragraph(Team $team, TeamYear $row): TeamYear
    {
        if (! $this->writer->on($team->section, 'paragraph')) {
            throw new RuntimeException('ai_off');
        }
        $paragraph = trim((string) $row->paragraph);
        if ($paragraph === '' || $row->confirmed_at === null) {
            throw new RuntimeException('no_paragraph');
        }
        $claimed = DB::transaction(function () use ($row): bool {
            $locked = TeamYear::query()->lockForUpdate()->findOrFail($row->id);
            if ($locked->feedback !== null || $locked->feedback_note !== null) {
                return false;
            }
            $locked->update(['feedback_note' => 'Writing']);

            return true;
        });
        if (! $claimed) {
            throw new RuntimeException('already');
        }
        $spec = (array) $this->content->file('ai')['paragraph'];
        $rows = array_values(array_filter($this->state->rows($team, min($row->year + 1, PlayState::LAST_YEAR)), fn (array $r): bool => $r['year'] >= $row->year));
        $system = Writer::fill((string) $spec['system'], [
            'year' => $row->year,
            'decisions' => $this->decisions($row),
            'figures' => implode("\n", array_map(fn (array $r): string => Writer::figures($r), $rows)),
        ]);
        $result = $this->writer->write($system, [['role' => 'user', 'content' => $paragraph]], (int) $spec['max_tokens'], [], [$paragraph], (int) $spec['words_min'], (int) $spec['words_max']);
        $row->update(['feedback' => $result['text'], 'feedback_note' => $result['note']]);

        return $row;
    }

    /** The class reading for a year every team has confirmed. The instructor can ask again. */
    public function reading(Section $section, int $year): SectionYear
    {
        if (! $this->writer->on($section, 'reading')) {
            throw new RuntimeException('ai_off');
        }
        $teams = $this->board->teams($section, $year);
        if ($teams === [] || array_filter($teams, fn (array $t): bool => $t['status'] !== 'confirmed') !== []) {
            throw new RuntimeException('not_all_confirmed');
        }
        $spec = (array) $this->content->file('ai')['reading'];
        $panel = $this->board->panel($teams, $year);
        $lines = [];
        foreach ($teams as $t) {
            $team = Team::query()->whereKey($t['id'])->firstOrFail();
            $row = TeamYear::query()->where('team_id', $team->id)->where('year', $year)->firstOrFail();
            $rows = array_values(array_filter($this->state->rows($team, min($year + 1, PlayState::LAST_YEAR)), fn (array $r): bool => $r['year'] >= $year));
            $lines[] = $t['name'].': '.$this->decisions($row).' '.implode(' ', array_map(fn (array $r): string => Writer::figures($r), $rows));
        }
        $system = Writer::fill((string) $spec['system'], [
            'year' => $year,
            'panel' => 'Graduate intake in client work (% of usual): '.implode(', ', $panel['intake']).'. Workflows by way of working: '
                .implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($panel['modes']), $panel['modes'])).'. Teams with a workflow on AI alone: '.$panel['alone'].' of '.$panel['confirmed'].'.',
            'teams' => implode("\n", $lines),
        ]);
        $result = $this->writer->write($system, [['role' => 'user', 'content' => "Write the reading for {$year}."]], (int) $spec['max_tokens'], [], [], (int) $spec['words_min'], (int) $spec['words_max'], ReplyCheck::STAFF_NEVER, noAdvice: false);

        return SectionYear::query()->updateOrCreate(['section_id' => $section->id, 'year' => $year], ['reading' => $result['text'], 'reading_note' => $result['note']]);
    }

    /** The debrief note, once the replay is ready. The instructor can ask again. */
    public function debrief(Section $section): Section
    {
        if (! $this->writer->on($section, 'debrief')) {
            throw new RuntimeException('ai_off');
        }
        $replay = $this->board->replay($section);
        if ($replay === null || $section->future === null) {
            throw new RuntimeException('not_all_confirmed');
        }
        $spec = (array) $this->content->file('ai')['debrief'];
        $names = (array) $this->content->file('faculty')['reference_names'];
        $system = Writer::fill((string) $spec['system'], [
            'future' => $section->future,
            'teams' => implode("\n", array_map(fn (array $t): string => $t['name'].': '.implode(', ', array_map(fn ($f, $v) => "{$f} ".number_format((float) $v, 1), array_keys($t['futures']), $t['futures'])).'; judgment '.number_format((float) $t['judgment'], 1), $replay['teams'])),
            'reference' => implode(', ', array_map(fn ($k, $v) => ($names[$k] ?? $k).' '.number_format((float) $v, 1), array_keys($replay['reference']), $replay['reference'])),
        ]);
        $result = $this->writer->write($system, [['role' => 'user', 'content' => 'Write the debrief note.']], (int) $spec['max_tokens'], [], [], (int) $spec['words_min'], (int) $spec['words_max'], ReplyCheck::STAFF_NEVER, noAdvice: false);
        $section->update(['debrief' => $result['text'], 'debrief_note' => $result['note']]);

        return $section;
    }

    /** A team's cards for a year as plain sentences. */
    private function decisions(TeamYear $row): string
    {
        $c = $row->cards ?? [];
        $modes = (array) $this->content->file('faculty')['panel']['mode_names'];
        $counts = array_count_values(array_map('strval', (array) ($c['workflows'] ?? [])));
        $ways = implode(', ', array_map(fn ($m, $n) => ($modes[$m] ?? $m).": {$n} of 8 workflows", array_keys($counts), $counts));
        $intake = $row->year === 2027 ? round((float) ($c['offers'] ?? 230)).' graduate offers in client work (the usual is 230)' : round((float) ($c['delivery_intake'] ?? 100)).'% of the usual graduate intake in client work';

        return "{$ways}. {$intake}, ".round((float) ($c['ops_intake'] ?? 100)).'% in operations. AI tutoring for '.round((float) ($c['tutor'] ?? 0)).'% of analysts. '
            .(($c['transparency'] ?? false) ? 'Told people early about changes. ' : 'Did not tell people early. ')
            .(($c['share_gains'] ?? false) ? 'Shared gains with staff.' : 'Did not share gains with staff.');
    }
}
