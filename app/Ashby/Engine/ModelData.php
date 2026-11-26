<?php

namespace App\Ashby\Engine;

use RuntimeException;

/**
 * The numbers the engine runs on, read from economics/data. The CSV files are the one source;
 * nothing here is copied into code.
 */
final class ModelData
{
    public const FAMILIES = ['delivery', 'operations'];

    public const FUTURES = ['rapid', 'complement', 'constrained'];

    /** @var array<string, float|list<float>> */
    public readonly array $constants;

    /** @var array<string, list<array<string, float|string>>> Levels 0 to 3 for each family. */
    public readonly array $levels;

    /** @var array<string, array<int, array<string, float>>> Each future's settings by year. */
    public readonly array $futures;

    /** @var array<string, list<array{key: string, name: string, share: float, fit: float, risk: float, type: int}>> */
    public readonly array $workflows;

    /** @var array<string, array{ai: list<float>, review: float, incident: float, lost: float}> Ways of working, in file order. */
    public readonly array $modes;

    /** @var array<int, array{review: float, error: float}> */
    public readonly array $taskTypes;

    public function __construct(?string $directory = null)
    {
        $directory ??= dirname(__DIR__, 3).'/economics/data';

        $constants = [];
        foreach ($this->rows($directory.'/constants.csv') as $row) {
            $value = $row['value'];
            $constants[$row['key']] = str_contains($value, '|')
                ? array_map(floatval(...), explode('|', $value))
                : (float) $value;
        }
        $this->constants = $constants;

        $levels = [];
        foreach ($this->rows($directory.'/levels.csv') as $row) {
            $level = [];
            foreach ($row as $key => $value) {
                $level[$key] = in_array($key, ['family', 'title'], true) ? $value : (float) $value;
            }
            $levels[$row['family']][(int) $row['level']] = $level;
        }
        foreach ($levels as $family => $list) {
            ksort($list);
            $levels[$family] = array_values($list);
        }
        $this->levels = $levels;

        $futures = [];
        foreach ($this->rows($directory.'/futures.csv') as $row) {
            $values = [];
            foreach ($row as $key => $value) {
                if ($key !== 'future' && $key !== 'year') {
                    $values[$key] = (float) $value;
                }
            }
            $futures[$row['future']][(int) $row['year']] = $values;
        }
        $this->futures = $futures;

        $workflows = array_fill_keys(self::FAMILIES, []);
        foreach ($this->rows($directory.'/workflows.csv') as $row) {
            $workflows[$row['family']][] = [
                'key' => $row['key'],
                'name' => $row['name'],
                'share' => (float) $row['share'],
                'fit' => (float) $row['ai_fit'],
                'risk' => (float) $row['risk'],
                'type' => (int) $row['task_type'],
            ];
        }
        $this->workflows = $workflows;

        $modes = [];
        foreach ($this->rows($directory.'/modes.csv') as $row) {
            $modes[$row['mode']] = [
                'ai' => [(float) $row['ai_l0'], (float) $row['ai_l1'], (float) $row['ai_l2']],
                'review' => (float) $row['review_rate'],
                'incident' => (float) $row['incident_weight'],
                'lost' => (float) $row['practice_lost'],
            ];
        }
        $this->modes = $modes;

        $types = [];
        foreach ($this->rows($directory.'/task_types.csv') as $row) {
            $types[(int) $row['task_type']] = ['review' => (float) $row['review_weight'], 'error' => (float) $row['hidden_error_weight']];
        }
        $this->taskTypes = $types;
    }

    /** A constant that holds one number. */
    public function c(string $key): float
    {
        $value = $this->constants[$key] ?? throw new RuntimeException("Unknown constant {$key}.");
        if (is_array($value)) {
            throw new RuntimeException("Constant {$key} holds a list.");
        }

        return $value;
    }

    /**
     * A constant that holds a list of numbers.
     *
     * @return list<float>
     */
    public function list(string $key): array
    {
        $value = $this->constants[$key] ?? throw new RuntimeException("Unknown constant {$key}.");
        if (! is_array($value)) {
            throw new RuntimeException("Constant {$key} holds one number.");
        }

        return $value;
    }

    /** A number from a level of a job family. */
    public function level(string $family, int $level, string $key): float
    {
        return (float) $this->levels[$family][$level][$key];
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            throw new RuntimeException("{$path} is empty.");
        }
        $header = array_map(fn ($name): string => trim((string) $name), $header);
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_map(fn ($value): string => trim((string) $value), $line));
        }
        fclose($handle);

        return $rows;
    }
}
