<?php

namespace App\Ashby\Reporting;

use App\Ashby\Ai\Writer;
use App\Ashby\Engine\AshbyEngine;
use App\Models\Enrolment;
use App\Models\ReportingConnection;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\TableMessage;
use App\Models\TeamYear;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The Flexee family reporting contract, v1, as The Future CHRO publishes it. Operational facts only: classes by name,
 * counts, states, dates, timings, failures and AI use. No person's name or email, no team's words, and no team's
 * results. Sample classes are left out. Every row starts with `sim`. Times are UTC in ISO 8601; money is US dollars.
 *
 * The contract's words map to this sim's: a course is a class, a firm is a team, and a quarter is a decision year.
 * The sim has no accounting-method requests, so that view is always empty; it stays so every sim lists the same six.
 */
class ReportingViews
{
    public const SCHEDULER_KEY = 'ashby:reporting:scheduler_ran_at';

    public const CERTIFICATE_KEY = 'ashby:reporting:certificate_expires_at';

    /** @return array<string, array{title: string, description: string, columns: array<string, string>}> */
    public static function tools(): array
    {
        return [
            'report_courses' => ['title' => 'Courses', 'description' => 'One row per class (sample classes left out): its length, status, where it stands, and how many teams and participants it has.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'course_id' => 'The class\'s id.', 'course' => 'The class\'s name.',
                'institution' => 'Null: the sim keeps no institution.', 'product' => 'future_chro_slice (2027, 2029, 2031) or future_chro_full (2027 to 2034).',
                'engine_version' => 'The economics engine the class runs on.', 'status' => 'setting_up, running or finished.',
                'quarter_now' => 'The decision year open now, else the last closed, else null.', 'quarters' => 'Decision years in the class.',
                'firms' => 'Teams in the class.', 'students' => 'Participants enrolled.', 'students_confirmed' => 'Enrolled participants whose access is active (not paused).',
                'next_deadline_at' => 'When the open year closes on the calendar, else null.', 'created_at' => 'When the class was created.', 'deployed_at' => 'When 2027 opened, else null.',
            ]],
            'report_quarters' => ['title' => 'Decision years', 'description' => 'One row per class and decision year: its state and close time, when it opened and closed, and how many teams confirmed.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'course_id' => 'The class\'s id.', 'course' => 'The class\'s name.', 'quarter' => 'The decision year (2027 to 2034).',
                'warm_up' => 'Always false: every year is played.', 'state' => 'locked, open or closed.', 'deadline_at' => 'When the year closes on the calendar, else null.',
                'deadline_extended' => 'Always false: the sim keeps no record of extensions.', 'opened_at' => 'When the year opened.', 'opened_after_deadline' => 'True when it opened after its own close time.',
                'overdue' => 'True when the close time has passed and the year is still open.', 'close_started_at' => 'When the year was closed.', 'closed_at' => 'When the year was closed; a close runs in one step.',
                'close_minutes' => 'Always 0: closing is one step.', 'firms' => 'Teams in the class.', 'firms_submitted' => 'Teams whose CHRO confirmed the year.',
                'firms_carried_forward' => 'Teams confirmed with their saved cards when the year closed.', 'explanations_required' => 'True: each team writes a paragraph on what it expects.',
                'explanations_submitted' => 'Teams that saved a paragraph.',
            ]],
            'report_closes' => ['title' => 'Firm closes', 'description' => 'One row per team year confirmed in the last 90 days.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'close_id' => 'The team year\'s id.', 'course_id' => 'The class\'s id.', 'course' => 'The class\'s name.',
                'quarter' => 'The decision year.', 'firm_id' => 'The team\'s id.', 'status' => 'Always succeeded: results are worked out when they are shown.',
                'error_code' => 'Null.', 'started_at' => 'When the year was confirmed.', 'completed_at' => 'When the year was confirmed.', 'seconds' => 'Always 0.',
            ]],
            'report_requests' => ['title' => 'Accounting-method requests', 'description' => 'The sim has no accounting-method requests. This view is always empty and is listed so every Flexee sim offers the same six.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'request_id' => 'Not used.',
            ]],
            'report_ai_use' => ['title' => 'AI use', 'description' => 'One row per class, month and AI feature in the last 12 months: calls, failures, answers the checks dropped, tokens and an estimated cost.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'course_id' => 'The class\'s id.', 'course' => 'The class\'s name.', 'month' => 'The month, as YYYY-MM.',
                'feature' => 'table (voices at the table), paragraph (Daniel\'s replies), reading (class readings) or debrief (debrief notes).',
                'calls' => 'Calls to Claude. The sim never retries.', 'failures' => 'Calls whose answer was not shown.', 'sentences_dropped' => 'Answers the checks dropped.',
                'input_tokens' => 'Input tokens (table answers only).', 'output_tokens' => 'Output tokens (table answers only).', 'cost_usd' => 'Estimated cost in US dollars, from the token prices set on the server.',
            ]],
            'report_health' => ['title' => 'Site health', 'description' => 'One row for the site: the queue, failed jobs, the scheduler, migrations not yet run, versions, AI use, mail, and the certificate.', 'columns' => [
                'sim' => 'Which sim the row comes from.', 'contract' => 'The reporting contract\'s version.', 'checked_at' => 'When these figures were worked out.',
                'environment' => 'The app environment.', 'commit' => 'The deployed commit, else null.', 'php_version' => 'PHP\'s version.', 'database_version' => 'The database server\'s version.',
                'migrations_pending' => 'Migrations not yet run.', 'jobs_waiting' => 'Jobs in the queue.', 'oldest_job_minutes' => 'Minutes the oldest waiting job has waited, else null.',
                'failed_jobs_7d' => 'Jobs that failed in the last seven days.', 'scheduler_last_run_at' => 'When the scheduler last ran, else null.', 'scheduler_ok' => 'True when the scheduler ran in the last five minutes.',
                'courses_running' => 'Classes running now.', 'quarters_overdue' => 'Years past their close time and still open.', 'closes_failed' => 'Always 0.',
                'requests_waiting' => 'Always 0.', 'ai_enabled' => 'True when AI features can run on this site.', 'ai_spent_this_month_usd' => 'Estimated AI cost this month, in US dollars.',
                'ai_ceiling_usd' => 'Null: each class has its own switches.', 'mail_enabled' => 'True when the site sends email.',
                'certificate_expires_at' => 'When the site\'s TLS certificate expires, else null.', 'certificate_days_left' => 'Days until it expires, else null.', 'connections_active' => 'Active reporting connections.',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public function run(string $tool, array $args = []): array
    {
        return match ($tool) {
            'report_courses' => $this->courses(),
            'report_quarters' => $this->quarters(),
            'report_closes' => $this->closes(),
            'report_requests' => [],
            'report_ai_use' => $this->aiUse(),
            default => [$this->health()],
        };
    }

    /** @return Collection<int, Section> */
    private function realCourses(): Collection
    {
        return Section::query()->where('name', 'not like', 'Sample class (%')->with('calendar')->orderBy('id')->get();
    }

    private function sim(): string
    {
        return (string) config('ashby.reporting.sim');
    }

    private static function at(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return list<array<string, mixed>> */
    public function courses(): array
    {
        $out = [];
        foreach ($this->realCourses() as $s) {
            $teams = $s->teams()->count();
            $enrolled = $s->enrolments()->count();
            $active = $s->enrolments()->where('status', Enrolment::ACTIVE)->count();
            $openYear = $s->open_through > $s->closed_through ? $s->open_through : null;
            $now = $openYear ?? ($s->closed_through >= 2027 ? $s->closed_through : null);
            $entry = $openYear === null ? null : $s->calendar->firstWhere('year', $openYear);
            $first = $s->calendar->firstWhere('year', 2027);
            $out[] = [
                'sim' => $this->sim(), 'course_id' => $s->id, 'course' => $s->name, 'institution' => null,
                'product' => 'future_chro_'.$s->length, 'engine_version' => (string) AshbyEngine::VERSION,
                'status' => $s->open_through < 2027 ? 'setting_up' : ($s->closed_through >= $s->lastYear() ? 'finished' : 'running'),
                'quarter_now' => $now, 'quarters' => count($s->years()), 'firms' => $teams, 'students' => $enrolled, 'students_confirmed' => $active,
                'next_deadline_at' => $entry instanceof SectionYear ? self::at($entry->closes_at) : null,
                'created_at' => self::at($s->created_at), 'deployed_at' => $first instanceof SectionYear ? self::at($first->opened_at) : null,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function quarters(): array
    {
        $out = [];
        foreach ($this->realCourses() as $s) {
            $teamIds = $s->teams()->pluck('id');
            $members = DB::table('team_members')->whereIn('team_id', $teamIds)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            foreach ($s->years() as $year) {
                $entry = $s->calendar->firstWhere('year', $year);
                $state = $year <= $s->closed_through ? 'closed' : ($year <= $s->open_through ? 'open' : 'locked');
                $rows = TeamYear::query()->whereIn('team_id', $teamIds)->where('year', $year)->get();
                $confirmed = $rows->whereNotNull('confirmed_at');
                $byChro = $confirmed->filter(fn (TeamYear $r): bool => in_array((int) $r->confirmed_by, $members, true))->count();
                $deadline = $entry instanceof SectionYear ? $entry->closes_at : null;
                $opened = $entry instanceof SectionYear ? $entry->opened_at : null;
                $closed = $entry instanceof SectionYear ? $entry->closed_at : null;
                $out[] = [
                    'sim' => $this->sim(), 'course_id' => $s->id, 'course' => $s->name, 'quarter' => $year, 'warm_up' => false, 'state' => $state,
                    'deadline_at' => self::at($deadline), 'deadline_extended' => false, 'opened_at' => self::at($opened),
                    'opened_after_deadline' => $deadline !== null && $opened !== null && $opened > $deadline,
                    'overdue' => $state === 'open' && $deadline !== null && $deadline < now(),
                    'close_started_at' => self::at($closed), 'closed_at' => self::at($closed), 'close_minutes' => $closed === null ? null : 0,
                    'firms' => $teamIds->count(), 'firms_submitted' => $byChro, 'firms_carried_forward' => $confirmed->count() - $byChro,
                    'explanations_required' => true, 'explanations_submitted' => $rows->filter(fn (TeamYear $r): bool => trim((string) $r->paragraph) !== '')->count(),
                ];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function closes(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $out = [];
        $rows = TeamYear::query()->whereNotNull('confirmed_at')->where('confirmed_at', '>=', now()->subDays((int) config('ashby.reporting.closes_days')))
            ->join('teams', 'teams.id', '=', 'team_years.team_id')->whereIn('teams.section_id', $courses->keys())
            ->orderBy('team_years.id')->get(['team_years.*', 'teams.section_id']);
        foreach ($rows as $r) {
            $s = $courses->get((int) $r->getAttribute('section_id'));
            $out[] = [
                'sim' => $this->sim(), 'close_id' => $r->id, 'course_id' => $s?->id, 'course' => $s?->name, 'quarter' => $r->year, 'firm_id' => $r->team_id,
                'status' => 'succeeded', 'error_code' => null, 'started_at' => self::at($r->confirmed_at), 'completed_at' => self::at($r->confirmed_at), 'seconds' => 0,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function aiUse(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $since = now()->subMonths(12)->startOfMonth();
        $in = (float) config('ashby.ai.usd_per_million_input') / 1e6;
        $outPrice = (float) config('ashby.ai.usd_per_million_output') / 1e6;
        $out = [];
        $messages = TableMessage::query()->where('table_messages.created_at', '>=', $since)->where(fn ($q) => $q->where('source', 'ai')->orWhereNotNull('dropped_reason'))
            ->join('teams', 'teams.id', '=', 'table_messages.team_id')->whereIn('teams.section_id', $courses->keys())
            ->get(['table_messages.*', 'teams.section_id']);
        foreach ($messages->groupBy(fn (TableMessage $m): string => $m->getAttribute('section_id').'|'.$m->created_at?->format('Y-m')) as $key => $group) {
            [$sectionId, $month] = explode('|', (string) $key);
            $s = $courses->get((int) $sectionId);
            $tin = (int) $group->sum('input_tokens');
            $tout = (int) $group->sum('output_tokens');
            $dropped = $group->whereNotNull('dropped_reason')->count();
            $out[] = ['sim' => $this->sim(), 'course_id' => $s?->id, 'course' => $s?->name, 'month' => $month, 'feature' => 'table', 'calls' => $group->count(),
                'failures' => $dropped, 'sentences_dropped' => $dropped, 'input_tokens' => $tin, 'output_tokens' => $tout, 'cost_usd' => round($tin * $in + $tout * $outPrice, 2)];
        }
        $paragraphs = TeamYear::query()->where('team_years.updated_at', '>=', $since)->where(fn ($q) => $q->whereNotNull('feedback')->orWhereNotNull('feedback_note'))
            ->join('teams', 'teams.id', '=', 'team_years.team_id')->whereIn('teams.section_id', $courses->keys())->get(['team_years.*', 'teams.section_id']);
        foreach ($paragraphs->groupBy(fn (TeamYear $r): string => $r->getAttribute('section_id').'|'.$r->updated_at?->format('Y-m')) as $key => $group) {
            [$sectionId, $month] = explode('|', (string) $key);
            $s = $courses->get((int) $sectionId);
            $dropped = $group->whereNull('feedback')->count();
            $out[] = ['sim' => $this->sim(), 'course_id' => $s?->id, 'course' => $s?->name, 'month' => $month, 'feature' => 'paragraph', 'calls' => $group->count(),
                'failures' => $dropped, 'sentences_dropped' => $dropped, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $oldest = DB::table('jobs')->min('available_at');
        $schedulerAt = Cache::get(self::SCHEDULER_KEY);
        $certificateAt = Cache::get(self::CERTIFICATE_KEY);
        $thisMonth = now()->format('Y-m');

        return [
            'sim' => $this->sim(), 'contract' => (string) config('ashby.reporting.contract'), 'checked_at' => self::at(now()),
            'environment' => (string) app()->environment(), 'commit' => self::commit(), 'php_version' => PHP_VERSION,
            'database_version' => self::databaseVersion(), 'migrations_pending' => self::migrationsPending(),
            'jobs_waiting' => DB::table('jobs')->count(),
            'oldest_job_minutes' => $oldest ? (int) floor((now()->getTimestamp() - (int) $oldest) / 60) : null,
            'failed_jobs_7d' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count(),
            'scheduler_last_run_at' => $schedulerAt ? self::at(now()->setTimestamp((int) $schedulerAt)) : null,
            'scheduler_ok' => $schedulerAt !== null && now()->getTimestamp() - (int) $schedulerAt <= 300,
            'courses_running' => collect($this->courses())->where('status', 'running')->count(),
            'quarters_overdue' => collect($this->quarters())->where('overdue', true)->count(),
            'closes_failed' => 0, 'requests_waiting' => 0,
            'ai_enabled' => app(Writer::class)->available(),
            'ai_spent_this_month_usd' => round((float) collect($this->aiUse())->where('month', $thisMonth)->sum('cost_usd'), 2),
            'ai_ceiling_usd' => null, 'mail_enabled' => (bool) config('ashby.mail_enabled'),
            'certificate_expires_at' => $certificateAt ? self::at(now()->setTimestamp((int) $certificateAt)) : null,
            'certificate_days_left' => $certificateAt ? (int) floor(((int) $certificateAt - now()->getTimestamp()) / 86400) : null,
            'connections_active' => ReportingConnection::query()->whereNull('revoked_at')->where('refresh_expires_at', '>', now())->count(),
        ];
    }

    /** The commit the server's checkout is on (the deploy fast-forwards a git checkout). */
    public static function commit(): ?string
    {
        try {
            $git = base_path('.git');
            $head = trim((string) @file_get_contents($git.'/HEAD'));
            if (preg_match('/^[0-9a-f]{40}$/', $head)) {
                return substr($head, 0, 12);
            }
            if (! str_starts_with($head, 'ref: ')) {
                return null;
            }
            $ref = substr($head, 5);
            $sha = trim((string) @file_get_contents($git.'/'.$ref));
            if ($sha === '' && is_file($git.'/packed-refs')) {
                foreach (file($git.'/packed-refs') ?: [] as $line) {
                    if (str_ends_with(trim($line), ' '.$ref)) {
                        $sha = strtok($line, ' ');
                    }
                }
            }

            return preg_match('/^[0-9a-f]{40}$/', (string) $sha) ? substr((string) $sha, 0, 12) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('select version() as v');

            return mb_substr((string) $row->v, 0, 100);
        } catch (Throwable) {
            return null;
        }
    }

    /** Migrations in the folder this database runs from (MySQL or MariaDB: migrations-mysql) that haven't run. */
    public static function migrationsPending(): ?int
    {
        try {
            $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            $folder = $mysql && is_dir(database_path('migrations-mysql')) ? 'migrations-mysql' : 'migrations';
            $files = collect(File::files(database_path($folder)))->map(fn ($f) => $f->getFilenameWithoutExtension());
            $ran = DB::table('migrations')->pluck('migration');

            return $files->diff($ran)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** Daily: when the site's own certificate expires, read from the certificate the site presents. */
    public static function checkCertificate(): ?int
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $host || parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $socket = @stream_socket_client("ssl://{$host}:443", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
            if (! $socket) {
                return null;
            }
            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            fclose($socket);
            $info = $cert ? openssl_x509_parse($cert) : false;
            $expires = is_array($info) ? (int) ($info['validTo_time_t'] ?? 0) : 0;
            if ($expires > 0) {
                Cache::forever(self::CERTIFICATE_KEY, $expires);

                return $expires;
            }
        } catch (Throwable) {
        }

        return null;
    }
}
