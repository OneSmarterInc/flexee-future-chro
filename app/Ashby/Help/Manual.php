<?php

namespace App\Ashby\Help;

use App\Ashby\Admin\Calendar;
use App\Ashby\Admin\Roster;
use App\Ashby\Ai\Writer;
use App\Ashby\Content\ContentPack;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\ReferenceTeams;
use App\Ashby\Game\TableTalk;
use App\Http\Controllers\Ashby\FacultyController;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The two manuals that can't fall behind (H2). Facts come from the product; the words come from one file in the
 * content pack; problems() is the check that fails the build when the two drift apart. The participant manual holds
 * the sections marked for participants; the instructor manual holds those and its own, with a teaching note per year.
 */
final class Manual
{
    /** The five steps of a year, in the order common.json lists them. */
    public const STEPS = ['brief', 'table', 'moment', 'cards', 'results'];

    /** Instructor pages and the route each must still have. */
    private const PAGES = ['board' => 'faculty.board', 'roster' => 'roster.show', 'admin' => 'admin', 'results-csv' => 'roster.export', 'gradebook-csv' => 'roster.export', 'sample' => 'faculty.sample'];

    /** Board buttons and their routes. */
    private const BUTTONS = ['future' => 'faculty.future', 'open' => 'faculty.open', 'close' => 'faculty.close', 'reopen' => 'faculty.reopen'];

    /** Things the manual covers that are ways of working, not rows in a table. */
    private const WAYS = ['calendar', 'length', 'replay', 'join'];

