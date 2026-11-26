<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Admin\ResultsExport;
use App\Ashby\Admin\Roster;
use App\Ashby\Content\ContentPack;
use App\Http\Controllers\Controller;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A class's people and teams, for its instructors: add people, the join link, teams and seats,
 * pause, paid, new passwords, take off, and the two spreadsheets.
 */
class RosterController extends Controller
{
    public function __construct(
        private readonly ContentPack $content,
        private readonly Roster $roster,
        private readonly ResultsExport $export,
    ) {}

    public function show(Request $request, Section $section): Response
    {
        $user = $this->authorizeFor($request, $section);
        $words = $this->content->file('faculty');
        $teams = [];
        $placed = [];
        foreach ($section->teams()->with('members')->orderBy('id')->get() as $team) {
            $members = [];
            foreach ($team->members->sortBy(fn (User $m) => array_search((string) $m->pivot?->getAttribute('seat'), Roster::SEATS, true)) as $member) {
                $placed[$member->id] = ['team' => $team->id, 'seat' => (string) $member->pivot?->getAttribute('seat')];
                $members[] = $member->id;
            }
            $teams[] = [
                'id' => $team->id,
                'name' => $team->name,
                'members' => $members,
                'can_delete' => $members === [] && ! TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->exists(),
                'size_note' => $members !== [] && (count($members) < Roster::SMALLEST_TEAM || count($members) > Roster::LARGEST_TEAM),
            ];
        }
        $people = [];
        foreach ($section->enrolments()->with('user')->get()->sortBy(fn (Enrolment $e) => $e->user->name) as $enrolment) {
            $people[] = [
                'id' => $enrolment->user_id,
                'name' => $enrolment->user->name,
                'email' => $enrolment->user->email,
                'paused' => $enrolment->status === Enrolment::PAUSED,
                'paid' => $enrolment->paid_at !== null,
                'team' => $placed[$enrolment->user_id]['team'] ?? null,
                'seat' => $placed[$enrolment->user_id]['seat'] ?? null,
            ];
        }
        $instructors = $section->staff()->orderBy('section_staff.id')->get()
            ->map(fn (User $u): array => ['name' => $u->name, 'email' => $u->email, 'lead' => $u->pivot?->getAttribute('role') === 'owner'])->all();

        return Inertia::render('ashby/faculty/Roster', [
            'section' => ['id' => $section->id, 'name' => $section->name, 'capacity' => $section->capacity],
            'isAdmin' => $user->isAdmin(),
            'teams' => $teams,
            'people' => $people,
            'seats' => Roster::SEATS,
            'counts' => ['participants' => count($people), 'placed' => count($placed), 'teams' => count($teams)],
            'joinLink' => $section->join_code === null ? null : route('join.show', ['code' => $section->join_code]),
            'instructors' => $instructors,
            'passwords' => $request->session()->get('one_time_passwords'),
            'notice' => $request->session()->get('roster_notice'),
            'words' => ['faculty' => $words, 'common' => $this->content->file('common')],
        ]);
    }

    public function add(Request $request, Section $section): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['list' => ['required', 'string', 'max:60000'], 'team_id' => ['nullable', 'integer']]);
        $team = ($data['team_id'] ?? null) === null ? null : $this->team($section, (int) $data['team_id']);
        $parsed = Roster::parse((string) $data['list']);
        if ($parsed['people'] === []) {
            throw ValidationException::withMessages(['list' => $this->error('no_email')]);
        }
        $added = $this->attempt(fn () => $this->roster->add($section, $parsed['people'], $team));
        $back = back()->with('one_time_passwords', $added);
        if ($parsed['rejected'] !== []) {
            $back->with('roster_notice', str_replace('{lines}', Str::limit(implode(' | ', $parsed['rejected']), 400), (string) $this->content->file('faculty')['roster']['add']['rejected']));
        }

        return $back;
    }

    public function formTeams(Request $request, Section $section): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $this->roster->formTeams($section);

        return back();
    }

    public function newTeam(Request $request, Section $section): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $this->roster->newTeam($section);

        return back();
    }

    public function renameTeam(Request $request, Section $section, Team $team): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $this->attempt(fn () => $this->roster->renameTeam($section, $team, (string) $data['name']));

        return back();
    }

    public function deleteTeam(Request $request, Section $section, Team $team): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $this->attempt(fn () => $this->roster->deleteTeam($section, $team));

        return back();
    }

    public function move(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['team_id' => ['nullable', 'integer']]);
        $team = ($data['team_id'] ?? null) === null ? null : $this->team($section, (int) $data['team_id']);
        $this->attempt(fn () => $this->roster->move($section, $user, $team));

        return back();
    }

    public function seat(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['seat' => ['required', Rule::in(Roster::SEATS)]]);
        $this->attempt(fn () => $this->roster->setSeat($section, $user, (string) $data['seat']));

        return back();
    }

    public function pause(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $this->attempt(fn () => $this->roster->pause($section, $user, (bool) $data['paused']));

        return back();
    }

    public function paid(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['paid' => ['required', 'boolean']]);
        $this->attempt(fn () => $this->roster->markPaid($section, $user, (bool) $data['paid']));

        return back();
    }

    public function remove(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $this->attempt(fn () => $this->roster->remove($section, $user));

        return back();
    }

    public function reset(Request $request, Section $section, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $password = $this->attempt(fn () => $this->roster->resetPassword($section, $user));

        return back()->with('one_time_passwords', [['email' => $user->email, 'name' => $user->name, 'password' => $password, 'note' => 'reset']]);
    }

    public function joinCode(Request $request, Section $section): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $this->roster->newJoinCode($section);

        return back();
    }

    public function capacity(Request $request, Section $section): RedirectResponse
    {
        $this->authorizeFor($request, $section);
        $data = $request->validate(['capacity' => ['nullable', 'integer', 'between:1,2000']]);
        $section->update(['capacity' => $data['capacity'] ?? null]);

        return back();
    }

    public function export(Request $request, Section $section, string $kind): StreamedResponse
    {
        $this->authorizeFor($request, $section);
        $csv = ResultsExport::csv($kind === 'results' ? $this->export->results($section) : $this->export->gradebook($section));

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, Str::slug('future-chro '.$section->name).'-'.$kind.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function team(Section $section, int $id): Team
    {
        return Team::query()->where('section_id', $section->id)->findOrFail($id);
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
            throw ValidationException::withMessages(['roster' => $this->error($e->getMessage())]);
        }
    }

    private function error(string $key): string
    {
        $errors = (array) $this->content->file('faculty')['errors'];

        return (string) ($errors[$key] ?? $key);
    }

    private function authorizeFor(Request $request, Section $section): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $section->isStaff($user), 403);

        return $user;
    }
}
