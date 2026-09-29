<?php
namespace App\Interp;

use App\Calc\{Dasha, KundaliService, Zodiac};

/**
 * Category predictions (career, love, stock market, ...) for daily / weekly / monthly / yearly / lifetime.
 * Each factor comes from the calculated chart: natal strength of the category's significator planets,
 * the category houses and their lords, the running dasha, and transits (gochar) for the period.
 * The score is an astrological favourability score, not a probability of any outcome.
 */
final class CategoryPredictor extends RuleEngine {
    public const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'lifetime'];
    private const FAST = ['Moon', 'Sun', 'Mercury', 'Venus', 'Mars'];
    private const SLOW = ['Jupiter', 'Saturn', 'Rahu', 'Ketu'];
    private const BENEFIC = ['Jupiter', 'Venus', 'Mercury', 'Moon'];

    /** @param array $cat category row; $local = reference local date (DateTimeImmutable, profile timezone). */
    public function predict(array $k, array $cat, string $period, \DateTimeImmutable $local, float $lat, float $lon): array {
        $P = array_values(array_filter(array_map('trim', explode(',', $cat['planets'])), fn($x) => isset(self::LK_BAD[$x])));
        $H = array_values(array_filter(array_map('intval', explode(',', $cat['houses'])), fn($x) => $x >= 1 && $x <= 12));
        [$range, $refs] = $this->range($period, $local);
        $by = $this->by($k); $F = [];

        // 1. natal strength of significators (always; heavier for lifetime)
        $wN = $period === 'lifetime' ? 2 : 1;
        foreach ($P as $pl) {
            $d = $by[$pl]['dignity'] ?? null;
            if ($d === 'exalted' || $d === 'own') $F[] = $this->f(+$wN, "natal_$d", ['planet' => $this->pn($pl)]);
            if ($d === 'debilitated') $F[] = $this->f(-$wN, 'natal_debilitated', ['planet' => $this->pn($pl)]);
            $good = !in_array($by[$pl]['house'], self::LK_BAD[$pl], true);
            $F[] = $this->f($good ? $wN : -$wN, $good ? 'lk_good' : 'lk_bad', ['planet' => $this->pn($pl), 'h' => $by[$pl]['house']]);
        }
        // 2. category houses: lords placed well / in 6-8-12
        $lords = [];
        foreach ($H as $h) {
            $lord = Zodiac::SIGN_LORDS[($k['lagna']['sign'] + $h - 1) % 12]; $lords[$lord] = true; $lh = $by[$lord]['house'];
            if (in_array($lh, [6, 8, 12], true) && !in_array($h, [6, 8, 12], true)) $F[] = $this->f(-$wN, 'lord_dusthana', ['h' => $h, 'planet' => $this->pn($lord), 'lh' => $lh]);
            elseif (in_array($lh, [1, 4, 5, 7, 9, 10], true)) $F[] = $this->f(+$wN, 'lord_strong', ['h' => $h, 'planet' => $this->pn($lord), 'lh' => $lh]);
        }
        $rel = fn(string $pl) => in_array($pl, $P, true) || isset($lords[$pl]);

        if ($period === 'lifetime') {
            $timeline = [];
            foreach ($k['dasha']['mahadasha'] as $md) if ($rel($md['lord']) && $md['end'] >= $local->format('Y-m-d')) {
                $good = !in_array($by[$md['lord']]['house'], self::LK_BAD[$md['lord']], true);
                $timeline[] = ['lord' => $md['lord'], 'name' => $this->pn($md['lord']), 'start' => $md['start'], 'end' => $md['end'], 'favourable' => $good];
            }
            foreach (array_slice($timeline, 0, 3) as $tl) $F[] = $this->f($tl['favourable'] ? 1 : -1, $tl['favourable'] ? 'md_future_good' : 'md_future_bad',
                ['planet' => $tl['name'], 'from' => $tl['start'], 'to' => $tl['end']]);
            return $this->finish($cat, $period, $range, $F) + ['timeline' => $timeline];
        }

        // 3. dasha running at the reference moment (not for a single day)
        $ks = new KundaliService();
        $trs = array_map(fn($d) => $ks->transits($k, $d->getTimestamp() / 86400 + 2440587.5, $lat, $lon), $refs);
        $now = $trs[intdiv(count($trs), 2)]['dasha_now'] ?? null;
        if ($period !== 'daily' && $now) foreach (['mahadasha' => 2, 'antardasha' => 1] as $lvl => $w) {
            $pl = $now[$lvl] ?? null; if (!$pl || !$rel($pl)) continue;
            $good = !in_array($by[$pl]['house'], self::LK_BAD[$pl], true);
            $F[] = $this->f($good ? $w : -$w, "dasha_{$lvl}_" . ($good ? 'good' : 'bad'), ['planet' => $this->pn($pl)]);
        }
        // 4. transits: period-relevant planets (plus significators of matching speed), from Moon and over category houses
        $set = match ($period) { 'daily' => ['Moon', ...array_intersect($P, self::FAST)], 'yearly' => [...self::SLOW],
            default => ['Sun', 'Mercury', 'Venus', 'Mars', ...array_intersect($P, self::SLOW)] };
        $set = array_values(array_unique($set)); $n = count($trs); $mid = $trs[intdiv($n, 2)];
        foreach ($set as $pl) {
            // average over the sampled dates; describe the position at the middle of the period
            $at = fn(array $tr) => current(array_filter($tr['planets'], fn($t) => $t['name'] === $pl));
            $m = $at($mid); $w = $rel($pl) ? 1.5 : 1;
            $g = array_sum(array_map(fn($tr) => in_array($at($tr)['house_from_moon'], self::GOCHAR_GOOD[$pl], true) ? 1 : -1, $trs)) / $n;
            if ($g != 0) $F[] = $this->f($g * $w, $g > 0 ? 'transit_good' : 'transit_bad', ['planet' => $this->pn($pl), 'h' => $m['house_from_moon']]);
            if ($pl === 'Moon') continue;
            $inH = array_sum(array_map(fn($tr) => in_array($at($tr)['house_from_lagna'], $H, true) ? 1 : 0, $trs)) / $n;
            if ($inH >= 0.5) { $ben = in_array($pl, self::BENEFIC, true);
                $F[] = $this->f(($ben ? 1 : -1) * $w * $inH, $ben ? 'transit_house_good' : 'transit_house_bad', ['planet' => $this->pn($pl), 'h' => $m['house_from_lagna']]); }
        }
        if ($period === 'daily') {
            $ti = $mid['derived']['tara']['index'];
            if ($ti !== 0) $F[] = $this->f(in_array($ti, [2, 4, 6], true) ? -1 : 1, in_array($ti, [2, 4, 6], true) ? 'tara_bad' : 'tara_good', ['tara' => $this->t("astro.taras.$ti")]);
            if ($mid['derived']['chandrashtama']['active']) $F[] = $this->f(-2, 'chandrashtama', []);
        }
        return $this->finish($cat, $period, $range, $this->merge($F));
    }

    /** Period bounds for display and the local dates at which transits are sampled. */
    private function range(string $period, \DateTimeImmutable $d): array {
        $d = $d->setTime(12, 0);
        switch ($period) {
            case 'daily': return [['date' => $d->format('Y-m-d')], [$d]];
            case 'weekly': $s = $d->modify('monday this week'); $e = $s->modify('+6 days');
                return [['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d')], [$s, $s->modify('+3 days'), $e]];
            case 'monthly': $s = $d->modify('first day of this month'); $e = $d->modify('last day of this month');
                return [['month' => $d->format('Y-m'), 'start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d')], [$s, $s->modify('+14 days'), $e]];
            case 'yearly': $y = $d->format('Y');
                return [['year' => (int) $y, 'start' => "$y-01-01", 'end' => "$y-12-31"], array_map(fn($m) => $d->setDate((int) $y, $m, 15), [1, 4, 7, 10])];
            default: return [['from' => $d->format('Y-m-d')], []];
        }
    }

    /** Same factor text seen at several sample dates → one factor with the summed weight. */
    private function merge(array $F): array {
        $o = []; foreach ($F as $f) { $key = $f['text']; if (isset($o[$key])) $o[$key]['w'] += $f['w']; else $o[$key] = $f; }
        return array_values(array_filter($o, fn($f) => abs($f['w']) > 0.01));
    }

    private function f(float $w, string $key, array $vars): array { return ['w' => $w, 'key' => $key, 'text' => $this->t("pred.f.$key", $vars)]; }

    private function finish(array $cat, string $period, array $range, array $F): array {
        $sum = array_sum(array_column($F, 'w')); $max = array_sum(array_map(fn($f) => abs($f['w']), $F)) ?: 1;
        $score = (int) max(5, min(95, round(50 + 45 * $sum / $max)));
        $level = $score >= 62 ? 'favourable' : ($score <= 40 ? 'challenging' : 'mixed');
        usort($F, fn($a, $b) => abs($b['w']) <=> abs($a['w']));
        $pos = array_values(array_column(array_filter($F, fn($f) => $f['w'] > 0), 'text'));
        $neg = array_values(array_column(array_filter($F, fn($f) => $f['w'] < 0), 'text'));
        $name = $cat['name_' . $this->lang] ?: $cat['name_en'];
        $text = $this->t("pred.summary.$level", ['cat' => $name, 'period' => $this->t("pred.period.$period")]) . ' '
              . $this->t('pred.summary.counts', ['p' => count($pos), 'n' => count($neg)]) . ' '
              . $this->t("pred.advice.$level") . ($cat['caution'] ? ' ' . $this->t('pred.caution') : '');
        return ['meta' => ['type' => 'interpretation', 'kind' => 'category_prediction', 'ruleset' => self::VERSION, 'lang' => $this->lang],
                'category' => ['id' => (int) $cat['id'], 'slug' => $cat['slug'], 'name' => $name, 'icon' => $cat['icon'], 'caution' => (bool) $cat['caution']],
                'period' => $period, 'range' => $range, 'score' => $score, 'score_label' => $this->t('pred.score_label'), 'level' => $level,
                'level_text' => $this->t("pred.level.$level"), 'explanation' => $text, 'positive' => $pos, 'challenging' => $neg,
                'disclaimer' => $this->t('pred.disclaimer')];
    }
}
