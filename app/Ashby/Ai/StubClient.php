<?php

namespace App\Ashby\Ai;

/**
 * Stands in for the real model in tests and when no API key is set. It answers with a fixed, harmless
 * sentence, or with whatever a test queues up, and records what it was asked.
 */
final class StubClient implements LlmClient
{
    /** @var list<string|LlmReply> */
    private array $queued = [];

    /** @var list<array{system: string, messages: list<array{role: string, content: string|list<array<string, mixed>>}>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    public function queue(string|LlmReply ...$replies): void
    {
        foreach ($replies as $reply) {
            $this->queued[] = $reply;
        }
    }

    public function complete(string $system, array $messages, int $maxTokens, array $tools = []): LlmReply
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages, 'tools' => $tools];
        $next = array_shift($this->queued);
        if ($next instanceof LlmReply) {
            return $next;
        }
        $text = $next
            ?? "I can tell you what I see from where I sit, and it's what the figures in front of you already say. What you do with them is for your team to decide.";

        return new LlmReply($text, (int) ceil(strlen($system) / 4), (int) ceil(strlen($text) / 4), 'stub');
    }
}
