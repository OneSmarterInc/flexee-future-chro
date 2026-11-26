<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Admin\Calendar;
use App\Ashby\Ai\Replies;
use App\Ashby\Ai\Writer;
use App\Ashby\Content\ContentPack;
use App\Ashby\Engine\ModelData;
use App\Ashby\Faculty\Board;
use App\Ashby\Faculty\BoardView;
use App\Ashby\Faculty\SampleClass;
use App\Ashby\Game\PlayState;
use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The instructor's board: classes, the future, opening and closing years, team rows, threads and the replay.
 */
class FacultyController extends Controller
{
    /** The AI features an instructor can switch off for a class (AI2). */
    public const AI_FEATURES = ['table', 'paragraph', 'reading', 'debrief'];

    public function __construct(
        private readonly Board $board,
        private readonly BoardView $view,
        private readonly ContentPack $content,
        private readonly Calendar $calendar,
        private readonly Writer $writer,
        private readonly Replies $replies,
    ) {}

    public function home(Request $request): Response
    {
        $user = $this->staffUser($request);
        $sections = $user->isAdmin() ? Section::query()->withCount('teams')->orderByDesc('id')->get() : $user->sections()->withCount('teams')->orderByDesc('sections.id')->get();

        return Inertia::render('ashby/faculty/Home', [
            'sections' => $sections->map(fn (Section $s): array => ['id' => $s->id, 'name' => $s->name, 'teams' => (int) $s->getAttribute('teams_count')])->values()->all(),
            'futures' => ModelData::FUTURES,
            'words' => $this->words(),
        ]);
    }

    public function sample(Request $request, SampleClass $sample): RedirectResponse
    {
        $user = $this->staffUser($request);
        $data = $request->validate(['future' => ['required', 'in:'.implode(',', ModelData::FUTURES)]]);
        $section = $sample->make($user, (string) $data['future']);

        return to_route('faculty.board', ['section' => $section->id, 'year' => max($section->years())]);
    }

    public function show(Request $request, Section $section, ?int $year = null): Response
    {
        $this->staff($request, $section);
        $year ??= $this->currentYear($section);
        abort_unless(in_array($year, $section->years(), true), 404);
        $teams = $this->view->teams($section, $year);
        $user = $this->staffUser($request);
        $classes = $user->isAdmin() ? Section::query()->orderByDesc('id')->get(['id', 'name']) : $user->sections()->orderByDesc('sections.id')->get(['sections.id', 'sections.name']);

        return Inertia::render('ashby/faculty/Board', [
            'section' => [
                'id' => $section->id, 'name' => $section->name, 'future' => $section->future, 'future_drawn' => $section->future_drawn,
                'future_locked' => $this->board->anyConfirmed($section), 'format' => $section->format, 'timezone' => $section->timezone,
                'auto_run' => $section->auto_run,
            ],
            'calendar' => $this->calendar->localTimes($section),
            'ai' => [
                'available' => $this->writer->available(),
                'features' => array_map(fn (string $f): array => ['id' => $f, 'on' => $section->aiOn($f)], self::AI_FEATURES),
                'reading' => SectionYear::query()->where('section_id', $section->id)->where('year', $year)->first(['reading', 'reading_note'])?->only(['reading', 'reading_note']),
                'debrief' => ['text' => $section->debrief, 'note' => $section->debrief_note],
            ],
            'isAdmin' => $user->isAdmin(),
            'classes' => $classes->map(fn (Section $s): array => ['id' => $s->id, 'name' => $s->name])->values()->all(),
            'year' => $year,
            'years' => array_map(fn (int $y): array => ['year' => $y, 'state' => $this->board->state($section, $y)], $section->years()),
            'teams' => $teams,
            'panel' => $this->view->panel($teams, $year),
            'replay' => $year === max($section->years()) ? $this->view->replay($section) : null,
            'lastYear' => max($section->years()),
            'futures' => ModelData::FUTURES,
            'words' => $this->words(),
        ]);
    }

