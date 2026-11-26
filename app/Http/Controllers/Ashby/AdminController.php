<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Admin\ClassFactory;
use App\Ashby\Content\ContentPack;
use App\Ashby\Game\PlayState;
use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The administrator's page: instructor logins, classes, and who teaches which class.
 */
class AdminController extends Controller
{
    public function __construct(private readonly ContentPack $content, private readonly ClassFactory $factory) {}

    public function index(Request $request): Response
    {
        $this->admin($request);
        $words = $this->content->file('faculty');
        $classes = [];
        foreach (Section::query()->with('staff')->withCount('enrolments')->orderBy('id')->get() as $section) {
            $lead = $section->staff->first(fn (User $u): bool => $u->pivot?->getAttribute('role') === 'owner');
            $classes[] = [
                'id' => $section->id,
                'name' => $section->name,
                'format' => $section->format,
                'length' => $section->length,
                'lead' => $lead === null ? null : ['id' => $lead->id, 'name' => $lead->name],
                'co' => $section->staff->filter(fn (User $u): bool => $u->pivot?->getAttribute('role') === 'co')->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
                'people' => (int) $section->getAttribute('enrolments_count').($section->capacity === null ? '' : ' / '.$section->capacity),
                'open_through' => $section->open_through,
                'can_delete' => $section->open_through < 2027,
            ];
        }

        return Inertia::render('ashby/faculty/Admin', [
            'classes' => $classes,
            'instructors' => User::query()->whereIn('role', [User::ROLE_FACULTY, User::ROLE_ADMIN])->orderBy('name')->get()
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role])->all(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'passwords' => $request->session()->get('one_time_passwords'),
            'words' => ['faculty' => $words, 'common' => $this->content->file('common')],
        ]);
    }

    public function storeLogin(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190']]);
        $made = $this->attempt(fn () => $this->factory->login((string) $data['name'], (string) $data['email'], User::ROLE_FACULTY));

        return back()->with('one_time_passwords', [['email' => $made['user']->email, 'name' => $made['user']->name, 'password' => $made['password'], 'note' => 'new']]);
    }

    public function storeClass(Request $request): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'format' => ['required', Rule::in(ClassFactory::FORMATS)],
            'length' => ['nullable', Rule::in(PlayState::LENGTHS)],
            'instructor_id' => ['required', 'integer'],
            'teams' => ['nullable', 'integer', 'between:0,40'],
            'capacity' => ['nullable', 'integer', 'between:1,2000'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);
        $this->attempt(fn () => $this->factory->create(
            (string) $data['name'], (string) $data['format'], $this->instructor((int) $data['instructor_id']),
            (int) ($data['teams'] ?? 0), isset($data['capacity']) ? (int) $data['capacity'] : null, (string) ($data['timezone'] ?? 'America/New_York'), (string) ($data['length'] ?? 'slice'),
        ));

        return back();
    }

    public function assign(Request $request, Section $section): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['instructor_id' => ['required', 'integer']]);
        $this->factory->setLead($section, $this->instructor((int) $data['instructor_id']));

        return back();
    }

    public function addCo(Request $request, Section $section): RedirectResponse
    {
        $this->admin($request);
        $data = $request->validate(['instructor_id' => ['required', 'integer']]);
        $this->factory->addCo($section, $this->instructor((int) $data['instructor_id']));

        return back();
    }

    public function removeCo(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->admin($request);
        $this->factory->removeCo($section, $user);

        return back();
    }

    public function destroy(Request $request, Section $section): RedirectResponse
    {
        $this->admin($request);
        $this->attempt(fn () => $this->factory->delete($section));

        return back();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     */
    private function attempt(callable $change): mixed
    {
        try {
            return $change();
        } catch (RuntimeException $e) {
            $errors = (array) $this->content->file('faculty')['errors'];
            throw ValidationException::withMessages(['admin' => (string) ($errors[$e->getMessage()] ?? $e->getMessage())]);
        }
    }

    private function instructor(int $id): User
    {
        return User::query()->whereIn('role', [User::ROLE_FACULTY, User::ROLE_ADMIN])->findOrFail($id);
    }

    private function admin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);
    }
}
