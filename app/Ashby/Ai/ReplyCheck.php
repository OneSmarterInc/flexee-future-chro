<?php

namespace App\Ashby\Ai;

use App\Ashby\Content\ContentPack;

/**
 * Checks every AI-written text before anyone sees it. A text that fails is never shown and never retried;
 * the reason goes to the instructor only.
 */
final class ReplyCheck
{
    /** Words AI text for a team never uses, on top of the screen list. Matched as whole words. */
    public const NEVER = [
        'levers', 'tiers', 'segments', 'cohorts', 'rounds', 'score', 'scores', 'rank', 'ranks', 'ranking', 'course', 'class',
        'classes', 'student', 'students', 'week', 'weeks', 'simulation', 'professor', 'instructor', 'participant', 'participants',
        'the model', 'the engine', 'the ai', 'language model', 'as an ai', 'delve', 'synergy', 'paradigm', 'tapestry', 'moat', 'leverage',
    ];

    /** For text only an instructor reads: it may say class or team, never name what writes it. */
    public const STAFF_NEVER = ['the model', 'the engine', 'language model', 'as an ai', 'delve', 'synergy', 'paradigm', 'tapestry', 'moat', 'leverage'];

    /** @return list<string> */
    public static function banned(): array
    {
        return [...ContentPack::BANNED, ...self::NEVER];
    }

    /**
     * @param  list<string>  $sources  every text the writer was given (facts, the team's figures, the team's own words)
     * @param  list<string>  $teamText  what the team wrote, whose words are exempt from the banned list
     * @param  list<string>|null  $banned  defaults to banned()
     * @return string|null the reason it failed, or null if it passed
     */
    public static function failure(string $text, array $sources, array $teamText, int $minWords, int $maxWords, ?array $banned = null): ?string
    {
        $text = trim($text);
        $words = str_word_count(strip_tags($text));
        if ($words < $minWords || $words > $maxWords) {
            return "Length: {$words} words (allowed {$minWords} to {$maxWords}).";
        }
        if (preg_match('/(\*\*|^#{1,6}\s|^\s*[-*•]\s|^\s*\d+\.\s|```)/m', $text) === 1) {
            return 'Formatting: the reply used a list, heading or other markup.';
        }
        $team = mb_strtolower(implode(' ', $teamText));
        foreach ($banned ?? self::banned() as $word) {
            $pattern = '/\b'.preg_quote($word, '/').'\b/iu';
            if (preg_match($pattern, $text) === 1 && preg_match($pattern, $team) !== 1) {
                return "Banned word: \"{$word}\".";
            }
        }
        $source = implode("\n", $sources);
        if (($raw = self::unsupported($text, [$source])) !== null) {
            return "Dollar figure not in its inputs: {$raw}.";
        }
        $allowedPct = self::percentFigures($source);
        foreach (self::percentFigures($text) as $raw => $value) {
            if (! self::near($value, $allowedPct, 0.5)) {
                return "Percentage not in its inputs: {$raw}.";
            }
        }

        return null;
    }

    /**
     * Every figure in a fact that the reply has to carry: its dollar amounts and percentages.
     *
     * @return string|null the first figure the reply left out
     */
    public static function missingFrom(string $text, string $fact): ?string
    {
        $have = self::dollarFigures($text);
        foreach (self::dollarFigures($fact) as $raw => $value) {
            if (! self::near($value, $have, max(0.015 * abs($value), 0.005))) {
                return $raw;
            }
        }
        $havePct = self::percentFigures($text);
        foreach (self::percentFigures($fact) as $raw => $value) {
            if (! self::near($value, $havePct, 0.5)) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * A phrase that tells the team what to choose, where the text must only describe.
     *
     * @param  list<string>  $phrases
     */
    public static function advice(string $text, array $phrases): ?string
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/iu', $text) === 1) {
                return "Gave advice (\"{$phrase}\").";
            }
        }

        return null;
    }

    /**
     * Every dollar amount in a text, as dollars. "$3,750M", "$3.75 billion" and "-$200M" all count.
     *
     * @return array<string, float> the figure as written => its value in dollars
     */
    public static function dollarFigures(string $text): array
    {
        $found = [];
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"], ' ', $text);
        if (preg_match_all('/\$\s?(\d[\d,]*(?:\.\d+)?)\s*(billion|million|thousand|bn|[BMK](?![a-z]))?/i', $text, $m, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($m as $hit) {
            $value = (float) str_replace(',', '', $hit[1]);
            $value *= match (strtolower($hit[2] ?? '')) {
                'billion', 'bn', 'b' => 1e9,
                'million', 'm' => 1e6,
                'thousand', 'k' => 1e3,
                default => 1.0,
            };
            $found[trim($hit[0])] = $value;
        }

        return $found;
    }

    /**
     * Every percentage in a text. "8%", "8 percent" and "8 per cent" all count.
     *
     * @return array<string, float>
     */
    public static function percentFigures(string $text): array
    {
        $found = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s?(%|percent\b|per cent\b)/i', $text, $m, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($m as $hit) {
            $found[trim($hit[0])] = (float) $hit[1];
        }

        return $found;
    }

    /**
     * The first dollar figure in a text that none of the sources has, or null when every figure is backed.
     *
     * @param  list<string>  $sources
     */
    public static function unsupported(string $text, array $sources): ?string
    {
        $allowed = self::dollarFigures(implode("\n", $sources));
        foreach (self::dollarFigures($text) as $raw => $value) {
            // Allow the rounding a person would do when saying a figure out loud.
            if (! self::near($value, $allowed, max(0.015 * abs($value), 0.005))) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * A failure in plain words for the instructor.
     */
    public static function plain(string $failure): string
    {
        return match (true) {
            str_starts_with($failure, 'Dollar figure') => 'It quoted a dollar figure that is not in the record ('.self::after($failure).').',
            str_starts_with($failure, 'Percentage') => 'It quoted a percentage that is not in the record ('.self::after($failure).').',
            str_starts_with($failure, 'Length') => 'It was the wrong length ('.self::after($failure).').',
            str_starts_with($failure, 'Banned word') => 'It used a word teams may not see ('.self::after($failure).').',
            str_starts_with($failure, 'Formatting') => 'It used a list or heading.',
            str_starts_with($failure, 'Gave advice') => 'It told the team what to choose ('.self::after($failure).').',
            str_starts_with($failure, 'Left out') => 'It left out the figure it had to give ('.self::after($failure).').',
            default => $failure,
        };
    }

    private static function after(string $failure): string
    {
        $colon = strpos($failure, ':');
        if ($colon === false) {
            return preg_match('/"([^"]+)"/', $failure, $m) === 1 ? '"'.$m[1].'"' : '';
        }

        return rtrim(trim(substr($failure, $colon + 1)), '.');
    }

    /** @param  array<string, float>  $allowed */
    private static function near(float $value, array $allowed, float $tolerance): bool
    {
        foreach ($allowed as $a) {
            if (abs($value - $a) <= $tolerance) {
                return true;
            }
        }

        return false;
    }
}