    public function future(Request $request, Section $section): RedirectResponse
    {
        $this->staff($request, $section);
        $data = $request->validate(['future' => ['required', 'in:draw,'.implode(',', ModelData::FUTURES)]]);

        return $this->attempt(fn () => $this->board->setFuture($section, (string) $data['future']));
    }

    public function open(Request $request, Section $section, int $year): RedirectResponse
    {
        $this->staff($request, $section);

        return $this->attempt(fn () => $this->board->open($section, $year), $section, $year);
    }

    public function close(Request $request, Section $section, int $year): RedirectResponse
    {
        $this->staff($request, $section);
        $user = $this->staffUser($request);

        return $this->attempt(fn () => $this->board->close($section, $year, $user), $section, $year);
    }

    public function reopen(Request $request, Section $section, Team $team, int $year): RedirectResponse
    {
        $this->staff($request, $section);
        abort_unless($team->section_id === $section->id, 404);

        return $this->attempt(fn () => $this->board->reopen($team, $year), $section, $year);
    }

    public function saveCalendar(Request $request, Section $section): RedirectResponse
    {
        $this->staff($request, $section);
        $data = $request->validate([
            'years' => ['array'],
            'years.*.opens_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'years.*.closes_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'auto_run' => ['required', 'boolean'],
        ]);
        /** @var array<int|string, array{opens_at?: string|null, closes_at?: string|null}> $years */
        $years = (array) ($data['years'] ?? []);

        return $this->attempt(fn () => $this->calendar->save($section, $years, (bool) $data['auto_run']));
    }

    public function fillCalendar(Request $request, Section $section): RedirectResponse
    {
        $this->staff($request, $section);
        $data = $request->validate(['first' => ['required', 'date_format:Y-m-d\TH:i']]);

        return $this->attempt(fn () => $this->calendar->fill($section, (string) $data['first']));
    }

    /** Turns one AI feature on or off for this class. */
    public function switchAi(Request $request, Section $section): RedirectResponse
    {
        $this->staff($request, $section);
        $data = $request->validate(['feature' => ['required', 'in:'.implode(',', self::AI_FEATURES)], 'on' => ['required', 'boolean']]);
        $off = array_values(array_diff($section->ai_off ?? [], [(string) $data['feature']]));
        if (! (bool) $data['on']) {
            $off[] = (string) $data['feature'];
        }
        $section->update(['ai_off' => $off]);

        return back();
    }

    public function reading(Request $request, Section $section, int $year): RedirectResponse
    {
        $this->staff($request, $section);

        return $this->attempt(fn () => $this->replies->reading($section, $year));
    }

    public function debrief(Request $request, Section $section): RedirectResponse
    {
        $this->staff($request, $section);

        return $this->attempt(fn () => $this->replies->debrief($section));
    }

    /** The participant's screen for one team and year, view only. */
    public function asTeam(Request $request, Section $section, Team $team, int $year, PlayController $play): Response
    {
        $this->staff($request, $section);
        abort_unless($team->section_id === $section->id, 404);
        $row = TeamYear::query()->where('team_id', $team->id)->where('year', $year)->first();
        abort_if($row === null, 404);

        return Inertia::render('ashby/Play', $play->playProps($team, $row, $year, '', viewOnly: true));
    }

    private function attempt(callable $action, ?Section $section = null, ?int $year = null): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $errors = (array) $this->content->file('faculty')['errors'];

            return back()->withErrors(['board' => (string) ($errors[$e->getMessage()] ?? $e->getMessage())]);
        }

        return $section !== null && $year !== null ? to_route('faculty.board', ['section' => $section->id, 'year' => $year]) : back();
    }

    private function currentYear(Section $section): int
    {
        foreach ($section->years() as $y) {
            if ($y > $section->closed_through) {
                return $y;
            }
        }

        return max($section->years());
    }

    private function staffUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->isFaculty() || $user->isAdmin()), 403);

        return $user;
    }

    private function staff(Request $request, Section $section): void
    {
        abort_unless($section->isStaff($this->staffUser($request)), 403);
    }

    /** @return array<string, mixed> */
    private function words(): array
    {
        return ['faculty' => $this->content->file('faculty'), 'common' => $this->content->file('common')];
    }
}
