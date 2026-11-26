<?php

namespace Tests\Feature\Ashby;

use App\Mail\AshbyMail;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{section: Section, instructor: User} */
    private function classWithInstructor(): array
    {
        $section = Section::query()->create(['name' => 'Spring workshop', 'future' => 'rapid', 'open_through' => 2027]);
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY, 'name' => 'Pat Lee']);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);

        return ['section' => $section->fresh() ?? $section, 'instructor' => $instructor];
    }

    public function test_with_mail_off_nothing_is_sent_and_the_roster_says_so(): void
    {
        Mail::fake();
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => 'new@example.edu']);
        Mail::assertNothingSent();
        $this->get("/faculty/{$section->id}/roster")->assertInertia(fn ($p) => $p->where('mail', false)->where('passwords.0.note', 'new'));
    }

    public function test_a_new_participant_gets_their_sign_in_by_email(): void
    {
        config(['ashby.mail_enabled' => true]);
        Mail::fake();
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        User::factory()->create(['email' => 'old@example.edu']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => "Ada <ada@example.edu>\nold@example.edu\npractice@school.test"]);
        Mail::assertSent(AshbyMail::class, fn (AshbyMail $m) => $m->hasTo('ada@example.edu')
            && $m->subjectLine === 'Your sign-in for Spring workshop'
            && str_contains(implode(' ', $m->lines), 'Pat Lee has added you')
            && str_contains(implode(' ', $m->lines), 'this password: '));
        Mail::assertSent(AshbyMail::class, fn (AshbyMail $m) => $m->hasTo('old@example.edu') && $m->subjectLine === "You've been added to Spring workshop");
        Mail::assertNotSent(AshbyMail::class, fn (AshbyMail $m) => $m->hasTo('practice@school.test'));
        $this->get("/faculty/{$section->id}/roster")->assertInertia(fn ($p) => $p->where('mail', true)->where('passwords.0.note', 'new_mailed'));
    }

    public function test_reminders_go_once_to_teams_that_have_not_confirmed(): void
    {
        config(['ashby.mail_enabled' => true]);
        Mail::fake();
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $section->teams()->create(['name' => 'Team 1']);
        $section->teams()->create(['name' => 'Team 2']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => "a@example.edu\nb@example.edu"]);
        $this->post("/faculty/{$section->id}/roster/teams/form");
        Mail::fake();
        SectionYear::query()->create(['section_id' => $section->id, 'year' => 2027, 'opens_at' => now()->subHour(), 'closes_at' => now()->addMinutes(50)]);

        Artisan::call('ashby:remind');
        Mail::assertSent(AshbyMail::class, 2);
        Mail::assertSent(AshbyMail::class, fn (AshbyMail $m) => $m->subjectLine === '2027 closes in 1 hour');
        Artisan::call('ashby:remind');
        Mail::assertSent(AshbyMail::class, 2);
    }
}
