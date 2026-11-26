<?php

namespace App\Ashby\Admin;

use App\Ashby\Faculty\Board;
use App\Ashby\Game\PlayState;
use App\Models\Section;
use App\Models\SectionYear;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The class calendar (RO3). A workshop runs the years within a day; a term runs one year a week.
 * Times are planned in the class's time zone, so a week added across a clock change keeps the clock time.
 */
final class Calendar
{
    /** Workshop: a year starts every two hours and is open for an hour and three quarters. */
    public const WORKSHOP_GAP_MINUTES = 120;

    public const WORKSHOP_OPEN_MINUTES = 105;

    /** Term: a year opens each week and closes six days later. */
    public const TERM_OPEN_DAYS = 6;

    /** Full-format workshop (FF-D7): two half-days, four years each, a year every 55 minutes, open for 45. */
    public const FULL_GAP_MINUTES = 55;

    public const FULL_OPEN_MINUTES = 45;

    public const FULL_YEARS_PER_DAY = 4;

    public function __construct(private readonly Board $board) {}

    /**
     * Opening and closing times for each decision year from a first opening time, in UTC.
     *
     * @param  list<int>|null  $years  the class's decision years; the slice by default
     * @return array<int, array{opens_at: CarbonImmutable, closes_at: CarbonImmutable}>
     */
    public static function plan(string $format, CarbonImmutable $firstLocal, ?array $years = null): array
    {
        $years ??= PlayState::YEARS;
        $full = count($years) > count(PlayState::YEARS);
        $out = [];
        foreach ($years as $i => $year) {
            if ($format === 'term') {
                $opens = $firstLocal->addWeeks($i);
                $closes = $opens->addDays(self::TERM_OPEN_DAYS);
            } elseif ($full) {
                $opens = $firstLocal->addDays(intdiv($i, self::FULL_YEARS_PER_DAY))->addMinutes(($i % self::FULL_YEARS_PER_DAY) * self::FULL_GAP_MINUTES);
                $closes = $opens->addMinutes(self::FULL_OPEN_MINUTES);
            } else {
                $opens = $firstLocal->addMinutes($i * self::WORKSHOP_GAP_MINUTES);
                $closes = $opens->addMinutes(self::WORKSHOP_OPEN_MINUTES);
            }
            $out[$year] = ['opens_at' => $opens->utc(), 'closes_at' => $closes->utc()];
        }

        return $out;
    }

    /**
     * Saves a calendar the instructor entered. Times arrive as local "Y-m-d\TH:i" strings in the class's zone.
     *
     * @param  array<int|string, array{opens_at?: string|null, closes_at?: string|null}>  $years
     */
    public function save(Section $section, array $years, bool $autoRun): void
    {
        foreach ($section->years() as $year) {
            $entry = $years[$year] ?? $years[(string) $year] ?? [];
            $opens = $this->local($section, $entry['opens_at'] ?? null);
            $closes = $this->local($section, $entry['closes_at'] ?? null);
            if ($opens !== null && $closes !== null && $closes <= $opens) {
                throw new RuntimeException('closes_before_opens');
            }
            SectionYear::query()->updateOrCreate(['section_id' => $section->id, 'year' => $year], ['opens_at' => $opens, 'closes_at' => $closes]);
        }
        $section->update(['auto_run' => $autoRun]);
    }

    /** Fills the calendar from the first opening time, in the class's zone. */
    public function fill(Section $section, string $firstLocal): void
    {
        $first = $this->local($section, $firstLocal);
        if ($first === null) {
            throw new RuntimeException('need_time');
        }
        foreach (self::plan($section->format, $first->setTimezone($section->timezone), $section->years()) as $year => $times) {
            SectionYear::query()->updateOrCreate(['section_id' => $section->id, 'year' => $year], $times);
        }
    }

    /**
     * Opens and closes years whose times have come, for classes that run on their calendar.
     * If no future was chosen when 2027 is due, one is drawn.
     *
     * @return list<string> what was done, for the log
     */
    public function tick(CarbonImmutable $now): array
    {
        $done = [];
        foreach (Section::query()->where('auto_run', true)->get() as $section) {
            foreach ($section->calendar()->orderBy('year')->get() as $entry) {
                $state = $this->board->state($section, $entry->year);
                try {
                    if ($state === 'not_open' && $entry->opens_at !== null && $entry->opens_at <= $now) {
                        if ($section->future === null) {
                            $this->board->setFuture($section, 'draw');
                            $done[] = "{$section->id}: drew the future";
                        }
                        $this->board->open($section, $entry->year);
                        $done[] = "{$section->id}: opened {$entry->year}";
                        $state = 'open';
                    }
                    if ($state === 'open' && $entry->closes_at !== null && $entry->closes_at <= $now) {
                        $this->board->close($section, $entry->year, null);
                        $done[] = "{$section->id}: closed {$entry->year}";
                    }
                } catch (RuntimeException) {
                    // Out of order (an earlier year still to open); the next tick tries again.
                    break;
                }
                $section->refresh();
            }
        }

        return $done;
    }

    /**
     * The calendar as the board shows it: local times for the inputs.
     *
     * @return array<int, array{opens_at: string|null, closes_at: string|null}>
     */
    public function localTimes(Section $section): array
    {
        $out = [];
        $rows = $section->calendar()->get()->keyBy('year');
        foreach ($section->years() as $year) {
            $row = $rows->get($year);
            $out[$year] = [
                'opens_at' => $row?->opens_at?->setTimezone($section->timezone)->format('Y-m-d\TH:i'),
                'closes_at' => $row?->closes_at?->setTimezone($section->timezone)->format('Y-m-d\TH:i'),
            ];
        }

        return $out;
    }

    /** When a year opens, as a participant reads it, in the class's zone. */
    public static function opensLabel(Section $section, int $year): ?string
    {
        $row = $section->calendar()->where('year', $year)->first();

        return $row?->opens_at?->setTimezone($section->timezone)->format('D j M, g:i a');
    }

    private function local(Section $section, ?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $section->timezone);
        if (! $parsed instanceof CarbonImmutable) {
            throw new RuntimeException('need_time');
        }

        return $parsed->utc();
    }
}
