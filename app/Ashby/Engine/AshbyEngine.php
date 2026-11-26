<?php

namespace App\Ashby\Engine;

/**
 * The Future CHRO's engine: a yearly stock-flow model of Ashby Calder's expertise pipeline, 2027 to 2035.
 * A port of economics/model.py (v0.3). It must reproduce every value in economics/fixtures.
 * Decisions are made in decision years and held until the next one.
 */
final class AshbyEngine
{
    public const VERSION = 3;

    /** Weights for the overall result (decision S1). */
    public const WEIGHTS = ['financial' => 0.40, 'capability' => 0.35, 'trust' => 0.25];

    private const DEFAULT = [
        'workflows' => [],
        'tutor' => 0.0,
        'intake' => ['delivery' => 1.0, 'operations' => 1.0],
        'mentoring' => 0.0,
        'layoffs' => [],
        'share_gains' => false,
        'transparency' => false,
        'oversight' => 'light',
        'lateral' => 0.0,
        'knowledge' => 'people',
        'planning' => 'hr',
        'board_report' => false,
        'checking' => false,
    ];

    public function __construct(private readonly ModelData $data) {}

    /**
     * Plays a team's decisions under one future, year by year.
     *
     * @param  array<string, array<string, mixed>>  $team  Decisions keyed by decision year ("2027", "2029", ...).
     * @return list<array<string, mixed>>
     */
    public function play(array $team, string $future, ?int $lastYear = null): array
    {
        $D = $this->data;
        $families = ModelData::FAMILIES;
        $modes = array_keys($D->modes);
        $y0 = (int) $D->c('first_year');
        $y1 = $lastYear ?? (int) $D->c('last_year');
        $startPipe = (0.3 * 520 + 360) / (1180 * 1.04 * (0.165 + 0.0695));

        $hc = [];
        $baseIntake = [];
        foreach ($families as $f) {
            $hc[$f] = [];
            for ($i = 0; $i < 4; $i++) {
                $hc[$f][$i] = $D->level($f, $i, 'start_headcount');
            }
            $baseIntake[$f] = $D->level($f, 0, 'start_headcount') * ($D->level($f, 0, 'attrition_base') + $D->level($f, 0, 'promo_base'));
        }
        $start23 = 0.0;
        foreach ($families as $f) {
            $start23 += $D->level($f, 2, 'start_headcount') + $D->level($f, 3, 'start_headcount');
        }

        $trust = $D->c('trust_start');
        $strain = $D->c('strain_start');
        $influence = $D->c('influence_start');
        $checkingYears = 0;
        $knowPeople = $D->c('knowledge_start');
        $knowHeld = 0.0;
        $d = self::DEFAULT;
        $rows = [];

        for ($y = $y0; $y <= $y1; $y++) {
            $new = $team[(string) $y] ?? null;
            $layoffs = [];
            if (is_array($new)) {
                foreach ($new as $key => $value) {
                    if ($key === 'workflows' && is_array($value)) {
                        $d['workflows'] = array_merge($d['workflows'], $value);
                    } else {
                        $d[$key] = $value;
                    }
                }
                $layoffs = is_array($new['layoffs'] ?? null) ? $new['layoffs'] : [];
            }
            $d['layoffs'] = [];
            $fx = $D->futures[$future][$y];
            $caps = [$fx['ai_entry_cap'], $fx['ai_mid_cap'], $fx['ai_expert_cap'], 0.0];
            $cost = 0.0;
            $notes = [];
            $strong = $d['oversight'] === 'strong';
            $planning = (string) $d['planning'];
            $knowledgeChoice = (string) $d['knowledge'];

            // One-off layoffs at the start of a decision year.
            $laid = 0.0;
            foreach ($layoffs as $f => $shares) {
                foreach ($shares as $i => $s) {
                    $n = $hc[$f][$i] * (float) $s;
                    $hc[$f][$i] -= $n;
                    $laid += $n;
                    $cost += $n * $D->level($f, $i, 'salary') * $D->c('severance_months') / 12.0;
                }
            }
            $totalStart = 0.0;
            foreach ($families as $f) {
                $totalStart += array_sum($hc[$f]);
            }
            if ($laid != 0.0) {
                $trust -= $D->c('layoff_trust_hit') * ($laid / max(1.0, $totalStart + $laid)) * 10;
                if ($d['transparency']) {
                    $trust += 0.25 * $D->c('layoff_trust_hit') * ($laid / ($totalStart + $laid)) * 10;
                }
            }
            $trust = $this->clamp($trust + ($d['share_gains'] ? $D->c('share_gain_trust') : 0) + ($d['transparency'] ? $D->c('transparency_trust') : 0)
                + ($d['board_report'] ? 0.5 : 0), 0, 100);

            // Firm knowledge: the vendor changes its terms once.
            if ($y === (int) $D->c('vendor_terms_year') && $knowledgeChoice === 'vendor') {
                $lost = $knowHeld * $D->c('vendor_knowledge_loss');
                $knowHeld -= $lost;
                $notes[] = 'Vendor terms changed: '.sprintf('%.0f', $lost).' points of firm knowledge lost';
            }
            $knowledge = $knowPeople + $knowHeld;
            $kf = 1 - $D->c('knowledge_productivity') * (1 - min(1.2, $knowledge / $D->c('knowledge_start')));
            $prod = (1 + 0.004 * ($trust - 60)) * (1 - $D->c('strain_productivity') * max(0, $strain - 40));

            // Work: each workflow's way of working sets the AI share at each level.
            $split = [];
            foreach ($families as $f) {
                $split[$f] = $this->aiSplit($d['workflows'], $caps, $f);
            }
            $a = [];
            foreach ($families as $f) {
                $a[$f] = $split[$f]['a'];
            }
            $demand = $D->c('demand_start') * (1 + $D->c('demand_growth')) ** ($y - $y0);
            $mix = $D->list('level_mix');
            $maturity = $this->clamp(($caps[0] - 0.20) / 0.75, 0, 1);
            $planReview = ['hr' => 1.10, 'it' => 1.0, 'joint' => 0.90][$planning];
            $reviewRate = [];
            foreach ($modes as $m) {
                $reviewRate[$m] = ($D->modes[$m]['review'] + ($strong && $m !== 'people' ? $D->c('review_strong_extra') : 0))
                    * (1 - $D->c('review_learning_fall') * $maturity) * $planReview;
            }
            if ($d['checking']) {
                $checkingYears++;
            }
            $checkFactor = 1 - $D->c('checking_cut') * min(1.0, $checkingYears / $D->c('checking_build_years'));
            $rpu = [];
            foreach ($families as $f) {
                $s = 0.0;
                foreach ($modes as $m) {
                    for ($i = 0; $i < 3; $i++) {
                        $s += $mix[$i] * $split[$f]['rev'][$m][$i] * $reviewRate[$m];
                    }
                }
                $rpu[$f] = $checkFactor * $s;
            }

            $units = function (string $f, float $scale) use ($hc, $D, $prod, $kf, $d, $mix, $a, $rpu): array {
                $cap = [];
                $need = [];
                for ($i = 0; $i < 4; $i++) {
                    $cap[$i] = $hc[$f][$i] * $D->level($f, $i, 'work_per_person') * $prod * ($i >= 2 ? $kf : 1) * (1 - ($i >= 2 ? (float) $d['mentoring'] : 0));
                    $need[$i] = $mix[$i] * (1 - $a[$f][$i]) * $scale;
                }
                $need[1] += $rpu[$f] * $D->c('review_to_l1') * $scale;
                $need[2] += $rpu[$f] * (1 - $D->c('review_to_l1')) * $scale;
                $u = INF;
                for ($k = 0; $k < 4; $k++) {
                    $sn = array_sum(array_slice($need, $k));
                    if ($sn > 1e-12) {
                        $u = min($u, array_sum(array_slice($cap, $k)) / $sn);
                    }
                }

                return [$u, $cap];
            };
            [$uDel, $capD] = $units('delivery', 1.0);
            [$uOps, $capO] = $units('operations', $D->c('ops_support_ratio'));
            $delivered = min($demand, $uDel, $uOps);
            $aiShare = 0.0;
            for ($i = 0; $i < 4; $i++) {
                $aiShare += $mix[$i] * $a['delivery'][$i];
            }
            $reviewUnits = $delivered * ($rpu['delivery'] + $D->c('ops_support_ratio') * $rpu['operations']);

            // The outage: AI work is lost unless people have spare room to cover it.
            if ($y === (int) $D->c('outage_year')) {
                $spare = max(0.0, min($uDel, $uOps) - $delivered) / max($delivered, 1e-9);
                $aiAll = 0.0;
                $alone = 0.0;
                foreach ($families as $f) {
                    $aiAll += array_sum($split[$f]['a']);
                    $alone += array_sum($split[$f]['by']['alone']);
                }
                $spread = $D->c('outage_alone_base') + $D->c('outage_alone_slope') * ($alone / max($aiAll, 1e-9));
                $lost = $D->c('outage_loss') * $spread * $aiShare * $delivered * max(0.0, 1 - $spare / max($aiShare, 1e-9));
                $delivered -= $lost;
                $notes[] = 'Outage cost '.sprintf('%.0f', $lost).' units of work';
            }

            $revenue = $delivered * $D->c('revenue_per_unit') * $fx['price_index'];
            $salaries = 0.0;
            foreach ($families as $f) {
                for ($i = 0; $i < 4; $i++) {
                    $salaries += $hc[$f][$i] * $D->level($f, $i, 'salary');
                }
            }
            $aiCost = 0.0;
            $naive = 0.0;
            $aiUnits = 0.0;
            foreach (['delivery' => 1.0, 'operations' => $D->c('ops_support_ratio')] as $f => $scale) {
                for ($i = 0; $i < 3; $i++) {
                    $u = $delivered * $scale * $mix[$i] * $a[$f][$i];
                    $perUnit = $D->level($f, $i, 'salary') / $D->level($f, $i, 'work_per_person');
                    $aiCost += $u * $fx['ai_cost_per_unit'] * $perUnit;
                    $naive += $u * $perUnit;
                    $aiUnits += $u;
                }
            }
            if ($knowledgeChoice === 'vendor' && $y >= (int) $D->c('vendor_terms_year')) {
                $aiCost *= 1 + $D->c('vendor_cost_rise');
            }
            if ($planning === 'it') {
                $aiCost *= 0.95;
            }
            $aiCost *= 1 + ($strong ? $D->c('oversight_cost') : 0);
            $risk = $split['delivery']['risk'] + $split['operations']['risk'] * $D->c('ops_support_ratio');

            // The verification wall: checking needed against the share of senior time that can go to checking.
            $seniorUnits = $capD[1] + $capD[2] + $capO[1] + $capO[2];
            $wallRatio = $reviewUnits / max(1e-9, $D->c('wall_share') * $seniorUnits);
            $over = max(0.0, $wallRatio - 1);
            $incidents = $fx['incident_rate'] * 100 * $risk * (1 + $D->c('strain_errors') * max(0, $strain - 40)) * (1 + $D->c('wall_errors') * $over)
                * ($planning === 'joint' ? 0.9 : 1);
            $incidentCost = $incidents * $D->c('incident_cost') * ($strong ? 1 - $D->c('oversight_cut') : 1);
            $regulation = $fx['regulation_cost'] * $revenue * $aiShare * ($strong ? 0.5 : 1.0);
            $bm0 = $split['delivery']['by'];
            $entryAi = $a['delivery'][0];
            $coached = $entryAi > 0 ? ($bm0['assist'][0] + $bm0['draft_check'][0] * (float) $d['tutor']) / $entryAi : 0.0;
            $tutorCost = 8000 * $hc['delivery'][0] * $coached;
            $hires = [];
            foreach ($families as $f) {
                $hires[$f] = $baseIntake[$f] * (float) $d['intake'][$f];
            }
            $hireCost = array_sum($hires) * 5000;
            $extra = ['people' => 0.0, 'firm' => $D->c('knowledge_cost_firm'), 'vendor' => $D->c('knowledge_cost_vendor')][$knowledgeChoice]
                + ($planning === 'joint' ? $D->c('planning_joint_cost') : 0) + ($d['board_report'] ? $D->c('board_report_cost') : 0)
                + ($d['checking'] ? $D->c('checking_cost') : 0);
            $cost += $salaries + $aiCost + $incidentCost + $regulation + $tutorCost + $hireCost + $D->c('overhead') + $extra;
            $profit = $revenue - $cost;

            // Strain: checking AI and stretched teams wear people down.
            $seniors = 0.0;
            foreach ($families as $f) {
                $seniors += $hc[$f][1] + $hc[$f][2];
            }
            $stretch = max(0.0, $delivered / max(1e-9, min($uDel, $uOps)) - 0.95);
            $strain += $D->c('strain_per_review') * $reviewUnits / max(1.0, $seniors) + $D->c('strain_per_stretch') * $stretch
                - ($d['share_gains'] ? $D->c('strain_relief_gains') : 0) + ($planning === 'it' ? 1.5 : 0) + $D->c('wall_strain') * $over
                - $D->c('strain_recovery') * ($strain - $D->c('strain_start'));
            $strain = $this->clamp($strain, 0, 100);

            // People move at the end of the year.
            $ment = $this->clamp((float) $d['mentoring'] / $D->c('mentor_full'), 0, 1);
            $latMarket = $D->c('lateral_market_base') * ($future === 'rapid' ? $D->c('lateral_scarcity_rapid') : 1.0);
            $tutor = (float) $d['tutor'];
            $bench = [];
            $out23 = 0.0;
            foreach ($families as $f) {
                $scale = $f === 'delivery' ? 1.0 : $D->c('ops_support_ratio');
                $bm = $split[$f]['by'];
                $nextNeed = [];
                for ($i = 0; $i < 4; $i++) {
                    $nextNeed[$i] = $demand * (1 + $D->c('demand_growth')) * $scale * $mix[$i] * (1 - $a[$f][$i]) / $prod;
                }
                $reps = function (int $i) use ($bm, $modes, $D, $tutor): float {
                    $lost = 0.0;
                    foreach ($modes as $m) {
                        $lost += $bm[$m][$i] * $D->modes[$m]['lost'];
                    }
                    $lost -= $bm['draft_check'][$i] * $tutor;
                    $r = 1 - $lost * $D->c('substitute_penalty');
                    if ($i === 1) {
                        $r -= $D->c('check_deskill') * ($bm['draft_check'][1] + $bm['draft_check'][0]);
                    }

                    return $r;
                };
                $h = $hc[$f];
                $out = [];
                for ($i = 0; $i < 4; $i++) {
                    $attr = $D->level($f, $i, 'attrition_base') + $D->c('trust_attrition') * max(0, 60 - $trust)
                        + ($i === 0 ? 0.06 * (1 - $reps(0)) : 0)
                        + (($i === 1 || $i === 2) ? $D->c('strain_attrition') * max(0, $strain - 40) : 0);
                    $out[$i] = $h[$i] * ($attr + $D->level($f, $i, 'retire'));
                }
                $out23 += $out[2] + $out[3];
                for ($i = 0; $i < 4; $i++) {
                    $h[$i] -= $out[$i];
                }
                foreach ([2, 1, 0] as $i) {
                    $assistShare = $a[$f][$i] > 0 ? $bm['assist'][$i] / max(1e-9, $a[$f][$i]) : 0.0;
                    $tutorEff = $a[$f][$i] > 0 ? $this->clamp($assistShare + (1 - $assistShare) * $tutor, 0, 1) : 0.0;
                    $tutorBonus = $D->c('learn_tutor_bonus') * $tutorEff * min(1.0, $caps[0] / 0.5);
                    $lf = $D->c('learn_reps_weight') * $reps($i) + $D->c('learn_mentor_weight') * $ment
                        + (1 - $D->c('learn_reps_weight') - $D->c('learn_mentor_weight')) + $tutorBonus;
                    $ready = $h[$i] * $D->level($f, $i, 'promo_base') * 2.0 * $lf;
                    $gap = max(0.0, $nextNeed[$i + 1] - $h[$i + 1]);
                    $p = min($ready, $gap);
                    $h[$i] -= $p;
                    $h[$i + 1] += $p;
                    $still = max(0.0, $nextNeed[$i + 1] - $h[$i + 1]);
                    if ($still > 0 && (float) $d['lateral'] > 0) {
                        $lat = min($still, (float) $d['lateral'] * $latMarket * ($h[$i + 1] + $still));
                        $h[$i + 1] += $lat;
                        $profit -= $lat * $D->level($f, $i + 1, 'salary') * ($D->c('lateral_cost_mult') - 1);
                    }
                }
                $h[0] += $hires[$f];
                $hc[$f] = $h;
                $bench[$f] = (0.3 * $h[0] + $h[1]) / max(1e-9, $nextNeed[2] + $nextNeed[3]) / $startPipe;
            }

            // Knowledge leaves with experts unless it was captured; experts at work renew it.
            $now23 = 0.0;
            foreach ($families as $f) {
                $now23 += $hc[$f][2] + $hc[$f][3];
            }
            $capture = $D->c('knowledge_capture_'.$knowledgeChoice);
            $leaveShare = $out23 / max(1.0, $now23 + $out23);
            $knowHeld += $knowPeople * $leaveShare * $capture;
            $knowPeople -= $knowPeople * $leaveShare;
            $knowPeople += $D->c('knowledge_renew') * $D->c('knowledge_start') * min(1.0, $now23 / $start23) * (0.85 + 0.15 * $ment);
            $knowPeople = $this->clamp($knowPeople, 0, 1.2 * $D->c('knowledge_start'));
            $knowHeld = $this->clamp($knowHeld, 0, 0.6 * $D->c('knowledge_start'));

            $influence += ['hr' => 1.0, 'it' => -3.0, 'joint' => 2.0][$planning] + ($d['board_report'] ? 2.5 : 0)
                + ($profit > 0.15 * $revenue ? 2 : -2) + ($delivered / $demand > 0.95 ? 1 : -2);
            $influence = $this->clamp($influence, 0, 100);
            $trust += 0.10 * (60 - $trust);

            $headcount = 0.0;
            foreach ($families as $f) {
                $headcount += array_sum($hc[$f]);
            }
            $rows[] = [
                'year' => $y, 'future' => $future, 'demand' => $demand, 'delivered' => $delivered,
                'revenue' => $revenue, 'cost' => $cost, 'profit' => $profit, 'ai_cost' => $aiCost, 'naive_saving' => $naive, 'ai_units' => $aiUnits,
                'review_units' => $reviewUnits, 'wall_ratio' => $wallRatio, 'strain' => $strain, 'knowledge' => $knowPeople + $knowHeld,
                'knowledge_held' => $knowHeld, 'influence' => $influence,
                'ai_share' => $aiShare, 'incidents' => $incidents, 'trust' => $trust,
                'headcount' => $headcount,
                'delivery_levels' => array_map(fn (float $x): float => round($x, 1), $hc['delivery']),
                'operations_levels' => array_map(fn (float $x): float => round($x, 1), $hc['operations']),
                'expert_bench' => $bench['delivery'], 'ops_bench' => $bench['operations'],
                'met_demand' => $delivered / $demand, 'notes' => $notes,
            ];
        }

        return $rows;
    }

