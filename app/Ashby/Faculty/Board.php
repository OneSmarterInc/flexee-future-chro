<?php

namespace App\Ashby\Faculty;

use App\Ashby\Engine\ModelData;
use App\Ashby\Game\Cards;
use App\Ashby\Game\PlayState;
use App\Models\Section;
use App\Models\SectionYear;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What an instructor does to a class: choose the future, open and close years, reopen one team's year,
 * and what the board shows for each team.
 */
final class Board
{
    public function __construct(private readonly PlayState $state) {}

    /** Chooses or draws the future. Fixed once any team has confirmed a year (FB4). */
    public function setFuture(Section $section, string $future): void
    {
        if ($this->anyConfirmed($section)) {
            throw new RuntimeException('future_locked');
        }
        $drawn = $future === 'draw';
        if ($drawn) {
            $future = ModelData::FUTURES[random_int(0, count(ModelData::FUTURES) - 1)];
        }
        if (! in_array($future, ModelData::FUTURES, true)) {
            throw new RuntimeException('not_a_future');
        }
        $section->future = $future;
        $section->future_drawn = $drawn;
        $section->save();
    }

    /** Opens the next decision year. Years open in order and need the future first. */
    public function open(Section $section, int $year): void
    {
        if ($section->future === null) {
            throw new RuntimeException('need_future');
        }
        if ($year !== $this->next($section, $section->open_through)) {
            throw new RuntimeException('not_next');
        }
        $section->open_through = $year;
        $section->save();
        SectionYear::query()->updateOrCreate(['section_id' => $section->id, 'year' => $year], ['opened_at' => now()]);
    }

    /**
     * Closes the next open year. Every team that hasn't confirmed it, or a year before it,
     * is confirmed with the cards it has saved (FB2).
     */
    public function close(Section $section, int $year, ?User $by): void
    {
        if ($year !== $this->next($section, $section->closed_through)) {
            throw new RuntimeException('not_next');
        }
        if ($year > $section->open_through) {
            throw new RuntimeException('not_open');
        }
        DB::transaction(function () use ($section, $year, $by): void {
            foreach ($section->teams as $team) {
                foreach ($section->years() as $y) {
                    if ($y > $year) {
                        break;
                    }
                    $row = $this->state->yearFor($team, $y);
                    if ($row->confirmed_at === null) {
                        $row->cards = array_merge(Cards::defaults(), $row->cards ?? []);
                        $row->confirmed_at = now();
                        $row->confirmed_by = $by?->id;
                        $row->save();
                    }
                }
            }
            $section->closed_through = $year;
            $section->save();
            SectionYear::query()->updateOrCreate(['section_id' => $section->id, 'year' => $year], ['closed_at' => now()]);
        });
    }

    /** Clears one team's confirm for its latest confirmed year, so the team can change it (FB3). */
    public function reopen(Team $team, int $year): void
    {
        $latest = TeamYear::query()->where('team_id', $team->id)->whereNotNull('confirmed_at')->max('year');
        if ($latest === null || (int) $latest !== $year) {
            throw new RuntimeException('cannot_reopen');
        }
        TeamYear::query()->where('team_id', $team->id)->where('year', $year)->update(['confirmed_at' => null, 'confirmed_by' => null]);
        if ($team->section->closed_through >= $year) {
            $team->section->closed_through = $this->previous($team->section, $year);
            $team->section->save();
        }
    }

    public function anyConfirmed(Section $section): bool
    {
        return TeamYear::query()->whereIn('team_id', $section->teams()->select('id'))->whereNotNull('confirmed_at')->exists();
    }

    public function state(Section $section, int $year): string
    {
        return $year <= $section->closed_through ? 'closed' : ($year <= $section->open_through ? 'open' : 'not_open');
    }

    private function next(Section $section, int $through): ?int
    {
        foreach ($section->years() as $y) {
            if ($y > $through) {
                return $y;
            }
        }

        return null;
    }

    private function previous(Section $section, int $year): int
    {
        $before = 2026;
        foreach ($section->years() as $y) {
            if ($y < $year) {
                $before = $y;
            }
        }

        return $before;
    }
}
