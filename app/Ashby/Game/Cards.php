<?php

namespace App\Ashby\Game;

/**
 * The four seat cards as a team sets them, and how they become the engine's decisions.
 * The cards hold percentages and plain choices; the engine takes shares and keys.
 */
final class Cards
{
    public const WORKFLOWS = ['audit_testing', 'tax_returns', 'advisory_research', 'client_reporting', 'billing', 'scheduling', 'it_support', 'client_service'];

    public const MODES = ['people', 'assist', 'draft_check', 'alone'];

    /** The usual graduate intake in client work, as Maya counts it. */
    public const USUAL_OFFERS = 230;

    /**
     * What a team holds before its first decision.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'workflows' => array_fill_keys(self::WORKFLOWS, 'people'),
            'oversight' => 'light',
            'checking' => false,
            'tutor' => 0,
            'mentoring' => 0,
            'knowledge' => 'people',
            'offers' => self::USUAL_OFFERS,
            'delivery_intake' => 100,
            'ops_intake' => 100,
            'lateral' => 0,
            'lay_delivery' => 0,
            'lay_delivery_experienced' => 0,
            'lay_ops' => 0,
            'lay_ops_experienced' => 0,
            'transparency' => false,
            'share_gains' => false,
            'planning' => 'hr',
            'board_report' => false,
        ];
    }

    /**
     * Validation rules for a card payload. Every field is required, so a save never half-applies.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [
            'workflows' => ['required', 'array'],
            'oversight' => ['required', 'in:strong,light'],
            'checking' => ['required', 'boolean'],
            'tutor' => ['required', 'numeric', 'between:0,100'],
            'mentoring' => ['required', 'numeric', 'between:0,10'],
            'knowledge' => ['required', 'in:people,firm,vendor'],
            'offers' => ['required', 'numeric', 'between:0,'.self::USUAL_OFFERS],
            'delivery_intake' => ['required', 'numeric', 'between:0,100'],
            'ops_intake' => ['required', 'numeric', 'between:0,100'],
            'lateral' => ['required', 'numeric', 'between:0,1'],
            'lay_delivery' => ['required', 'numeric', 'between:0,50'],
            'lay_delivery_experienced' => ['required', 'numeric', 'between:0,50'],
            'lay_ops' => ['required', 'numeric', 'between:0,50'],
            'lay_ops_experienced' => ['required', 'numeric', 'between:0,50'],
            'transparency' => ['required', 'boolean'],
            'share_gains' => ['required', 'boolean'],
            'planning' => ['required', 'in:hr,it,joint'],
            'board_report' => ['required', 'boolean'],
        ];
        foreach (self::WORKFLOWS as $key) {
            $rules['workflows.'.$key] = ['required', 'in:'.implode(',', self::MODES)];
        }

        return $rules;
    }

    /**
     * The engine's decisions for one decision year. In 2027 the graduate intake is Maya's number of offers;
     * later it is the share on the Talent card.
     *
     * @param  array<string, mixed>  $cards
     * @return array<string, mixed>
     */
    public static function toEngine(array $cards, int $year): array
    {
        $c = array_merge(self::defaults(), $cards);
        $workflows = [];
        foreach (self::WORKFLOWS as $key) {
            $workflows[$key] = (string) (is_array($c['workflows']) ? ($c['workflows'][$key] ?? 'people') : 'people');
        }
        $delivery = $year === 2027 ? (float) $c['offers'] / self::USUAL_OFFERS : (float) $c['delivery_intake'] / 100;

        return [
            'workflows' => $workflows,
            'tutor' => (float) $c['tutor'] / 100,
            'intake' => ['delivery' => $delivery, 'operations' => (float) $c['ops_intake'] / 100],
            'mentoring' => (float) $c['mentoring'] / 100,
            'layoffs' => [
                'delivery' => [(float) $c['lay_delivery'] / 100, (float) $c['lay_delivery_experienced'] / 100, 0.0, 0.0],
                'operations' => [(float) $c['lay_ops'] / 100, (float) $c['lay_ops_experienced'] / 100, 0.0, 0.0],
            ],
            'share_gains' => (bool) $c['share_gains'],
            'transparency' => (bool) $c['transparency'],
            'oversight' => (string) $c['oversight'],
            'lateral' => (float) $c['lateral'],
            'knowledge' => (string) $c['knowledge'],
            'planning' => (string) $c['planning'],
            'board_report' => (bool) $c['board_report'],
            'checking' => (bool) $c['checking'],
        ];
    }
}
