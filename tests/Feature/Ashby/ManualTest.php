<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Content\ContentPack;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Help\Manual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The manuals that can't fall behind (H2). The first group is the check itself; the second watches it fail,
 * because a check never seen failing proves nothing; the third is who may read what.
 */
class ManualTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    private string $copy = '';

    protected function tearDown(): void
    {
        if ($this->copy !== '' && is_dir($this->copy)) {
            array_map('unlink', glob($this->copy.'/*') ?: []);
            rmdir($this->copy);
        }
        parent::tearDown();
    }

    public function test_the_manuals_agree_with_the_product(): void
    {
        $this->assertSame([], app(Manual::class)->problems());
    }

    public function test_the_what_changed_log_is_current(): void
    {
        $this->assertTrue(app(Manual::class)->logIsCurrent(), 'Run: php artisan ashby:manual-log');
        $this->artisan('ashby:manual-log', ['--check' => true])->assertSuccessful();
    }

    public function test_a_year_nothing_explains_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'years', fn (array $s): array => [...$s, 'covers' => array_values(array_diff($s['covers'], ['year:2033']))]))->problems();
        $this->assertContains('No section in the participant manual covers [year:2033].', $problems);
        $this->assertContains('No section in the instructor manual covers [year:2033].', $problems);
    }

    public function test_a_board_button_nothing_explains_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'board', fn (array $s): array => [...$s, 'covers' => array_values(array_diff($s['covers'], ['button:reopen']))]))->problems();
        $this->assertContains('No section in the instructor manual covers [button:reopen].', $problems);
    }

    public function test_a_note_about_something_that_is_gone_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'table', fn (array $s): array => [...$s, 'covers' => [...$s['covers'], 'step:vanished']]))->problems();
        $this->assertContains('Section [table] covers [step:vanished], which no longer exists.', $problems);
    }

    public function test_a_number_typed_into_a_note_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'table', fn (array $s): array => [...$s, 'body' => ['You have 15 minutes at the table.']]))->problems();
        $this->assertNotEmpty(array_filter($problems, fn (string $p): bool => str_contains($p, 'has a number that is not a token')));
    }

    public function test_a_banned_word_in_the_participant_manual_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'moment', fn (array $s): array => [...$s, 'body' => ['Each round has one moment.']]))->problems();
        $this->assertContains('The participant manual uses the banned word [round]: Each round has one moment.', $problems);
    }

    public function test_a_token_the_product_does_not_have_is_found(): void
    {
        $problems = $this->broken(fn (array $m): array => $this->editSection($m, 'table', fn (array $s): array => [...$s, 'body' => ['You have {made_up} minutes.']]))->problems();
        $this->assertContains("The manual uses a token the product doesn't have: {made_up}.", $problems);
    }

    public function test_participants_read_their_manual_and_staff_read_both(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent'])->get('/help')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('ashby/Help')
            ->where('audience', 'participant')
            ->where('sections', fn ($s) => collect($s)->every(fn ($x) => $x['for'] === 'participant'))
            ->where('sections.1.body.0', fn ($t) => str_contains((string) $t, 'You have 15 minutes at the table'))
            ->has('changes'));
        $this->get('/faculty/help')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->get('/faculty/help')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('audience', 'instructor')
            ->where('sections', fn ($s) => collect($s)->contains(fn ($x) => $x['id'] === 'teach-2032')));
    }

    public function test_the_welcome_tour_shows_once(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent'])->get('/play/2027')->assertInertia(fn (Assert $p) => $p->has('tour', 5)->where('yearMinutes', 45));
        $this->post('/help/tours/participant/done')->assertRedirect();
        $this->get('/play/2027')->assertInertia(fn (Assert $p) => $p->where('tour', null));
        $this->post('/help/tours/nonsense/done')->assertNotFound();
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($instructor)->post('/help/tours/instructor/done');
        $this->assertSame(['instructor'], $instructor->fresh()?->tours_done);
    }

    /**
     * A copy of the content pack with manual.json changed, read by a fresh Manual.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function broken(callable $change): Manual
    {
        $this->copy = sys_get_temp_dir().'/ashby-manual-'.bin2hex(random_bytes(4));
        mkdir($this->copy);
        foreach (glob(resource_path('content/ashby').'/*.json') ?: [] as $file) {
            copy($file, $this->copy.'/'.basename($file));
        }
        /** @var array<string, mixed> $manual */
        $manual = json_decode((string) file_get_contents($this->copy.'/manual.json'), true);
        file_put_contents($this->copy.'/manual.json', json_encode($change($manual)));

        return new Manual(new ContentPack($this->copy), app(AshbyEngine::class));
    }

    /**
     * @param  array<string, mixed>  $manual
     * @param  callable(array<string, mixed>): array<string, mixed>  $edit
     * @return array<string, mixed>
     */
    private function editSection(array $manual, string $id, callable $edit): array
    {
        $manual['sections'] = array_map(fn (array $s): array => $s['id'] === $id ? $edit($s) : $s, (array) $manual['sections']);

        return $manual;
    }
}
