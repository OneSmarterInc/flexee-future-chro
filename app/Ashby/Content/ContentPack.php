<?php

namespace App\Ashby\Content;

use RuntimeException;

/**
 * Every word a participant reads, from resources/content/ashby. One source: nothing here is copied into code.
 */
final class ContentPack
{
    /** Words that may not appear on any screen a team sees. Checked as whole words by ContentWordsTest. */
    public const BANNED = ['room', 'war room', 'lever', 'tier', 'converge', 'commit', 'segment', 'cohort', 'runtime', 'round'];

    /** The files in the pack. */
    public const FILES = ['common', 'years', 'cards', 'results', 'replay', 'faculty'];

    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    public function __construct(private readonly ?string $directory = null) {}

    /** @return array<string, mixed> */
    public function file(string $name): array
    {
        if (! in_array($name, self::FILES, true)) {
            throw new RuntimeException("No content file {$name}.");
        }
        if (! isset($this->loaded[$name])) {
            $path = ($this->directory ?? resource_path('content/ashby')).'/'.$name.'.json';
            $json = file_get_contents($path);
            if ($json === false) {
                throw new RuntimeException("Cannot read {$path}.");
            }
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException("{$path} holds no object.");
            }
            /** @var array<string, mixed> $decoded */
            $this->loaded[$name] = $decoded;
        }

        return $this->loaded[$name];
    }

    /**
     * Every string in the pack, for the content-words test.
     *
     * @return list<string>
     */
    public function strings(): array
    {
        $out = [];
        foreach (self::FILES as $name) {
            $data = $this->file($name);
            array_walk_recursive($data, function (mixed $value) use (&$out): void {
                if (is_string($value)) {
                    $out[] = $value;
                }
            });
        }

        return $out;
    }
}
