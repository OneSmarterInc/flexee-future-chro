<?php

namespace App\Console\Commands;

use App\Ashby\Ai\Writer;
use App\Ashby\Content\ContentPack;
use Illuminate\Console\Command;

/**
 * A live check of the AI service on this server: one question to Claire Whitman in 2027, through every
 * check a team's answer goes through. Run after a release with a key in .env.
 */
class AshbyAiCheck extends Command
{
    protected $signature = 'ashby:ai-check';

    protected $description = 'Ask the AI service one table question and run it through the checks';

    public function handle(Writer $writer, ContentPack $content): int
    {
        if (! $writer->available()) {
            $this->error('AI is off on this server: set ANTHROPIC_API_KEY (and leave ASHBY_AI_ENABLED unset or true).');

            return self::FAILURE;
        }
        $years = $content->file('years');
        $talk = (array) $years['talk']['2027']['claire'];
        $spec = (array) $content->file('ai')['voices'];
        $c = (array) $spec['characters']['claire'];
        $system = Writer::fill((string) $spec['rules'], [
            'name' => 'Claire Whitman', 'role' => 'CFO', 'year' => 2027, 'who' => (string) $c['who'], 'wants' => (string) $c['wants'], 'manner' => (string) $c['manner'],
            'facts' => implode("\n", [(string) $talk['opens'], ...array_map('strval', (array) $talk['says'])]),
            'figures' => 'Last year the firm earned $389 million in fees and kept about $98 million as profit.', 'table' => '',
        ]);
        $question = 'Why should we trust the plan?';
        $result = $writer->write($system, [['role' => 'user', 'content' => $question]], (int) $spec['max_tokens'], [], [$question], (int) $spec['words_min'], (int) $spec['words_max']);
        $this->line('Model: '.($result['model'] ?? 'none').'; tokens in '.$result['input_tokens'].', out '.$result['output_tokens'].'.');
        if ($result['text'] === null) {
            $this->error('The answer did not pass: '.$result['note']);
            if ($result['draft'] !== '') {
                $this->line('What was written (dropped, never shown to a team):');
                $this->line($result['draft']);
            }

            return self::FAILURE;
        }
        $this->info('Passed. Claire said:');
        $this->line($result['text']);

        return self::SUCCESS;
    }
}
