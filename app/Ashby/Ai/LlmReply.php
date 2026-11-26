<?php

namespace App\Ashby\Ai;

final readonly class LlmReply
{
    /**
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls  lookups the model asked for
     * @param  list<array<string, mixed>>  $blocks  the reply as the service sent it, to send back with the lookup results
     */
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public string $model = '',
        public bool $cutOff = false,
        public array $toolCalls = [],
        public array $blocks = [],
    ) {}
}
