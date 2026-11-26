<?php

namespace App\Ashby\Faculty;

use App\Ashby\Content\ContentPack;
use App\Ashby\Game\ReferenceTeams;
use App\Models\Section;
use App\Models\Team;
use App\Models\TeamYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "What students see": a class played by the four reference teams through all three decision years,
 * owned by the instructor who asked for it. It has no participant logins.
 */
final class SampleClass
{
    public function make(User $owner, string $future): Section
    {
        return DB::transaction(function () use ($owner, $future): Section {
            $section = Section::query()->create([
                'name' => 'Sample class ('.$future.')', 'future' => $future, 'timezone' => 'America/New_York',
                'open_through' => 2031, 'closed_through' => 2031,
            ]);
            $section->staff()->attach($owner->id, ['role' => 'owner']);
            $names = (array) app(ContentPack::class)->file('faculty')['reference_names'];
            foreach (ReferenceTeams::NAMES as $name) {
                $team = Team::query()->create(['section_id' => $section->id, 'name' => (string) ($names[$name] ?? $name)]);
                foreach (ReferenceTeams::cards($name) as $year => $cards) {
                    TeamYear::query()->create([
                        'team_id' => $team->id, 'year' => $year, 'cards' => $cards, 'checks' => [], 'revealed' => [],
                        'confirmed_at' => now(), 'confirmed_by' => $owner->id,
                    ]);
                }
            }
            return $section;
        });
    }
}
