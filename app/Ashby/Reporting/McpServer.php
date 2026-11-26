<?php

namespace App\Ashby\Reporting;

use App\Models\ReportingCall;
use App\Models\ReportingConnection;
use Throwable;

/**
 * the reporting connector's MCP side. Stateless JSON-RPC over plain
 * HTTP POST (the Streamable HTTP transport, answered as JSON): initialize,
 * ping, tools/list and tools/call. Every tool is read-only, takes no SQL and
 * returns rows worked out by the app. Each call is logged without its rows.
 */
class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(private ReportingViews $views) {}

    /**
     * The reply to one JSON-RPC message, or null for a notification.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, ReportingConnection $connection): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, -32600, 'Invalid request.');
        }
        if (! array_key_exists('id', $message)) {
            return null;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params, $connection),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($asked, self::PROTOCOL_VERSIONS, true) ? $asked : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'flexee-reporting', 'title' => 'Flexee reporting', 'version' => (string) config('ashby.reporting.contract')],
            'instructions' => 'Read-only operational reporting for '.config('ashby.reporting.sim').' (Flexee family reporting contract '.config('ashby.reporting.contract').'). '
                .'Each tool returns {"rows": [...]}; every row starts with "sim". Times are UTC (ISO 8601), money is US dollars. '
                .'In The Future CHRO a course is a class, a firm is a team and a quarter is a decision year (2027 to 2031 in the slice, 2027 to 2034 in the full format). There are no names of people, no participant work and no results. Sample classes are left out.',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function tools(): array
    {
        $tools = [];
        foreach (ReportingViews::tools() as $name => $tool) {
            $columns = collect($tool['columns'])->map(fn ($d, $c) => "{$c}: {$d}")->join("\n");
            $tools[] = [
                'name' => $name,
                'title' => $tool['title'],
                'description' => $tool['description']."\n\nColumns:\n".$columns,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'outputSchema' => [
                    'type' => 'object',
                    'properties' => ['rows' => ['type' => 'array', 'items' => ['type' => 'object']]],
                    'required' => ['rows'],
                ],
                'annotations' => ['title' => $tool['title'], 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ];
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, ReportingConnection $connection): array
    {
        $name = (string) ($params['name'] ?? '');
        if (! array_key_exists($name, ReportingViews::tools())) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }
        $started = hrtime(true);
        try {
            $rows = $this->views->run($name);
            $this->log($connection, $name, 'ok', count($rows), $started);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'structuredContent' => ['rows' => $rows],
                'isError' => false,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->log($connection, $name, 'error', 0, $started);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'The report couldn\'t be worked out just now. Try again shortly.']],
                'isError' => true,
            ]);
        }
    }

    private function log(ReportingConnection $connection, string $tool, string $status, int $rows, int $started): void
    {
        ReportingCall::query()->create([
            'connection_id' => $connection->id,
            'tool' => $tool,
            'status' => $status,
            'rows' => $rows,
            'ms' => (int) round((hrtime(true) - $started) / 1e6),
        ]);
    }

    /** @return array<string, mixed> */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