    /**
     * The overall result of a play, as in economics/model.py summary().
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{cum_profit_m: float, end_bench: float, end_met: float, end_trust: float, end_headcount: float, financial: float, capability: float, overall: float}
     */
    public function summary(array $rows): array
    {
        $profit = 0.0;
        foreach ($rows as $row) {
            $profit += (float) $row['profit'];
        }
        $end = $rows[count($rows) - 1];
        $financial = $this->clamp($profit / 1e9, 0, 1);
        $capability = $this->clamp(0.5 * min((float) $end['expert_bench'], 1.0) + 0.5 * (float) $end['met_demand'], 0, 1);
        $trust = (float) $end['trust'] / 100;
        $overall = 100 * (self::WEIGHTS['financial'] * $financial + self::WEIGHTS['capability'] * $capability + self::WEIGHTS['trust'] * $trust);

        return [
            'cum_profit_m' => $profit / 1e6, 'end_bench' => (float) $end['expert_bench'], 'end_met' => (float) $end['met_demand'],
            'end_trust' => (float) $end['trust'], 'end_headcount' => (float) $end['headcount'],
            'financial' => $financial, 'capability' => $capability, 'overall' => $overall,
        ];
    }

    /**
     * The same decisions replayed under all three futures; judgment is the worst overall result.
     *
     * @param  array<string, array<string, mixed>>  $team
     * @return array{judgment: float, overall: array<string, float>}
     */
    public function judgment(array $team): array
    {
        $overall = [];
        foreach (ModelData::FUTURES as $future) {
            $overall[$future] = $this->summary($this->play($team, $future))['overall'];
        }

        return ['judgment' => min($overall), 'overall' => $overall];
    }

