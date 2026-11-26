<?php

namespace Tests\Unit\Ashby;

use App\Ashby\Content\ContentPack;
use PHPUnit\Framework\TestCase;

/**
 * Every word a participant reads lives in the content pack. None of the banned words may appear, as whole words.
 */
class ContentWordsTest extends TestCase
{
    public function test_no_banned_word_appears_in_the_content_pack(): void
    {
        $pack = new ContentPack(dirname(__DIR__, 3).'/resources/content/ashby');
        $found = [];
        foreach ($pack->strings() as $where => $text) {
            foreach (ContentPack::BANNED as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $text) === 1) {
                    $found[] = "{$where}: {$word}";
                }
            }
            // A lone key such as draft_check is a value the page sends back, not a word anyone reads.
            if (str_contains($text, ' ') && preg_match('/\b[a-z]+_[a-z_]+\b/', $text) === 1) {
                $found[] = "{$where}: a snake_case value";
            }
        }
        $this->assertSame([], $found, 'Banned words on a participant screen.');
    }

    public function test_the_pack_has_every_file(): void
    {
        $pack = new ContentPack(dirname(__DIR__, 3).'/resources/content/ashby');
        foreach (ContentPack::FILES as $file) {
            $this->assertNotEmpty($pack->file($file), "{$file}.json is empty.");
        }
    }
}
