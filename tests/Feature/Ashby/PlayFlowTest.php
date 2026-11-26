<?php

namespace Tests\Feature\Ashby;

use App\Models\TableMessage;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlayFlowTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    public function test_a_participant_without_a_team_gets_a_plain_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/play')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ashby/NoTeam'));
    }

    public function test_a_team_waits_until_its_instructor_opens_2027(): void
    {
        ['seats' => $seats] = $this->makeClass(openThrough: 2026);
        $this->actingAs($seats['chro'])->get('/play')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ashby/NoTeam')->where('message', "Your instructor hasn't opened 2027 yet."));
        $this->get('/play/2027')->assertNotFound();
    }

    public function test_nothing_opens_without_a_future(): void
    {
        ['section' => $section, 'seats' => $seats] = $this->makeClass();
        $section->update(['future' => null]);
        $this->actingAs($seats['chro'])->get('/play/2027')->assertNotFound();
    }

    public function test_a_team_starts_in_2027_and_cannot_skip_ahead(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent'])->get('/play')->assertRedirect('/play/2027');
        $this->get('/play/2027')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ashby/Play')
            ->where('year', 2027)
            ->where('team.seat', 'talent')
            ->where('minutesLeft', 15)
            ->where('confirmed', false)
            ->where('results', null)
            ->has('people', 3));
        $this->get('/play/2029')->assertNotFound();
        $this->get('/play/replay')->assertRedirect('/play');
    }

    public function test_asking_uses_a_minute_and_each_person_answers_eight_times_at_most(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['learning'])->get('/play/2027');
        for ($i = 0; $i < 8; $i++) {
            $this->post('/play/2027/ask', ['person' => 'daniel', 'question' => "Question {$i} about the graduates?"])->assertSessionHasNoErrors();
        }
        $this->post('/play/2027/ask', ['person' => 'daniel', 'question' => 'One more?'])->assertSessionHasErrors('question');
        $this->assertSame(8, TableMessage::query()->where('team_id', $team->id)->count());
        $this->assertSame(8, TeamYear::query()->where('team_id', $team->id)->value('minutes_used'));
        $this->post('/play/2027/ask', ['person' => 'nobody', 'question' => 'Hello?'])->assertNotFound();
    }

    public function test_a_check_counts_once_and_shows_what_it_found(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['governance'])->get('/play/2027');
        $this->post('/play/2027/check', ['check' => 'c2'])->assertSessionHasNoErrors();
        $this->post('/play/2027/check', ['check' => 'c2'])->assertSessionHasNoErrors();
        $row = TeamYear::query()->where('team_id', $team->id)->firstOrFail();
        $this->assertSame(4, $row->checking_used);
        $this->assertSame(['c2'], $row->checks);
        $this->get('/play/2027')->assertInertia(fn (Assert $page) => $page
            ->where('checkingUsed', 4)
            ->where('plan.checks.1.id', 'c2')
            ->whereType('plan.checks.1.found', 'string')
            ->where('plan.checks.0.found', null));
        $this->post('/play/2027/check', ['check' => 'nope'])->assertNotFound();
    }

    public function test_only_the_chro_confirms_and_a_confirmed_year_is_locked(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent'])->get('/play/2027');
        $this->post('/play/2027/moment', ['text' => 'We keep most offers.', 'offers' => 200])->assertSessionHasNoErrors();
        $this->post('/play/2027/cards', $this->cards(['tutor' => 60]))->assertSessionHasNoErrors();
        $this->post('/play/2027/confirm', $this->cards())->assertSessionHasErrors('confirm');

        $this->actingAs($seats['chro'])->post('/play/2027/confirm', $this->cards(['offers' => 200, 'tutor' => 60]))
            ->assertRedirect('/play/2027?step=4');
        $row = TeamYear::query()->where('team_id', $team->id)->where('year', 2027)->firstOrFail();
        $this->assertNotNull($row->confirmed_at);
        $this->assertSame($seats['chro']->id, $row->confirmed_by);

        $this->post('/play/2027/cards', $this->cards())->assertStatus(409);
        $this->post('/play/2027/ask', ['person' => 'daniel', 'question' => 'Too late?'])->assertStatus(409);
        $this->post('/play/2027/paragraph', ['paragraph' => 'Next we watch the reviewers.'])->assertSessionHasNoErrors();

        $this->get('/play/2027')->assertInertia(fn (Assert $page) => $page
            ->where('confirmed', true)
            ->has('results.years', 2)
            ->where('results.years.0.year', 2027)
            ->where('results.years.1.year', 2028));
        $this->get('/play')->assertRedirect('/play/2029');
        $this->get('/play/2029')->assertInertia(fn (Assert $page) => $page
            ->where('cards.tutor', 60)
            ->where('standing.year', 2028));
    }

    public function test_the_instructor_decides_how_far_a_class_can_go(): void
    {
        ['seats' => $seats] = $this->makeClass(openThrough: 2027);
        $this->actingAs($seats['chro'])->post('/play/2027/confirm', $this->cards())->assertRedirect();
        $this->get('/play/2029')->assertNotFound();
    }

    public function test_layoffs_happen_once_and_are_not_carried_forward(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['chro'])->post('/play/2027/confirm', $this->cards(['lay_ops' => 10]))->assertRedirect();
        $this->get('/play/2029')->assertOk();
        $this->assertSame(0, TeamYear::query()->where('team_id', $team->id)->where('year', 2029)->firstOrFail()->cards['lay_ops']);
    }
}
