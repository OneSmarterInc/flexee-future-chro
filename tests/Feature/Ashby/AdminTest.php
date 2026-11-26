<?php

namespace Tests\Feature\Ashby;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_only_an_administrator_opens_the_admin_page(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertInertia(fn (Assert $p) => $p->component('ashby/faculty/Admin'));
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_an_instructor_cannot_do_any_of_the_administrator_actions(): void
    {
        $section = Section::query()->create(['name' => 'A class']);
        $other = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]));
        $this->post('/admin/logins', ['name' => 'X', 'email' => 'x@example.edu'])->assertForbidden();
        $this->post('/admin/classes', ['name' => 'X', 'format' => 'workshop', 'instructor_id' => $other->id])->assertForbidden();
        $this->post("/admin/classes/{$section->id}/lead", ['instructor_id' => $other->id])->assertForbidden();
        $this->post("/admin/classes/{$section->id}/co", ['instructor_id' => $other->id])->assertForbidden();
        $this->post("/admin/classes/{$section->id}/co/{$other->id}/remove")->assertForbidden();
        $this->post("/admin/classes/{$section->id}/delete")->assertForbidden();
        $this->assertSame(1, Section::query()->count());
    }

    public function test_a_new_instructor_login_shows_its_password_once_and_stores_only_the_hash(): void
    {
        $this->actingAs($this->admin())->post('/admin/logins', ['name' => 'Pat Lee', 'email' => 'Pat@Example.edu'])->assertSessionHasNoErrors();
        $this->get('/admin')->assertInertia(function (Assert $p) {
            $p->has('passwords', 1)->where('passwords.0.email', 'pat@example.edu')->where('passwords.0.note', 'new');

            return $p;
        });
        $user = User::query()->where('email', 'pat@example.edu')->firstOrFail();
        $this->assertTrue($user->isFaculty());
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('passwords', null));
        $this->post('/admin/logins', ['name' => 'Again', 'email' => 'pat@example.edu'])->assertSessionHasErrors('admin');
    }

    public function test_a_class_is_made_with_its_lead_and_teams(): void
    {
        $lead = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($this->admin())->post('/admin/classes', ['name' => 'EMBA spring', 'format' => 'term', 'instructor_id' => $lead->id, 'teams' => 5, 'capacity' => 24, 'timezone' => 'Europe/London'])->assertSessionHasNoErrors();
        $section = Section::query()->firstOrFail();
        $this->assertSame('term', $section->format);
        $this->assertTrue($section->auto_run);
        $this->assertSame('Europe/London', $section->timezone);
        $this->assertSame(24, $section->capacity);
        $this->assertSame(5, $section->teams()->count());
        $this->assertSame($lead->id, $section->owner()?->id);
        $this->assertNull($section->future);
        $this->assertSame(2026, $section->open_through);
    }

    public function test_instructors_are_changed_added_and_taken_off(): void
    {
        $a = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $b = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $c = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($this->admin())->post('/admin/classes', ['name' => 'W', 'format' => 'workshop', 'instructor_id' => $a->id]);
        $section = Section::query()->firstOrFail();
        $this->post("/admin/classes/{$section->id}/co", ['instructor_id' => $b->id]);
        $this->post("/admin/classes/{$section->id}/lead", ['instructor_id' => $c->id]);
        $this->assertSame($c->id, $section->owner()?->id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $section->staff()->wherePivot('role', 'co')->pluck('users.id')->all());
        $this->post("/admin/classes/{$section->id}/co/{$b->id}/remove");
        $this->assertFalse($section->isStaff($b));
        $this->assertTrue($section->isStaff($a));
        $this->actingAs($b)->get("/faculty/{$section->id}")->assertForbidden();
        $this->actingAs($a)->get("/faculty/{$section->id}")->assertOk();
    }

    public function test_a_class_is_deleted_only_before_2027_opens(): void
    {
        $admin = $this->admin();
        $lead = User::factory()->create(['role' => User::ROLE_FACULTY]);
        $this->actingAs($admin)->post('/admin/classes', ['name' => 'One', 'format' => 'workshop', 'instructor_id' => $lead->id, 'teams' => 2]);
        $this->post('/admin/classes', ['name' => 'Two', 'format' => 'workshop', 'instructor_id' => $lead->id]);
        [$one, $two] = Section::query()->orderBy('id')->get()->all();
        $two->update(['future' => 'rapid', 'open_through' => 2027]);
        $this->post("/admin/classes/{$one->id}/delete")->assertSessionHasNoErrors();
        $this->post("/admin/classes/{$two->id}/delete")->assertSessionHasErrors('admin');
        $this->assertSame([$two->id], Section::query()->pluck('id')->all());
    }

    public function test_an_administrator_with_no_class_starts_on_the_admin_page(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertRedirect('/admin');
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FACULTY]))->get('/dashboard')->assertRedirect('/faculty');
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertRedirect('/play');
    }

    public function test_the_command_makes_an_administrator_with_a_random_password(): void
    {
        $this->artisan('ashby:admin', ['--email' => 'Head@Example.edu'])->assertSuccessful();
        $user = User::query()->where('email', 'head@example.edu')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertFalse(Hash::check('password', $user->password));
        $this->artisan('ashby:admin', ['--email' => 'head@example.edu'])->assertFailed();
        $this->artisan('ashby:admin', ['--email' => 'not-an-email'])->assertFailed();
    }
}