    private const WORDS = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];

    /** @var array<string, string>|null */
    private ?array $facts = null;

    public function __construct(private readonly ContentPack $content, private readonly AshbyEngine $engine) {}

    /**
     * The tokens a note may use, each read from where the product keeps it.
     *
     * @return array<string, string>
     */
    public function facts(): array
    {
        if ($this->facts !== null) {
            return $this->facts;
        }
        $years = $this->content->file('years');
        [$shortTable, $shortChecking] = TableTalk::time($years, 2028);
        $board = (array) $this->content->file('faculty')['year'];
        $teams = (array) $this->content->file('faculty')['teams'];
        $facts = [
            'questions' => $this->word(TableTalk::QUESTIONS_PER_PERSON),
            'table_minutes' => (string) TableTalk::MINUTES,
            'checking_minutes' => (string) TableTalk::CHECKING_MINUTES,
            'short_table' => (string) $shortTable,
            'short_checking' => (string) $shortChecking,
            'year_minutes' => (string) Calendar::FULL_OPEN_MINUTES,
            'handover_words' => (string) PlayState::HANDOVER_WORDS,
            'workshop_gap' => (string) Calendar::WORKSHOP_GAP_MINUTES,
            'full_gap' => (string) Calendar::FULL_GAP_MINUTES,
            'term_days' => $this->word(Calendar::TERM_OPEN_DAYS),
            'team_size' => $this->word(Roster::TEAM_SIZE),
            'team_min' => $this->word(Roster::SMALLEST_TEAM),
            'team_max' => $this->word(Roster::LARGEST_TEAM),
            'label_open' => str_replace('{year}', 'a year', (string) $board['open_button']),
            'label_close' => str_replace('{year}', 'a year', (string) $board['close_button']),
            'label_reopen' => str_replace('{year}', 'a year', (string) $teams['reopen']),
        ];
        foreach (ReferenceTeams::NAMES as $name) {
            $decisions = ReferenceTeams::decisions($name);
            $facts['judgment_'.$name] = number_format($this->engine->judgment($decisions)['judgment'], 1);
            foreach ($this->engine->play($decisions, 'complement') as $row) {
                $facts['profit_'.$name.'_'.$row['year']] = Writer::money((float) $row['profit']);
            }
        }

        return $this->facts = $facts;
    }

    /**
     * The sections a reader sees, in order, with every token filled.
     *
     * @return list<array{id: string, title: string, for: string, body: list<string|array{title: string, list: list<string>}>}>
     */
    public function sections(string $audience): array
    {
        $out = [];
        foreach ($this->notes()['sections'] as $note) {
            if ($audience === 'participant' && $note['for'] !== 'participant') {
                continue;
            }
            $body = [];
            foreach ((array) $note['body'] as $item) {
                $body[] = is_array($item)
                    ? ['title' => $this->fill((string) $item['title']), 'list' => array_values(array_map(fn ($l): string => $this->fill((string) $l), (array) $item['list']))]
                    : $this->fill((string) $item);
            }
            $out[] = ['id' => (string) $note['id'], 'title' => $this->fill((string) $note['title']), 'for' => (string) $note['for'], 'body' => $body];
        }

        return $out;
    }

    /** @return list<array{q: string, a: string}> */
    public function questions(string $audience): array
    {
        $out = [];
        foreach ($this->notes()['faq'] as $item) {
            if ($audience === 'participant' && $item['for'] !== 'participant') {
                continue;
            }
            $out[] = ['q' => $this->fill((string) $item['q']), 'a' => $this->fill((string) $item['a'])];
        }

        return $out;
    }

    /** @return array<string, string> */
    public function words(): array
    {
        /** @var array<string, string> $words */
        $words = $this->notes()['words'];

        return $words;
    }

    /** @return list<array{title: string, text: string}> */
    public function tour(string $name): array
    {
        /** @var list<array{title: string, text: string}> $steps */
        $steps = (array) ($this->notes()['tours'][$name] ?? []);

        return $steps;
    }

    /**
     * Everything that exists and so must be covered. The instructor's list is the participant's and the board's.
     *
     * @return list<string>
     */
    public function universe(string $audience): array
    {
        $keys = [];
        foreach (self::STEPS as $step) {
            $keys[] = 'step:'.$step;
        }
        foreach (array_keys((array) $this->content->file('cards')) as $card) {
            if (in_array($card, ['governance', 'learning', 'talent', 'chro'], true)) {
                $keys[] = 'card:'.$card;
            }
        }
        foreach (PlayState::FULL_YEARS as $year) {
            $keys[] = 'year:'.$year;
        }
        $keys[] = 'replay';
        $keys[] = 'join';
        if ($audience === 'instructor') {
            foreach (self::PAGES as $page => $route) {
                if (Route::has($route)) {
                    $keys[] = 'page:'.$page;
                }
            }
            foreach (self::BUTTONS as $button => $route) {
                if (Route::has($route)) {
                    $keys[] = 'button:'.$button;
                }
            }
            foreach (FacultyController::AI_FEATURES as $switch) {
                $keys[] = 'switch:'.$switch;
            }
            foreach (PlayState::FULL_YEARS as $year) {
                $keys[] = 'teach:'.$year;
            }
            foreach (ReferenceTeams::NAMES as $name) {
                $keys[] = 'reference:'.$name;
            }
            foreach (self::WAYS as $way) {
                $keys[] = $way;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The check. Each string is one way the manual and the product disagree. Empty means they agree.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $notes = $this->notes();
        $universe = ['participant' => $this->universe('participant'), 'instructor' => $this->universe('instructor')];
        $covered = ['participant' => [], 'instructor' => []];
        $ids = [];
        foreach ($notes['sections'] as $note) {
            if (isset($ids[$note['id']])) {
                $problems[] = "Two sections share the id [{$note['id']}].";
            }
            $ids[$note['id']] = true;
            foreach ((array) $note['covers'] as $key) {
                $covered['instructor'][$key] = true;
                if ($note['for'] === 'participant') {
                    $covered['participant'][$key] = true;
                }
                if (! in_array($key, $universe['instructor'], true)) {
                    $problems[] = "Section [{$note['id']}] covers [{$key}], which no longer exists.";
                }
            }
        }
        foreach ($notes['faq'] as $item) {
            foreach ((array) $item['covers'] as $key) {
                if (! in_array($key, $universe['instructor'], true)) {
                    $problems[] = "A question covers [{$key}], which no longer exists: {$item['q']}";
                }
            }
        }
        foreach (['participant', 'instructor'] as $audience) {
            foreach ($universe[$audience] as $key) {
                if (! isset($covered[$audience][$key])) {
                    $problems[] = "No section in the {$audience} manual covers [{$key}].";
                }
            }
        }
        foreach ($this->rawTexts() as $where => $text) {
            $plain = (string) preg_replace('/\{[a-z0-9_]+\}/', '', $text);
            $plain = (string) preg_replace('/\b20(2[7-9]|3[0-5])\b/', '', $plain);
            if (preg_match('/\d/', $plain) === 1) {
                $problems[] = "{$where} has a number that is not a token or a year: {$text}";
            }
        }
        try {
            $participant = $this->text('participant');
            $this->text('instructor');
        } catch (RuntimeException $e) {
            return [...$problems, $e->getMessage()];
        }
        foreach ($participant as $line) {
            foreach (ContentPack::BANNED as $banned) {
                if (preg_match('/\b'.preg_quote($banned, '/').'\b/i', $line) === 1) {
                    $problems[] = "The participant manual uses the banned word [{$banned}]: {$line}";
                }
            }
            if (preg_match('/\b[a-z]+_[a-z_]+\b/', $line) === 1) {
                $problems[] = "The participant manual shows a code name: {$line}";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * Every sentence a reader sees, for search and the word checks.
     *
     * @return list<string>
     */
    public function text(string $audience): array
    {
        $out = [];
        foreach ($this->sections($audience) as $section) {
            $out[] = $section['title'];
            foreach ($section['body'] as $item) {
                if (is_array($item)) {
                    $out[] = $item['title'];
                    array_push($out, ...$item['list']);
                } else {
                    $out[] = $item;
                }
            }
        }
        foreach ($this->questions($audience) as $item) {
            $out[] = $item['q'];
            $out[] = $item['a'];
        }
        foreach ($this->tour($audience === 'participant' ? 'participant' : 'instructor') as $step) {
            $out[] = $step['title'];
            $out[] = $step['text'];
        }

        return $out;
    }

    /**
     * Sections whose text changed, newest first, from the log `ashby:manual-log` keeps (H4).
     *
     * @return list<array{date: string, titles: list<string>}>
     */
    public function changes(string $audience): array
    {
        $titles = [];
        foreach ($this->sections($audience) as $section) {
            $titles[$section['id']] = $section['title'];
        }
        $byDate = [];
        foreach ($this->log() as $id => $entry) {
            if (isset($titles[$id])) {
                $byDate[$entry['changed']][] = $titles[$id];
            }
        }
        krsort($byDate);

        return array_map(fn (string $date, array $list): array => ['date' => $date, 'titles' => $list], array_keys($byDate), array_values($byDate));
    }

    /** @return array<string, string> */
    public function hashes(): array
    {
        $out = [];
        foreach ($this->sections('instructor') as $section) {
            $out[$section['id']] = md5(json_encode([$section['title'], $section['body']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        foreach ($this->questions('instructor') as $i => $item) {
            $out['faq-'.($i + 1)] = md5($item['q'].'|'.$item['a']);
        }

        return $out;
    }

    /** @return array<string, array{hash: string, changed: string}> */
    public function log(): array
    {
        $path = $this->logPath();
        if (! is_file($path)) {
            return [];
        }
        /** @var array<string, array{hash: string, changed: string}> $log */
        $log = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $log;
    }

    public function logPath(): string
    {
        return dirname(__DIR__, 3).'/resources/content/ashby/manual_log.json';
    }

    /**
     * The log brought up to date: a section whose text changed, or is new, takes today's date. Returns what moved.
     *
     * @return list<string>
     */
    public function updateLog(string $today): array
    {
        $old = $this->log();
        $new = [];
        $moved = [];
        foreach ($this->hashes() as $id => $hash) {
            if (! isset($old[$id]) || $old[$id]['hash'] !== $hash) {
                $moved[] = $id;
                $new[$id] = ['hash' => $hash, 'changed' => $today];
            } else {
                $new[$id] = $old[$id];
            }
        }
        $gone = array_diff(array_keys($old), array_keys($new));
        if ($moved !== [] || $gone !== [] || ! is_file($this->logPath())) {
            file_put_contents($this->logPath(), json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return [...$moved, ...array_map(fn (string $id): string => "removed: {$id}", $gone)];
    }

    public function logIsCurrent(): bool
    {
        $log = $this->log();
        $hashes = $this->hashes();
        if (array_keys($log) !== array_keys($hashes)) {
            return false;
        }
        foreach ($hashes as $id => $hash) {
            if ($log[$id]['hash'] !== $hash) {
                return false;
            }
        }

        return true;
    }

    /** @return array{words: array<string, string>, sections: list<array<string, mixed>>, faq: list<array<string, mixed>>, tours: array<string, mixed>} */
    private function notes(): array
    {
        /** @var array{words: array<string, string>, sections: list<array<string, mixed>>, faq: list<array<string, mixed>>, tours: array<string, mixed>} $notes */
        $notes = $this->content->file('manual');

        return $notes;
    }

    /** @return array<string, string> */
    private function rawTexts(): array
    {
        $out = [];
        foreach ($this->notes()['sections'] as $note) {
            $lines = [(string) $note['title']];
            foreach ((array) $note['body'] as $item) {
                if (is_array($item)) {
                    $lines[] = (string) $item['title'];
                    foreach ((array) $item['list'] as $l) {
                        $lines[] = (string) $l;
                    }
                } else {
                    $lines[] = (string) $item;
                }
            }
            foreach ($lines as $i => $line) {
                $out["Section [{$note['id']}] line ".($i + 1)] = $line;
            }
        }
        foreach ($this->notes()['faq'] as $i => $item) {
            $out['Question '.($i + 1)] = $item['q'].' '.$item['a'];
        }

        return $out;
    }

    private function fill(string $text): string
    {
        $facts = $this->facts();

        return (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', function (array $m) use ($facts): string {
            if (! array_key_exists($m[1], $facts)) {
                throw new RuntimeException("The manual uses a token the product doesn't have: {{$m[1]}}.");
            }

            return $facts[$m[1]];
        }, $text);
    }

    private function word(int $n): string
    {
        return self::WORDS[$n] ?? (string) $n;
    }
}
