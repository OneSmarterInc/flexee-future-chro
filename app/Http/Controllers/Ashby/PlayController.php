<?php

namespace App\Http\Controllers\Ashby;

use App\Ashby\Admin\Calendar;
use App\Ashby\Content\ContentPack;
use App\Ashby\Game\Cards;
use App\Ashby\Game\PlayState;
use App\Ashby\Game\TableTalk;
use App\Http\Controllers\Controller;
use App\Models\Enrolment;
use App\Models\TableMessage;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The participant's loop: brief, table, one moment, the four cards, results, and the replay.
 */
class PlayController extends Controller
{
    public function __construct(private readonly PlayState $state, private readonly ContentPack $content) {}

    /** Sends a participant to the first year they still have to play, or to the replay. */
    public function home(Request $request): RedirectResponse|Response
    {
        $team = $this->team($request);
        if ($team === null) {
            $words = $this->content->file('common');

            return Inertia::render('ashby/NoTeam', ['words' => $words, 'message' => (string) $words['no_team']]);
        }
        $words = $this->content->file('common');
        if ($this->paused($request, $team)) {
            return Inertia::render('ashby/NoTeam', ['words' => $words, 'message' => (string) $words['paused']]);
        }
        foreach ($this->state->years($team) as $year) {
            if (! $year['confirmed']) {
                if (! $year['open']) {
                    $at = Calendar::opensLabel($team->section, $year['year']);
                    $text = $at === null ? (string) $words['waiting'] : (string) $words['waiting_at'];

                    return Inertia::render('ashby/NoTeam', ['words' => $words, 'message' => str_replace(['{year}', '{time}'], [(string) $year['year'], (string) $at], $text)]);
                }

                return to_route('play.year', ['year' => $year['year']]);
            }
        }

        return to_route('play.replay');
    }

    public function show(Request $request, int $year): Response|RedirectResponse
    {
        [$team, $row] = $this->playable($request, $year);

        return Inertia::render('ashby/Play', $this->playProps($team, $row, $year, $this->seat($request, $team)));
    }

    /**
     * Everything the play screen needs for one team and year. Staff see the same screen with viewOnly set.
     *
     * @return array<string, mixed>
     */
    public function playProps(Team $team, TeamYear $row, int $year, string $seat, bool $viewOnly = false): array
    {
        $years = $this->content->file('years');
        $people = [];
        $messages = TableMessage::query()->where('team_id', $team->id)->where('year', $year)->orderBy('id')->get();
        foreach ($this->person($years, $year) as $key => $person) {
            $people[] = [
                'key' => $key,
                'name' => $years['people'][$key]['name'],
                'role' => $years['people'][$key]['role'],
                'opens' => $person['opens'],
                'suggested' => $person['suggested'],
                'messages' => $messages->where('person', $key)->map(fn (TableMessage $m): array => ['question' => $m->question, 'answer' => $m->answer, 'new' => $m->revealed !== null])->values()->all(),
            ];
        }
        $plan = $years['plans'][(string) $year];
        $checks = $row->checks ?? [];
        $plan['checks'] = array_map(fn (array $check): array => [
            'id' => $check['id'], 'minutes' => $check['minutes'], 'label' => $check['label'],
            'found' => in_array($check['id'], $checks, true) ? $check['found'] : null,
        ], $plan['checks']);

        $standing = null;
        if ($year > 2027) {
            $rows = $this->state->rows($team, $year - 1);
            $standing = $this->figures($rows[count($rows) - 1]);
        }
        $results = null;
        if ($row->confirmed_at !== null) {
            $rows = $this->state->rows($team, min($year + 1, PlayState::LAST_YEAR));
            $ran = array_values(array_filter($rows, fn (array $r): bool => $r['year'] >= $year));
            $results = ['years' => array_map(fn (array $r): array => $this->figures($r), $ran)];
        }

        return [
            'year' => $year,
            'years' => $this->state->years($team),
            'team' => ['name' => $team->name, 'seat' => $seat],
            'viewOnly' => $viewOnly,
            'words' => [
                'common' => $this->content->file('common'),
                'brief' => $years['briefs'][(string) $year],
                'signals_title' => $years['signals_title'],
                'signals' => $years['signals'][(string) $year],
                'moment' => $years['moments'][(string) $year],
                'cards' => $this->content->file('cards'),
                'results' => $this->content->file('results'),
            ],
            'people' => $people,
            'plan' => $plan,
            'minutesLeft' => TableTalk::MINUTES - $row->minutes_used,
            'checkingUsed' => $row->checking_used,
            'cards' => $row->cards ?? Cards::defaults(),
            'moment' => $row->moment,
            'paragraph' => $row->paragraph,
            'confirmed' => $row->confirmed_at !== null,
            'standing' => $standing,
            'results' => $results,
        ];
    }

