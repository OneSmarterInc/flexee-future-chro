<?php

namespace App\Ashby\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AnthropicClient implements LlmClient
{
    /**
     * Current models think before they answer, and the thinking counts against max_tokens. The callers' budgets
     * are for the answer itself (the checks enforce the length), so the request gets this much room on top.
     */
    private const THINKING_ROOM = 8000;

    public function __construct(private readonly string $apiKey, private readonly string $model) {}

    public function complete(string $system, array $messages, int $maxTokens, array $tools = []): LlmReply
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(45)->retry(2, 1500, throw: false)->post('https://api.anthropic.com/v1/messages', [
            'model' => $this->model,
            'max_tokens' => $maxTokens + self::THINKING_ROOM,
            'system' => $system,
            'messages' => $messages,
            ...($tools === [] ? [] : ['tools' => $tools]),
        ]);
        if (! $response->successful()) {
            $why = $response->json('error.message');
            throw new RuntimeException('The AI service did not answer (HTTP '.$response->status().')'.(is_string($why) && $why !== '' ? ': '.mb_substr($why, 0, 200) : '').'.');
        }
        $text = '';
        $blocks = [];
        $calls = [];
        foreach ((array) $response->json('content', []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'tool_use' && (($block['input'] ?? []) === [])) {
                // A lookup with no arguments decodes to [], and the API wants {} when it is sent back.
                $block['input'] = new \stdClass;
            }
            $blocks[] = $block;
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) $block['text'];
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => (array) ($block['input'] ?? [])];
            }
        }

        return new LlmReply(
            trim($text),
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (string) $response->json('model', $this->model),
            $response->json('stop_reason') === 'max_tokens',
            $calls,
            $blocks,
        );
    }
}
