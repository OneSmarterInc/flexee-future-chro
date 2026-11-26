<?php

namespace App\Ashby\Mail;

use App\Ashby\Content\ContentPack;
use App\Mail\AshbyMail;
use App\Models\Enrolment;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The emails the site sends: a participant's sign-in when an instructor adds them, a note when they're added with
 * a sign-in they already have, and reminders before a year closes. Nothing goes out until the server has outgoing
 * mail on; the screens say so. A failed send is logged and never stops the step that asked for it.
 */
final class Outgoing
{
    public function __construct(private readonly ContentPack $content) {}

    public static function enabled(): bool
    {
        return (bool) config('ashby.mail_enabled');
    }

    public function login(Section $section, User $to, string $password): void
    {
        $this->send('login', $to, $section, ['password' => $password, 'link' => route('login')]);
    }

    public function added(Section $section, User $to): void
    {
        $this->send('added', $to, $section, ['link' => route('login')]);
    }

    /**
     * A reminder to each active member of a team that hasn't confirmed an open year.
     *
     * @return int how many were sent
     */
    public function remind(SectionYear $entry, string $hours): int
    {
        $section = $entry->section;
        if (! self::enabled() || $entry->closes_at === null || $section->open_through < $entry->year || $section->closed_through >= $entry->year) {
            return 0;
        }
        $done = TeamYear::query()->whereIn('team_id', $section->teams()->select('id'))->where('year', $entry->year)->whereNotNull('confirmed_at')
            ->pluck('team_id')->map(fn ($id): int => (int) $id)->values()->all();
        $sent = 0;
        foreach ($this->members($section, $done) as $user) {
            $this->send('reminder', $user, $section, [
                'year' => (string) $entry->year, 'hours' => $hours,
                'deadline' => $entry->closes_at->setTimezone($section->timezone)->format('l j F, g:i a T'),
                'link' => route('play.year', ['year' => $entry->year]),
            ]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Active members of a team in this class, leaving out the teams given.
     *
     * @param  list<int>  $skipTeams
     * @return list<User>
     */
    private function members(Section $section, array $skipTeams): array
    {
        $ids = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->join('enrolments', fn ($j) => $j->on('enrolments.user_id', '=', 'team_members.user_id')->on('enrolments.section_id', '=', 'teams.section_id'))
            ->where('teams.section_id', $section->id)
            ->where('enrolments.status', Enrolment::ACTIVE)
            ->whereNotIn('teams.id', $skipTeams)
            ->pluck('team_members.user_id')->unique()->all();

        return array_values(User::query()->whereIn('id', $ids)->orderBy('id')->get()->reject(fn (User $u): bool => self::cannotReceive($u->email))->all());
    }

    /** Practice logins on a reserved domain (.invalid, example.test) can't receive mail: nothing goes to them. */
    private static function cannotReceive(string $email): bool
    {
        $email = strtolower(trim($email));

        return str_ends_with($email, '.invalid') || str_ends_with($email, '.test');
    }

    /** @param  array<string, string>  $fill */
    private function send(string $kind, User $to, Section $section, array $fill): void
    {
        if (! self::enabled() || self::cannotReceive($to->email)) {
            return;
        }
        $mail = $this->content->file('mail');
        $words = (array) $mail[$kind];
        $fill += ['name' => $to->name, 'class' => $section->name, 'instructor' => $section->owner()->name ?? 'Your instructor', 'email' => $to->email];
        $swap = fn (string $text): string => strtr($text, array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($fill)), array_values($fill)));
        try {
            Mail::to($to->email, $to->name)->send(new AshbyMail($swap((string) $words['subject']), array_values(array_map(fn ($line): string => $swap((string) $line), (array) $words['lines'])), (string) $mail['sign_off']));
        } catch (Throwable $e) {
            Log::warning('Mail not sent', ['kind' => $kind, 'user' => $to->id, 'error' => $e->getMessage()]);
        }
    }
}