    public function ask(Request $request, int $year): RedirectResponse
    {
        [$team, $row] = $this->playable($request, $year, open: true);
        $data = $request->validate(['person' => ['required', 'string'], 'question' => ['required', 'string', 'max:500']]);
        $years = $this->content->file('years');
        $people = $this->person($years, $year);
        abort_unless(isset($people[$data['person']]), 404);
        $asked = TableMessage::query()->where('team_id', $team->id)->where('year', $year)->where('person', $data['person'])->count();
        if ($asked >= TableTalk::QUESTIONS_PER_PERSON || $row->minutes_used + 1 > TableTalk::MINUTES) {
            return back()->withErrors(['question' => $this->line('common', 'table', 'no_time')]);
        }
        $reply = TableTalk::answer($people[$data['person']], $data['question'], $row->revealed ?? [], $asked);
        TableMessage::query()->create([
            'team_id' => $team->id, 'user_id' => $request->user()?->id, 'year' => $year, 'person' => $data['person'],
            'question' => $data['question'], 'answer' => $reply['answer'], 'revealed' => $reply['revealed'],
        ]);
        $row->minutes_used += 1;
        if ($reply['revealed'] !== null) {
            $row->revealed = array_merge($row->revealed ?? [], [$reply['revealed']]);
        }
        $row->save();

        return back();
    }

    public function check(Request $request, int $year): RedirectResponse
    {
        [, $row] = $this->playable($request, $year, open: true);
        $data = $request->validate(['check' => ['required', 'string']]);
        $plan = $this->content->file('years')['plans'][(string) $year];
        $check = null;
        foreach ((array) ($plan['checks'] ?? []) as $candidate) {
            if (is_array($candidate) && ($candidate['id'] ?? null) === $data['check']) {
                $check = $candidate;
            }
        }
        abort_unless(is_array($check), 404);
        $done = $row->checks ?? [];
        if (in_array($check['id'], $done, true)) {
            return back();
        }
        $minutes = (int) $check['minutes'];
        if ($row->checking_used + $minutes > TableTalk::CHECKING_MINUTES || $row->minutes_used + $minutes > TableTalk::MINUTES) {
            return back()->withErrors(['check' => $this->line('common', 'table', 'no_time')]);
        }
        $row->checks = array_merge($done, [$check['id']]);
        $row->checking_used += $minutes;
        $row->minutes_used += $minutes;
        $row->save();

        return back();
    }

