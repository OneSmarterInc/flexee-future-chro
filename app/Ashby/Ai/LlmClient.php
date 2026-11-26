<?php

namespace App\Ashby\Ai;

interface LlmClient
{
    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  list<array<string, mixed>>  $tools  tool definitions the model may call; empty for a plain answer
     */
    public function complete(string $system, array $messages, int $maxTokens, array $tools = []): LlmReply;
}
