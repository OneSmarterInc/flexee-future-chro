<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Ai\LlmClient;
use App\Ashby\Ai\ReplyCheck;
use App\Ashby\Ai\StubClient;
use App\Ashby\Game\ReferenceTeams;
use App\Models\SectionYear;
use App\Models\TableMessage;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PR 6's "done" test: every AI text passes its guardrails with the stand-in, and one that fails never reaches a team.
 */
class AiVoicesTest extends TestCase
{
    use MakesAClass;
    use RefreshDatabase;

    private StubClient $stub;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ashby.ai.enabled' => true]);
        $this->stub = new StubClient;
        $this->app->instance(LlmClient::class, $this->stub);
    }

    private function ask(string $person, string $question): TableMessage
    {
        $this->post('/play/2027/ask', ['person' => $person, 'question' => $question])->assertSessionHasNoErrors();

        return TableMessage::query()->orderByDesc('id')->firstOrFail();
    }

    public function test_with_ai_off_on_the_server_people_give_their_fixed_replies(): void
    {
        config(['ashby.ai.enabled' => false]);
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent']);
        $m = $this->ask('claire', 'Hello?');
        $this->assertSame('fixed', $m->source);
        $this->assertSame('The plan came from our AI planning tool. I haven\'t checked it line by line.', $m->answer);
        $this->assertSame([], $this->stub->calls);
    }

    public function test_a_voice_answers_in_its_own_words_and_the_screen_says_where_it_came_from(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent']);
        $this->stub->queue('I brought the plan because the savings look real on paper. I would like your view before I take it any further with the partners.');
        $m = $this->ask('claire', 'Why did you bring this plan?');
        $this->assertSame('ai', $m->source);
        $this->assertNull($m->dropped_reason);
        $this->assertStringStartsWith('I brought the plan', $m->answer);
        $this->get('/play/2027')->assertInertia(fn (Assert $p) => $p->where('people.1.messages.0.ai', true));
        $this->assertStringContainsString('Claire Whitman, CFO of Ashby Calder', $this->stub->calls[0]['system']);
    }

    public function test_a_held_back_fact_is_never_given_to_the_writer_until_its_trigger_is_met(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent']);
        $this->stub->queue('The plan is the tool\'s work and I have not been through it line by line yet. Ask me what you need and I will tell you what I can.');
        $this->ask('claire', 'Is the plan any good?');
        $first = $this->stub->calls[0]['system'];
        $this->assertStringNotContainsString('8.4 million', $first);
        $this->assertStringNotContainsString('eight analyst-hours', $first);

        $this->stub->queue('It counts salaries only. The tools themselves cost about $8.4 million a year, so before any checking we are nearer $10 million in savings.');
        $m = $this->ask('claire', 'What does the plan leave out?');
        $this->assertSame('aicost', $m->revealed);
        $this->assertSame('ai', $m->source);
        $second = $this->stub->calls[1]['system'];
        $this->assertStringContainsString('you must tell them this', $second);
        $this->assertStringContainsString('$8.4 million', $second);
        $this->assertStringNotContainsString('eight analyst-hours', $second, 'Only the fact whose trigger was met.');
        $this->assertSame([['role' => 'user', 'content' => 'Is the plan any good?'], ['role' => 'assistant', 'content' => TableMessage::query()->orderBy('id')->firstOrFail()->answer], ['role' => 'user', 'content' => 'What does the plan leave out?']], $this->stub->calls[1]['messages']);
    }

    public function test_an_answer_that_leaves_out_the_fact_falls_back_to_the_fixed_reply(): void
    {
        ['section' => $section, 'seats' => $seats] = $this->makeClass();
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);
        $this->actingAs($seats['talent']);
        $this->stub->queue('It counts salaries only, and the tools cost money on top of that, which the plan does not show anywhere at all.');
        $m = $this->ask('claire', 'What does the plan leave out?');
        $this->assertSame('fixed', $m->source);
        $this->assertStringContainsString('$8.4 million', $m->answer);
        $this->assertSame('It left out the figure it had to give ($8.4 million).', $m->dropped_reason);
        $this->actingAs($instructor)->get("/faculty/{$section->id}/2027")->assertInertia(fn (Assert $p) => $p->where('teams.0.ai_notes.0.note', $m->dropped_reason));
        $this->actingAs($seats['talent'])->get('/play/2027')->assertInertia(fn (Assert $p) => $p->missing('people.1.messages.0.dropped_reason'));
    }

    public function test_each_guardrail_drops_a_bad_answer_and_the_team_gets_the_fixed_reply(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent']);
        $bad = [
            'A banned word' => ['Your score on this is fine and I would take the savings now before the partners change their minds about it.', 'It used a word teams may not see ("score").'],
            'A made-up figure' => ['The savings are really about $50 million once you count everything properly, and that is what I will tell the partners.', 'It quoted a dollar figure that is not in the record ($50 million).'],
            'A made-up percentage' => ['About 40% of the analysts could go this year and nobody outside the firm would notice the difference at all.', 'It quoted a percentage that is not in the record (40%).'],
            'Advice' => ['You should take the plan as written and cut the graduate intake this year, because the numbers are clear enough.', 'It told the team what to choose ("you should").'],
            'A list' => ["Three things:\n- the savings\n- the timing\n- the partners, who will want to see this before anything moves forward", 'It used a list or heading.'],
            'Too short' => ['No.', 'It was the wrong length (1 words (allowed 12 to 110)).'],
            'The writer itself' => ['As an AI I cannot say, but the language model behind this suggests the plan is broadly right in what it claims.', 'It used a word teams may not see ("language model").'],
        ];
        foreach ($bad as $what => [$text, $note]) {
            $this->stub->queue($text);
            $m = $this->ask('daniel', 'How do the partners see this?');
            $this->assertSame('fixed', $m->source, $what);
            $this->assertSame($note, $m->dropped_reason, $what);
            $this->assertStringNotContainsString($text, (string) $m->answer, $what);
        }
    }

    public function test_the_team_s_own_words_may_come_back_to_it(): void
    {
        ['seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['talent']);
        $this->stub->queue('If by score you mean how the partners keep count, they look at fees and at who runs the work. That is what I hear from them.');
        $m = $this->ask('daniel', 'What score do the partners keep?');
        $this->assertSame('ai', $m->source);
    }

    public function test_the_instructor_switches_a_feature_off_for_the_class_and_no_one_else_can(): void
    {
        ['section' => $section, 'seats' => $seats] = $this->makeClass();
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);
        $this->actingAs($seats['chro'])->post("/faculty/{$section->id}/ai", ['feature' => 'table', 'on' => false])->assertForbidden();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/ai", ['feature' => 'table', 'on' => false])->assertSessionHasNoErrors();
        $this->get("/faculty/{$section->id}")->assertInertia(fn (Assert $p) => $p->where('ai.available', true)->where('ai.features.0.id', 'table')->where('ai.features.0.on', false)->where('ai.features.1.on', true));
        $this->actingAs($seats['talent']);
        $m = $this->ask('claire', 'Why this plan?');
        $this->assertSame('fixed', $m->source);
        $this->assertSame([], $this->stub->calls);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/ai", ['feature' => 'table', 'on' => true]);
        $this->assertSame([], $section->fresh()?->ai_off);
    }

    public function test_daniel_replies_once_to_the_paragraph_using_only_the_record(): void
    {
        ['team' => $team, 'seats' => $seats] = $this->makeClass();
        $this->actingAs($seats['chro'])->post('/play/2027/confirm', ReferenceTeams::cards('builder')[2027])->assertRedirect();
        $this->post('/play/2027/feedback')->assertSessionHasErrors('feedback');
        $this->post('/play/2027/paragraph', ['paragraph' => 'We expect AI to move slowly, so we kept graduate hiring at 70% and trained people.'])->assertSessionHasNoErrors();
        $this->stub->queue('Your note says you kept graduate hiring at 70%, and the record agrees: you made 161 offers. You expect AI to move slowly; what the record shows so far is a firm that still has its analysts in place.');
        $this->post('/play/2027/feedback')->assertSessionHasNoErrors();
        $row = TeamYear::query()->where('team_id', $team->id)->where('year', 2027)->firstOrFail();
        $this->assertStringStartsWith('Your note says', (string) $row->feedback);
        $this->assertStringContainsString('161 graduate offers', $this->stub->calls[0]['system']);
        $this->assertStringContainsString('In 2028: profit $', $this->stub->calls[0]['system']);
        $this->post('/play/2027/feedback')->assertSessionHasErrors('feedback');
        $this->assertCount(1, $this->stub->calls, 'Once per year.');
        $this->get('/play/2027')->assertInertia(fn (Assert $p) => $p->where('feedback.text', $row->feedback)->where('feedback.tried', true));
    }

    public function test_the_class_reading_and_debrief_are_for_the_instructor_once_every_team_is_in(): void
    {
        ['section' => $section, 'team' => $team, 'seats' => $seats] = $this->makeClass('rapid');
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/years/2027/reading")->assertSessionHasErrors('board');
        $this->actingAs($seats['chro'])->post("/faculty/{$section->id}/years/2027/reading")->assertForbidden();

        foreach (ReferenceTeams::cards('middle') as $year => $cards) {
            $this->actingAs($seats['chro'])->post("/play/{$year}/confirm", $cards)->assertRedirect();
        }
        $reading = 'Across the class, every team kept most of its analysts and moved work to drafts with people checking. The contrast worth raising is how differently teams spoke about the pace of change, given the same evidence on the table in 2027 and the same pilot results from the firm.'
            .' One team made 138 graduate offers rather than the usual intake and still talked about building the next generation of managers, which is a good thread to pull on in the discussion that follows, and a fair question for the whole group to answer together.';
        $this->stub->queue($reading);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/years/2027/reading")->assertSessionHasNoErrors();
        $this->assertSame($reading, SectionYear::query()->where('section_id', $section->id)->where('year', 2027)->value('reading'));
        $this->assertStringNotContainsString('rapid', $this->stub->calls[0]['system'], 'The reading does not name the future.');

        $debrief = str_repeat('The replay shows that the team which drafted with AI and kept people checking held up across all three futures, while its gains were largest in the rapid one. ', 5);
        $this->stub->queue($debrief);
        $this->post("/faculty/{$section->id}/debrief")->assertSessionHasNoErrors();
        $this->assertSame(trim($debrief), $section->fresh()?->debrief);
        $this->assertStringContainsString('class ran in the rapid future', $this->stub->calls[1]['system']);
        $this->get("/faculty/{$section->id}/2031")->assertInertia(fn (Assert $p) => $p->where('ai.debrief.text', trim($debrief)));
    }

    public function test_the_live_check_runs_one_question_through_the_checks(): void
    {
        $this->artisan('ashby:ai-check')->assertSuccessful();
        config(['ashby.ai.enabled' => false]);
        $this->artisan('ashby:ai-check')->assertFailed();
    }

    public function test_figures_are_read_the_way_people_write_them(): void
    {
        $this->assertSame(['$8.4 million' => 8.4e6, '$3,750M' => 3.75e9, '$12k' => 12000.0], ReplyCheck::dollarFigures('About $8.4 million, or $3,750M, or $12k.'));
        $this->assertSame(['8%' => 8.0, '12.5 percent' => 12.5], ReplyCheck::percentFigures('Costs fall 8% and fees 12.5 percent.'));
        $this->assertNull(ReplyCheck::missingFrom('nearer $8.4m in all', 'about $8.4 million a year'));
        $this->assertSame('$8.4 million', ReplyCheck::missingFrom('some money', 'about $8.4 million a year'));
    }
}