    /**
     * AI share of each level's work, the parts of it by way of working, the checking weight by way of working,
     * and error risk, for one family. Task types follow verification economics.
     *
     * @param  array<string, mixed>  $workflows
     * @param  list<float>  $caps
     * @return array{a: list<float>, by: array<string, list<float>>, risk: float, rev: array<string, list<float>>}
     */
    private function aiSplit(array $workflows, array $caps, string $family): array
    {
        $D = $this->data;
        $a = [0.0, 0.0, 0.0, 0.0];
        $by = [];
        $rev = [];
        foreach (array_keys($D->modes) as $m) {
            $by[$m] = [0.0, 0.0, 0.0, 0.0];
            $rev[$m] = [0.0, 0.0, 0.0, 0.0];
        }
        $risk = 0.0;
        foreach ($D->workflows[$family] as $w) {
            $m = (string) ($workflows[$w['key']] ?? 'people');
            $mode = $D->modes[$m];
            $t = $D->taskTypes[$w['type']];
            for ($i = 0; $i < 3; $i++) {
                $s = $w['share'] * min(1.0, $mode['ai'][$i] * $caps[$i] * $w['fit']);
                $a[$i] += $s;
                $by[$m][$i] += $s;
                $rev[$m][$i] += $s * $t['review'];
            }
            $err = ($m === 'draft_check' || $m === 'alone') ? $t['error'] : 1.0;
            $r2 = 0.0;
            for ($i = 0; $i < 2; $i++) {
                $r2 += min(1.0, $mode['ai'][$i] * $caps[$i] * $w['fit']);
            }
            $risk += $w['share'] * $w['risk'] * $err * $mode['incident'] * $r2 / 2;
        }

        return ['a' => $a, 'by' => $by, 'risk' => $risk, 'rev' => $rev];
    }

    private function clamp(float $x, float $lo, float $hi): float
    {
        return max($lo, min($hi, $x));
    }
}
