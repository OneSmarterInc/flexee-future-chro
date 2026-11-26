<?php

namespace App\Ashby\Ai;

use App\Ashby\Content\ContentPack;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\TableTalk;
use App\Models\TableMessage;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A question to one person at the table. Code decides what the person may say: a held-back fact is given to
 * the writer only when the question meets its trigger, so it can't come out early. The fixed reply is saved
 * first, so the team always gets an answer; an AI answer replaces it only when it passes every check (AI1).
 */
final class TableVoices
{
    public function __construct(private readonly Writer $writer, private readonly ContentPack $content, private readonly PlayState $state) {}

    public function ask(Team $team, TeamYear $row, User $user, string $person, string $question): TableMessage
    {
        $years = $this->content->file('years');
        /** @var array<string, mixed> $talk */
        $talk = (array) $years['talk'][(string) $row->year][$person];
        $question = trim($question);

        // The limits are held inside a lock, so two teammates can't both take the last minute.
        [$minutes] = TableTalk::time($years, $row->year);
        [$message, $revealedNow, $revealed] = DB::transaction(function () use ($row, $user, $person, $question, $talk, $minutes): array {
            $locked = TeamYear::query()->lockForUpdate()->findOrFail($row->id);
            $asked = TableMessage::query()->where('team_id', $locked->team_id)->where('year', $locked->year)->where('person', $person)->count();
            if ($asked >= TableTalk::QUESTIONS_PER_PERSON || $locked->minutes_used + 1 > $minutes) {
                throw new RuntimeException('no_time');
            }
            $fixed = TableTalk::answer($talk, $question, $locked->revealed ?? [], $asked);
            $message = TableMessage::query()->create([
                'team_id' => $locked->team_id, 'user_id' => $user->id, 'year' => $locked->year, 'person' => $person,
                'question' => $question, 'answer' => $fixed['answer'], 'revealed' => $fixed['revealed'], 'source' => 'fixed',
            ]);
            $locked->minutes_used += 1;
            if ($fixed['revealed'] !== null) {
                $locked->revealed = array_merge($locked->revealed ?? [], [$fixed['revealed']]);
            }
            $locked->save();

            return [$message, $fixed['revealed'], $locked->revealed ?? []];
        });

        if (! $this->writer->on($team->section, 'table')) {
            return $message;
        }

        $spec = (array) $this->content->file('ai')['voices'];
        $character = (array) $spec['characters'][$person];
        $facts = [(string) $talk['opens'], ...array_map('strval', (array) $talk['says'])];
        $mustSay = null;
        foreach ((array) ($talk['holds_back'] ?? []) as $held) {
            if (in_array($held['id'], $revealed, true)) {
                $facts[] = (string) $held['says'];
            }
            if ($held['id'] === $revealedNow) {
                $mustSay = (string) $held['says'];
            }
        }
        $system = Writer::fill((string) $spec['rules'], [
            'name' => (string) $years['people'][$person]['name'], 'role' => (string) $years['people'][$person]['role'], 'year' => $row->year,
            'who' => (string) $character['who'], 'wants' => (string) $character['wants'], 'manner' => (string) $character['manner'],
            'facts' => implode("\n", $facts), 'figures' => $this->figures($team, $row->year, $years), 'table' => $this->table($row, $years),
        ]);
        if ($mustSay !== null) {
            $system .= Writer::fill((string) $spec['must_say'], ['fact' => $mustSay]);
        }

        $messages = [];
        $teamText = [$question];
        foreach (TableMessage::query()->where('team_id', $team->id)->where('year', $row->year)->where('person', $person)->where('id', '<', $message->id)->orderBy('id')->get() as $earlier) {
            $messages[] = ['role' => 'user', 'content' => $earlier->question];
            $messages[] = ['role' => 'assistant', 'content' => $earlier->answer];
            $teamText[] = $earlier->question;
        }
        // The question goes to the writer whole. Nothing a team wrote is cut before it is read.
        $messages[] = ['role' => 'user', 'content' => $question];

        $result = $this->writer->write($system, $messages, (int) $spec['max_tokens'], [], $teamText, (int) $spec['words_min'], (int) $spec['words_max'],
            extra: fn (string $text): ?string => $mustSay !== null && ($missing = ReplyCheck::missingFrom($text, $mustSay)) !== null ? "Left out: {$missing}." : null);

        $message->update([
            'answer' => $result['text'] ?? $message->answer,
            'source' => $result['text'] === null ? 'fixed' : 'ai',
            'dropped_reason' => $result['note'],
            'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'model' => $result['model'],
        ]);

        return $message;
    }

    /** @param  array<string, mixed>  $years */
    private function figures(Team $team, int $year, array $years): string
    {
        if ($year === 2027) {
            $brief = (array) $years['briefs']['2027'];

            return implode("\n", array_map(fn (array $f): string => $f[1].': '.$f[0], (array) ($brief['figures'] ?? [])));
        }
        $rows = $this->state->rows($team, $year - 1);

        return implode("\n", array_map(fn (array $r): string => Writer::figures($r), array_slice($rows, -2)));
    }

    /** @param  array<string, mixed>  $years */
    private function table(TeamYear $row, array $years): string
    {
        $brief = (array) $years['briefs'][(string) $row->year];
        $plan = (array) $years['plans'][(string) $row->year];
        $lines = [(string) $brief['title'], ...array_map('strval', (array) $brief['paragraphs'])];
        $lines[] = (string) $plan['title'].': '.str_replace('{figure}', (string) $plan['figure'], (string) $plan['claim']);
        foreach ((array) $plan['checks'] as $check) {
            if (in_array($check['id'], $row->checks ?? [], true)) {
                $lines[] = 'The team checked this: '.$check['found'];
            }
        }

        return implode("\n", $lines);
    }
}
