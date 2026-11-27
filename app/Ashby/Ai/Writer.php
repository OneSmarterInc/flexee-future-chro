<?php

namespace App\Ashby\Ai;

use App\Ashby\Content\ContentPack;
use App\Models\Section;
use Throwable;

/**
 * One AI-written text, checked before anyone sees it. A text that fails is dropped, never retried;
 * the reason, in plain words, is for the instructor only.
 */
final class Writer
{
    public function __construct(private readonly LlmClient $llm, private readonly ContentPack $content) {}

    /** Whether the AI features can run on this server: on in config, and a real key on production. */
    public function available(): bool
    {
        return (bool) config('ashby.ai.enabled') && ((string) config('ashby.ai.anthropic_key') !== '' || ! app()->isProduction());
    }

    public function on(Section $section, string $feature): bool
    {
        return $this->available() && $section->aiOn($feature);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<string>  $sources  every text the writer was given
     * @param  list<string>  $teamText  the team's own words, exempt from the banned list
     * @param  list<string>|null  $banned  null for the participant list
     * @param  callable(string): ?string|null  $extra  one more check on the text
     * @return array{text: string|null, note: string|null, draft: string, input_tokens: int, output_tokens: int, model: string|null}
     */
    public function write(string $system, array $messages, int $maxTokens, array $sources, array $teamText, int $minWords, int $maxWords, ?array $banned = null, bool $noAdvice = true, ?callable $extra = null): array
    {
        $reply = new LlmReply('');
        try {
            $reply = $this->llm->complete($system, $messages, $maxTokens);
            $text = trim($reply->text);
            $reason = $reply->cutOff ? 'The reply was cut off before it finished.' : null;
            $reason ??= ReplyCheck::failure($text, [$system, ...$sources, ...$teamText], $teamText, $minWords, $maxWords, $banned);
            if ($noAdvice) {
                $reason ??= ReplyCheck::advice($text, array_values(array_map('strval', (array) $this->content->file('ai')['advice_phrases'])));
            }
            if ($extra !== null) {
                $reason ??= $extra($text);
            }
        } catch (Throwable $e) {
            $text = '';
            $reason = 'The AI service did not answer: '.mb_substr($e->getMessage(), 0, 150);
        }

        return [
            'text' => $reason === null ? $text : null,
            'note' => $reason === null ? null : mb_substr(ReplyCheck::plain($reason), 0, 250),
            'draft' => $text,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'model' => $reply->model === '' ? null : $reply->model,
        ];
    }

    /** @param  array<string, string|int|float>  $vars */
    public static function fill(string $text, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    public static function money(float $dollars): string
    {
        return ($dollars < 0 ? '-$' : '$').number_format(abs($dollars) / 1e6, 1).' million';
    }

    /**
     * An engine row as plain sentences the writer may quote.
     *
     * @param  array<string, mixed>  $row
     */
    public static function figures(array $row): string
    {
        $y = (int) $row['year'];

        return "In {$y}: profit ".self::money((float) $row['profit']).', fees '.self::money((float) $row['revenue'])
            .', '.number_format((float) $row['headcount']).' people, trust '.round((float) $row['trust']).' out of 100, strain '.round((float) $row['strain']).' out of 100, '
            .number_format((float) $row['incidents'], 1).' AI errors that reached clients, senior associates '.round((float) ((array) $row['delivery_levels'])[1]).'.'
            .((array) $row['notes'] === [] ? '' : ' '.implode(' ', array_map('strval', (array) $row['notes'])).'.');
    }
}
