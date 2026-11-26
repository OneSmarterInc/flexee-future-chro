<?php

namespace App\Ashby\Game;

use RuntimeException;

/**
 * The four reference teams from economics/teams as the full card set a team posts each year.
 * Used by "What students see" and by the tests that play them through the screens.
 */
final class ReferenceTeams
{
    public const NAMES = ['builder', 'middle', 'cutter', 'waiter'];

    /**
     * A reference team's cards for each decision year: its changes laid over what it held.
     * In the full format the years between hold the same cards, with no new layoffs.
     *
     * @param  list<int>|null  $years  the slice by default
     * @return array<int, array<string, mixed>>
     */
    public static function cards(string $name, ?array $years = null): array
    {
        $decisions = self::decisions($name);
        $held = [
            'workflows' => array_fill_keys(Cards::WORKFLOWS, 'people'), 'tutor' => 0.0, 'intake' => ['delivery' => 1.0, 'operations' => 1.0],
            'mentoring' => 0.0, 'share_gains' => false, 'transparency' => false, 'oversight' => 'light', 'lateral' => 0.0,
            'knowledge' => 'people', 'planning' => 'hr', 'board_report' => false, 'checking' => false, 'ruth' => 'go',
        ];
        $out = [];
        foreach ($years ?? PlayState::YEARS as $year) {
            /** @var array<string, mixed> $new */
            $new = array_key_exists($year, $decisions) ? $decisions[$year] : [];
            /** @var array<string, list<float|int>> $layoffs */
            $layoffs = $new['layoffs'] ?? [];
            unset($new['layoffs']);
            if (isset($new['workflows']) && is_array($new['workflows'])) {
                $new['workflows'] = array_merge($held['workflows'], $new['workflows']);
            }
            $held = array_replace($held, $new);
            $lay = fn (string $family, int $level): float => 100 * (float) ($layoffs[$family][$level] ?? 0);
            $intake = (array) $held['intake'];
            $out[$year] = [
                'workflows' => $held['workflows'],
                'oversight' => $held['oversight'],
                'checking' => $held['checking'],
                'tutor' => 100 * (float) $held['tutor'],
                'mentoring' => 100 * (float) $held['mentoring'],
                'knowledge' => $held['knowledge'],
                'offers' => $year === 2027 ? Cards::USUAL_OFFERS * (float) $intake['delivery'] : Cards::USUAL_OFFERS,
                'delivery_intake' => 100 * (float) $intake['delivery'],
                'ops_intake' => 100 * (float) $intake['operations'],
                'lateral' => $held['lateral'],
                'lay_delivery' => $lay('delivery', 0),
                'lay_delivery_experienced' => $lay('delivery', 1),
                'lay_ops' => $lay('operations', 0),
                'lay_ops_experienced' => $lay('operations', 1),
                'transparency' => $held['transparency'],
                'share_gains' => $held['share_gains'],
                'planning' => $held['planning'],
                'board_report' => $held['board_report'],
                'ruth' => $held['ruth'],
            ];
        }

        return $out;
    }

    /** @return array<int|string, array<string, mixed>> */
    public static function decisions(string $name): array
    {
        if (! in_array($name, self::NAMES, true)) {
            throw new RuntimeException("No reference team {$name}.");
        }
        $json = file_get_contents(dirname(__DIR__, 3).'/economics/teams/'.$name.'.json');
        if ($json === false) {
            throw new RuntimeException("Cannot read the {$name} team.");
        }
        /** @var array<int|string, array<string, mixed>> $decoded */
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
