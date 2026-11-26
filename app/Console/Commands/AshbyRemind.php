<?php

namespace App\Console\Commands;

use App\Ashby\Mail\Outgoing;
use App\Models\SectionYear;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reminders a day, four hours and an hour before a year closes, to teams that haven't confirmed it. Each goes once. */
class AshbyRemind extends Command
{
    protected $signature = 'ashby:remind';

    protected $description = 'Email reminders before an open year closes';

    /** Minutes before the close, and how the email says it, nearest first. */
    public const WHEN = ['hour' => [60, '1 hour'], 'four' => [240, '4 hours'], 'day' => [1440, '24 hours']];

    public function handle(Outgoing $mail): int
    {
        if (! Outgoing::enabled()) {
            return self::SUCCESS;
        }
        $now = now();
        foreach (SectionYear::query()->whereNotNull('closes_at')->where('closes_at', '>', $now)->where('closes_at', '<=', $now->copy()->addDay())->with('section')->get() as $entry) {
            if ($entry->closes_at === null) {
                continue;
            }
            // Only the nearest reminder goes; the earlier ones it has passed are marked, so they never go late.
            $remaining = $now->diffInMinutes($entry->closes_at, true);
            $due = null;
            foreach (self::WHEN as $kind => [$minutes]) {
                if ($due === null && $remaining <= $minutes) {
                    $due = $kind;
                }
            }
            if ($due === null) {
                continue;
            }
            $claimed = 0;
            $passed = false;
            foreach (self::WHEN as $kind => [$minutes]) {
                $passed = $passed || $kind === $due;
                if ($passed) {
                    $n = DB::table('year_reminders')->insertOrIgnore(['section_year_id' => $entry->id, 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now]);
                    $claimed += $kind === $due ? $n : 0;
                }
            }
            if ($claimed === 0) {
                continue;
            }
            $sent = $mail->remind($entry, self::WHEN[$due][1]);
            DB::table('year_reminders')->where('section_year_id', $entry->id)->where('kind', $due)->update(['sent' => $sent]);
            $this->line("{$entry->section_id} {$entry->year} {$due}: {$sent}");
        }

        return self::SUCCESS;
    }
}
