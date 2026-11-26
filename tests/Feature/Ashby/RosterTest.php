<?php

namespace Tests\Feature\Ashby;

use App\Ashby\Admin\ResultsExport;
use App\Ashby\Admin\Roster;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RosterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{section: Section, instructor: User} */
    private function classWithInstructor(?int $capacity = null): array
    {
        $section = Section::query()->create(['name' => 'Workshop', 'capacity' => $capacity]);
        $instructor = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($instructor->id, ['role' => 'owner']);

        return ['section' => $section->fresh() ?? $section, 'instructor' => $instructor];
    }

    private function list(int $n, string $prefix = 'p'): string
    {
        return implode("\n", array_map(fn (int $i): string => "Person {$i} <{$prefix}{$i}@example.edu>", range(1, $n)));
    }

    /** @return array<string, string> */
    private function seats(Team $team): array
    {
        return $team->members()->get()->mapWithKeys(fn (User $u) => [$u->email => (string) $u->pivot?->getAttribute('seat')])->all();
    }

    public function test_a_pasted_list_is_read_in_every_usual_form(): void
    {
        $parsed = Roster::parse("email,name\nAda Byron <ADA@example.edu>\nbob@example.edu, Bob Ray\nCy Young; cy@example.edu\nnot an email\nada@example.edu");
        $this->assertSame([
            ['email' => 'ada@example.edu', 'name' => 'Ada Byron'],
            ['email' => 'bob@example.edu', 'name' => 'Bob Ray'],
            ['email' => 'cy@example.edu', 'name' => 'Cy Young'],
        ], $parsed['people']);
        $this->assertSame(['not an email'], $parsed['rejected']);
    }

    public function test_adding_people_shows_each_new_password_once_and_stores_only_the_hash(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $existing = User::factory()->create(['email' => 'old@example.edu']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => "new@example.edu\nold@example.edu"])->assertSessionHasNoErrors();
        $this->get("/faculty/{$section->id}/roster")->assertInertia(fn (Assert $p) => $p
            ->component('ashby/faculty/Roster')
            ->where('passwords.0.note', 'new')
            ->whereType('passwords.0.password', 'string')
            ->where('passwords.1.note', 'existing')
            ->where('passwords.1.password', null)
            ->has('people', 2));
        $new = User::query()->where('email', 'new@example.edu')->firstOrFail();
        $this->assertTrue($new->isStudent());
        $this->assertNotSame('', $new->password);
        $this->assertFalse(Hash::check('password', $new->password));
        $this->assertSame(2, Enrolment::query()->count());
        $this->assertSame($existing->id, Enrolment::query()->where('user_id', $existing->id)->value('user_id'));
        $this->post("/faculty/{$section->id}/roster/add", ['list' => 'nothing here'])->assertSessionHasErrors('list');
    }

    public function test_the_class_takes_no_more_people_than_its_limit(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor(capacity: 3);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => $this->list(5)]);
        $this->assertSame(3, Enrolment::query()->count());
        $this->get("/faculty/{$section->id}/roster")->assertInertia(fn (Assert $p) => $p->where('passwords.3.note', 'full')->where('passwords.4.note', 'full'));
    }

    public function test_everyone_is_placed_in_teams_of_three_to_five_with_one_chro_each(): void
    {
        foreach ([4 => [4], 8 => [4, 4], 9 => [5, 4], 10 => [5, 5], 11 => [4, 4, 3], 13 => [5, 4, 4], 3 => [3]] as $people => $sizes) {
            ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
            $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => $this->list($people, "s{$section->id}-")]);
            $this->post("/faculty/{$section->id}/roster/teams/form")->assertSessionHasNoErrors();
            $teams = $section->teams()->withCount('members')->get();
            $got = $teams->pluck('members_count')->sortDesc()->values()->all();
            $this->assertSame($sizes, $got, "{$people} people");
            foreach ($teams as $team) {
                $seats = array_count_values($this->seats($team));
                $this->assertSame(1, $seats['chro'] ?? 0, "{$people} people: one CHRO per team");
            }
        }
    }

    public function test_short_teams_are_filled_first_and_one_or_two_left_over_join_teams_as_fifth_members(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => $this->list(8)]);
        $this->post("/faculty/{$section->id}/roster/teams/form");
        $this->post("/faculty/{$section->id}/roster/add", ['list' => $this->list(2, 'late')]);
        $this->post("/faculty/{$section->id}/roster/teams/form");
        $this->assertSame([5, 5], $section->teams()->withCount('members')->pluck('members_count')->all());
        foreach ($section->teams as $team) {
            $this->assertEqualsCanonicalizing(['chro', 'talent', 'learning', 'governance', 'governance'], array_values($this->seats($team)));
        }
    }

    public function test_seats_move_and_a_team_always_keeps_a_chro(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Team 1']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => $this->list(3), 'team_id' => $team->id]);
        $this->assertSame(['p1@example.edu' => 'chro', 'p2@example.edu' => 'talent', 'p3@example.edu' => 'learning'], $this->seats($team));
        $p3 = User::query()->where('email', 'p3@example.edu')->firstOrFail();
        $this->post("/faculty/{$section->id}/roster/people/{$p3->id}/seat", ['seat' => 'chro'])->assertSessionHasNoErrors();
        $this->assertSame(['p1@example.edu' => 'learning', 'p2@example.edu' => 'talent', 'p3@example.edu' => 'chro'], $this->seats($team));
        $this->post("/faculty/{$section->id}/roster/people/{$p3->id}/remove")->assertSessionHasNoErrors();
        $this->assertSame(1, array_count_values($this->seats($team))['chro'] ?? 0);
        $this->assertSame(2, Enrolment::query()->count());
        $this->assertNotNull(User::query()->find($p3->id), 'The login stays.');
    }

    public function test_a_participant_can_be_moved_paused_given_a_new_password_and_removed(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $section->update(['future' => 'rapid', 'open_through' => 2027]);
        $a = Team::query()->create(['section_id' => $section->id, 'name' => 'A']);
        $b = Team::query()->create(['section_id' => $section->id, 'name' => 'B']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => 'one@example.edu', 'team_id' => $a->id]);
        $one = User::query()->where('email', 'one@example.edu')->firstOrFail();

        $this->post("/faculty/{$section->id}/roster/people/{$one->id}/move", ['team_id' => $b->id])->assertSessionHasNoErrors();
        $this->assertSame(['one@example.edu' => 'chro'], $this->seats($b));
        $this->assertSame([], $this->seats($a));

        $this->post("/faculty/{$section->id}/roster/people/{$one->id}/pause", ['paused' => true]);
        $this->actingAs($one)->get('/play')->assertInertia(fn (Assert $p) => $p->component('ashby/NoTeam')->where('message', 'Your place in this class is paused. Ask your instructor.'));
        $this->get('/play/2027')->assertForbidden();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/people/{$one->id}/pause", ['paused' => false]);
        $this->actingAs($one)->get('/play')->assertRedirect('/play/2027');

        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/people/{$one->id}/reset");
        $this->get("/faculty/{$section->id}/roster")->assertInertia(fn (Assert $p) => $p->where('passwords.0.note', 'reset')->whereType('passwords.0.password', 'string'));

        $this->post("/faculty/{$section->id}/roster/people/{$one->id}/remove");
        $this->assertSame(0, Enrolment::query()->count());
        $this->actingAs($one)->get('/play')->assertInertia(fn (Assert $p) => $p->component('ashby/NoTeam'));
    }

    public function test_teams_can_be_added_renamed_and_deleted_only_when_empty_and_unplayed(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/teams");
        $this->post("/faculty/{$section->id}/roster/teams");
        [$one, $two] = $section->teams()->orderBy('id')->get()->all();
        $this->assertSame(['Team 1', 'Team 2'], [$one->name, $two->name]);
        $this->post("/faculty/{$section->id}/roster/teams/{$one->id}", ['name' => 'Team 2'])->assertSessionHasErrors('roster');
        $this->post("/faculty/{$section->id}/roster/teams/{$one->id}", ['name' => 'Northside'])->assertSessionHasNoErrors();
        $this->post("/faculty/{$section->id}/roster/add", ['list' => 'x@example.edu', 'team_id' => $two->id]);
        $this->post("/faculty/{$section->id}/roster/teams/{$two->id}/delete")->assertSessionHasErrors('roster');
        $this->post("/faculty/{$section->id}/roster/teams/{$one->id}/delete")->assertSessionHasNoErrors();
        $this->assertSame(['Team 2'], $section->teams()->pluck('name')->all());
    }

    public function test_the_join_link_makes_a_sign_in_and_enrols_the_participant(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/join-code");
        $code = (string) $section->fresh()?->join_code;
        $this->assertSame(10, strlen($code));
        auth()->logout();

        $this->get("/join/{$code}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('ashby/Join')->where('title', 'Join Workshop')->where('blocked', null));
        $this->post("/join/{$code}", ['name' => 'Jo', 'email' => 'jo@example.edu', 'password' => 'Long-pass-123', 'password_confirmation' => 'nope'])->assertSessionHasErrors('password');
        $this->post("/join/{$code}", ['name' => 'Jo', 'email' => 'jo@example.edu', 'password' => 'Long-pass-123', 'password_confirmation' => 'Long-pass-123'])->assertRedirect('/play');
        $this->assertAuthenticated();
        $jo = User::query()->where('email', 'jo@example.edu')->firstOrFail();
        $this->assertTrue($jo->isStudent());
        $this->assertTrue(Enrolment::query()->where('section_id', $section->id)->where('user_id', $jo->id)->exists());
        $this->get('/play')->assertInertia(fn (Assert $p) => $p->component('ashby/NoTeam')->where('message', "You're not on a team yet. Your instructor will place you on one."));

        // A participant who already has a sign-in joins with one click; an instructor is turned away.
        $other = User::factory()->create();
        $this->actingAs($other)->post("/join/{$code}")->assertRedirect('/play');
        $this->assertSame(2, Enrolment::query()->count());
        $this->actingAs($instructor)->post("/join/{$code}")->assertSessionHasErrors('join');

        // A new link replaces the old one.
        $this->post("/faculty/{$section->id}/roster/join-code");
        auth()->logout();
        $this->get("/join/{$code}")->assertNotFound();
    }

    public function test_a_full_class_turns_the_join_link_away(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor(capacity: 1);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => 'first@example.edu']);
        $this->post("/faculty/{$section->id}/roster/join-code");
        $code = (string) $section->fresh()?->join_code;
        auth()->logout();
        $this->get("/join/{$code}")->assertInertia(fn (Assert $p) => $p->where('blocked', 'This class is full. Ask your instructor.'));
        $this->post("/join/{$code}", ['name' => 'Late', 'email' => 'late@example.edu', 'password' => 'Long-pass-123', 'password_confirmation' => 'Long-pass-123'])->assertSessionHasErrors('join');
        $this->assertSame(1, Enrolment::query()->count());
    }

    public function test_a_co_instructor_runs_the_class_and_other_instructors_cannot_open_it(): void
    {
        ['section' => $section] = $this->classWithInstructor();
        $co = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $section->staff()->attach($co->id, ['role' => 'co']);
        $this->actingAs($co)->get("/faculty/{$section->id}/roster")->assertOk();
        $this->post("/faculty/{$section->id}/roster/add", ['list' => 'c@example.edu'])->assertSessionHasNoErrors();
        $stranger = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($stranger)->get("/faculty/{$section->id}/roster")->assertForbidden();
        $this->post("/faculty/{$section->id}/roster/add", ['list' => 'd@example.edu'])->assertForbidden();
        $this->get("/faculty/{$section->id}/export/results.csv")->assertForbidden();
        $this->actingAs(User::factory()->create())->get("/faculty/{$section->id}/roster")->assertForbidden();
    }

    public function test_the_spreadsheets_hold_the_class_record_and_the_paid_mark_blocks_nothing(): void
    {
        ['section' => $section, 'instructor' => $instructor] = $this->classWithInstructor();
        $section->update(['future' => 'rapid', 'open_through' => 2031]);
        $team = Team::query()->create(['section_id' => $section->id, 'name' => 'Builders']);
        $this->actingAs($instructor)->post("/faculty/{$section->id}/roster/add", ['list' => "Chi Ro <chro@example.edu>\nTal Ent <talent@example.edu>", 'team_id' => $team->id]);
        $chro = User::query()->where('email', 'chro@example.edu')->firstOrFail();
        $this->post("/faculty/{$section->id}/roster/people/{$chro->id}/paid", ['paid' => true]);

        $this->actingAs($chro);
        foreach (ReferenceTeams::cards('builder') as $year => $cards) {
            $this->post("/play/{$year}/confirm", $cards)->assertRedirect();
            $this->post("/play/{$year}/paragraph", ['paragraph' => '=SUM(A1) we expect slow change'])->assertSessionHasNoErrors();
        }

        $this->actingAs($instructor);
        $results = $this->get("/faculty/{$section->id}/export/results.csv")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $results)));
        $this->assertStringStartsWith('Team,Year,"Decision year"', $lines[0]);
        $this->assertCount(1 + 9, $lines, 'One row for each year 2027 to 2035.');
        $this->assertStringStartsWith('Builders,2027,2027,"Chief HR Officer"', $lines[1]);
        $this->assertStringContainsString('Outage', $results);

        $grades = $this->get("/faculty/{$section->id}/export/gradebook.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('"Chi Ro",chro@example.edu,Builders,"Chief HR Officer",active,yes,3,3,', $grades);
        $this->assertStringContainsString('"Tal Ent",talent@example.edu,Builders,"Head of Talent",active,no,3,3,', $grades);
    }

    public function test_a_cell_a_spreadsheet_would_run_is_made_plain_text(): void
    {
        $this->assertSame("'=1+1,\"'+cmd\",ok\n", ResultsExport::csv([['=1+1', '+cmd', 'ok']]));
    }
}
