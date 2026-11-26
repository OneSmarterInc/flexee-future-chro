<?php

namespace App\Ashby\Game;

/**
 * The people at the table, with fixed replies. A held-back fact comes out only when a question
 * meets its trigger; otherwise the person says the next thing they say freely.
 * PR 5 adds AI voices under the same rules.
 */
final class TableTalk
{
    public const MINUTES = 15;

    public const CHECKING_MINUTES = 10;

    /**
     * Table time for a year: minutes in all, and how many of them may go on checking (FF-D3).
     *
     * @param  array<string, mixed>  $years  years.json
     * @return array{0: int, 1: int}
     */
    public static function time(array $years, int $year): array
    {
        $t = (array) ($years['table_time'][(string) $year] ?? [self::MINUTES, self::CHECKING_MINUTES]);

        return [(int) ($t[0] ?? self::MINUTES), (int) ($t[1] ?? self::CHECKING_MINUTES)];
    }

    public const QUESTIONS_PER_PERSON = 8;

    /**
     * @param  array<string, mixed>  $person  The person's entry for this year in years.json.
     * @param  list<string>  $revealed  Facts this team has already been told.
     * @param  int  $answered  How many questions this person has answered this year.
     * @return array{answer: string, revealed: string|null}
     */
    public static function answer(array $person, string $question, array $revealed, int $answered): array
    {
        $low = mb_strtolower($question);
        $held = is_array($person['holds_back'] ?? null) ? $person['holds_back'] : [];
        foreach ($held as $fact) {
            if (! is_array($fact) || in_array($fact['id'], $revealed, true)) {
                continue;
            }
            foreach ((array) $fact['when_asked_about'] as $trigger) {
                if (str_contains($low, mb_strtolower((string) $trigger))) {
                    return ['answer' => (string) $fact['says'], 'revealed' => (string) $fact['id']];
                }
            }
        }
        $says = is_array($person['says'] ?? null) ? array_values($person['says']) : [''];

        return ['answer' => (string) $says[$answered % max(1, count($says))], 'revealed' => null];
    }
}