    public function moment(Request $request, int $year): RedirectResponse
    {
        [, $row] = $this->playable($request, $year, open: true);
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:4000'],
            'offers' => [$year === 2027 ? 'required' : 'nullable', 'numeric', 'between:0,'.Cards::USUAL_OFFERS],
            'planning' => [$year === 2029 ? 'required' : 'nullable', 'in:hr,it,joint'],
        ]);
        $cards = $row->cards ?? Cards::defaults();
        if ($year === 2027) {
            $cards['offers'] = (float) $data['offers'];
        }
        if ($year === 2029) {
            $cards['planning'] = (string) $data['planning'];
        }
        $row->cards = $cards;
        $row->moment = $data['text'] ?? null;
        $row->save();

        return back();
    }

    public function cards(Request $request, int $year): RedirectResponse
    {
        [, $row] = $this->playable($request, $year, open: true);
        /** @var array<string, mixed> $data */
        $data = $request->validate(Cards::rules());
        $row->cards = array_merge(Cards::defaults(), $data);
        $row->save();

        return back();
    }

    public function confirm(Request $request, int $year): RedirectResponse
    {
        [$team, $row] = $this->playable($request, $year, open: true);
        $seat = $this->seat($request, $team);
        if ($seat !== 'chro') {
            $seats = $this->content->file('common')['seats'];

            return back()->withErrors(['confirm' => str_replace('{seat}', (string) ($seats[$seat] ?? $seat), $this->line('cards', 'confirm_only_chro'))]);
        }
        /** @var array<string, mixed> $data */
        $data = $request->validate(Cards::rules());
        $row->cards = array_merge(Cards::defaults(), $data);
        $row->confirmed_at = now();
        $row->confirmed_by = $request->user()?->id;
        $row->save();

        return to_route('play.year', ['year' => $year, 'step' => 4]);
    }

    public function paragraph(Request $request, int $year): RedirectResponse
    {
        [, $row] = $this->playable($request, $year);
        $data = $request->validate(['paragraph' => ['nullable', 'string', 'max:4000']]);
        $row->paragraph = $data['paragraph'] ?? null;
        $row->save();

        return back();
    }

    public function replay(Request $request): Response|RedirectResponse
    {
        $team = $this->team($request);
        abort_if($team === null || $this->paused($request, $team), 403);
        if (count($this->state->confirmedYears($team)) < count(PlayState::YEARS)) {
            return to_route('play');
        }
        $paragraphs = TeamYear::query()->where('team_id', $team->id)->orderBy('year')->pluck('paragraph', 'year')->all();

        return Inertia::render('ashby/Replay', [
            'team' => ['name' => $team->name],
            'future' => $team->section->future,
            'replay' => $this->state->replay($team),
            'paragraphs' => $paragraphs,
            'words' => ['common' => $this->content->file('common'), 'replay' => $this->content->file('replay')],
        ]);
    }

    /**
     * The participant's team and its row for a year they may play. Open means it can still be changed.
     *
     * @return array{0: Team, 1: TeamYear}
     */
    private function playable(Request $request, int $year, bool $open = false): array
    {
        $team = $this->team($request);
        abort_if($team === null || $this->paused($request, $team), 403);
        $entry = collect($this->state->years($team))->firstWhere('year', $year);
        abort_unless(is_array($entry) && $entry['open'], 404);
        $row = $this->state->yearFor($team, $year);
        abort_if($open && $row->confirmed_at !== null, 409);

        return [$team, $row];
    }

    /** A paused participant sees a plain page; their team plays on. */
    private function paused(Request $request, Team $team): bool
    {
        return Enrolment::query()->where('section_id', $team->section_id)->where('user_id', $request->user()?->id)->where('status', Enrolment::PAUSED)->exists();
    }

    private function team(Request $request): ?Team
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        return $user->teams()->with('section')->orderBy('teams.id')->first();
    }

    private function seat(Request $request, Team $team): string
    {
        $member = $team->members()->where('users.id', $request->user()?->id)->first();

        return (string) ($member?->pivot?->getAttribute('seat') ?? '');
    }

    /**
     * The people at the table in a year.
     *
     * @param  array<string, mixed>  $years
     * @return array<string, array<string, mixed>>
     */
    private function person(array $years, int $year): array
    {
        /** @var array<string, array<string, mixed>> $people */
        $people = $years['talk'][(string) $year];

        return $people;
    }

    private function line(string $file, string ...$path): string
    {
        $value = $this->content->file($file);
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? '') : '';
        }

        return (string) $value;
    }

    /**
     * The figures a participant sees for a year.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function figures(array $row): array
    {
        return [
            'year' => $row['year'], 'profit' => $row['profit'], 'revenue' => $row['revenue'], 'headcount' => $row['headcount'],
            'trust' => $row['trust'], 'strain' => $row['strain'], 'incidents' => $row['incidents'], 'bench' => $row['expert_bench'],
            'delivery_levels' => $row['delivery_levels'], 'operations_levels' => $row['operations_levels'], 'notes' => $row['notes'],
        ];
    }
}
