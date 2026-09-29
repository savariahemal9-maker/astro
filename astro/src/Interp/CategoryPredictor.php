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
    private array $k = [], $by = [], $relPlanets = [], $timing = [], $day = [];

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
            if ($d === 'exalted' || $d === 'own') $F[] = $this->f(+$wN, "natal_$d", ['planet' => $this->pn($pl)], $pl);
            if ($d === 'debilitated') $F[] = $this->f(-$wN, 'natal_debilitated', ['planet' => $this->pn($pl)], $pl);
            $good = !in_array($by[$pl]['house'], self::LK_BAD[$pl], true);
            $F[] = $this->f($good ? $wN : -$wN, $good ? 'lk_good' : 'lk_bad', ['planet' => $this->pn($pl), 'h' => $by[$pl]['house']], $pl);
        }
        // 2. category houses: lords placed well / in 6-8-12
        $lords = [];
        foreach ($H as $h) {
            $lord = Zodiac::SIGN_LORDS[($k['lagna']['sign'] + $h - 1) % 12]; $lords[$lord] = true; $lh = $by[$lord]['house'];
            if (in_array($lh, [6, 8, 12], true) && !in_array($h, [6, 8, 12], true)) $F[] = $this->f(-$wN, 'lord_dusthana', ['h' => $h, 'planet' => $this->pn($lord), 'lh' => $lh], $lord);
            elseif (in_array($lh, [1, 4, 5, 7, 9, 10], true)) $F[] = $this->f(+$wN, 'lord_strong', ['h' => $h, 'planet' => $this->pn($lord), 'lh' => $lh], $lord);
        }
        $rel = fn(string $pl) => in_array($pl, $P, true) || isset($lords[$pl]);
        $this->k = $k; $this->by = $by; $this->relPlanets = array_values(array_unique([...$P, ...array_keys($lords)])); $this->timing = []; $this->day = [];

        if ($period === 'lifetime') {
            $timeline = [];
            foreach ($k['dasha']['mahadasha'] as $md) if ($rel($md['lord']) && $md['end'] >= $local->format('Y-m-d')) {
                $good = !in_array($by[$md['lord']]['house'], self::LK_BAD[$md['lord']], true);
                $timeline[] = ['lord' => $md['lord'], 'name' => $this->pn($md['lord']), 'start' => $md['start'], 'end' => $md['end'], 'favourable' => $good];
            }
            foreach (array_slice($timeline, 0, 3) as $tl) $F[] = $this->f($tl['favourable'] ? 1 : -1, $tl['favourable'] ? 'md_future_good' : 'md_future_bad',
                ['planet' => $tl['name'], 'from' => $tl['start'], 'to' => $tl['end']], $tl['lord']);
            return $this->finish($cat, $period, $range, $F) + ['timeline' => $timeline];
        }

        // 3. dasha running at the reference moment (not for a single day)
        $ks = new KundaliService();
        $trs = array_map(fn($d) => $ks->transits($k, $d->getTimestamp() / 86400 + 2440587.5, $lat, $lon), $refs);
        $now = $trs[intdiv(count($trs), 2)]['dasha_now'] ?? null;
        if ($period !== 'daily' && $now) foreach (['mahadasha' => 2, 'antardasha' => 1] as $lvl => $w) {
            $pl = $now[$lvl] ?? null; if (!$pl || !$rel($pl)) continue;
            $good = !in_array($by[$pl]['house'], self::LK_BAD[$pl], true);
            $F[] = $this->f($good ? $w : -$w, "dasha_{$lvl}_" . ($good ? 'good' : 'bad'), ['planet' => $this->pn($pl)], $pl);
            $this->timing[] = ['good' => $good, 'until' => $this->dashaEnd($lvl, $local->format('Y-m-d'))];
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
            if ($g != 0) $F[] = $this->f($g * $w, $g > 0 ? 'transit_good' : 'transit_bad', ['planet' => $this->pn($pl), 'h' => $m['house_from_moon']], $pl);
            if ($pl === 'Moon') continue;
            $inH = array_sum(array_map(fn($tr) => in_array($at($tr)['house_from_lagna'], $H, true) ? 1 : 0, $trs)) / $n;
            if ($inH >= 0.5) { $ben = in_array($pl, self::BENEFIC, true);
                $F[] = $this->f(($ben ? 1 : -1) * $w * $inH, $ben ? 'transit_house_good' : 'transit_house_bad', ['planet' => $this->pn($pl), 'h' => $m['house_from_lagna']], $pl); }
        }
        if ($period === 'daily') {
            $ti = $mid['derived']['tara']['index'];
            if ($ti !== 0) $this->day[] = in_array($ti, [2, 4, 6], true) ? 'tara_bad' : 'tara_good';
            if ($mid['derived']['chandrashtama']['active']) $this->day[] = 'chandrashtama';
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

    private function f(float $w, string $key, array $vars, ?string $pl = null): array { return ['w' => $w, 'key' => $key, 'pl' => $pl, 'vars' => $vars, 'text' => $this->t("pred.f.$key", $vars)]; }

    private function lines(?string $s): array { return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $s)))); }
    private function field(array $cat, string $k): array { return $this->lines($cat["{$k}_{$this->lang}"] ?? '') ?: $this->lines($cat["{$k}_en"] ?? ''); }

    private function finish(array $cat, string $period, array $range, array $F): array {
        $sum = array_sum(array_column($F, 'w')); $max = array_sum(array_map(fn($f) => abs($f['w']), $F)) ?: 1;
        $score = (int) max(5, min(95, round(50 + 45 * $sum / $max)));
        $level = $score >= 62 ? 'favourable' : ($score <= 40 ? 'challenging' : 'mixed');
        usort($F, fn($a, $b) => abs($b['w']) <=> abs($a['w']));
        $pos = array_values(array_column(array_filter($F, fn($f) => $f['w'] > 0), 'text'));
        $neg = array_values(array_column(array_filter($F, fn($f) => $f['w'] < 0), 'text'));
        $name = $cat['name_' . $this->lang] ?: $cat['name_en']; $v = ['cat' => $name, 'period' => $this->t("pred.period.$period")];
        [, , $upay, $personal] = $this->advice($cat, $F);
        $personal = [];   // general natal readings are not specific to the chosen topic, so they are not shown
        $fx = fn(callable $keep) => array_values(array_map(fn($f) => ['text' => $f['text'], 'effect' => $this->t("pred.fx.{$f['key']}", $f['vars'] + ['cat' => $name]
            + ($f['pl'] ? ['domain' => $this->t("interp.planet_domain.{$f['pl']}")] : []))], array_filter($F, $keep)));
        // "in your favour" / "watch out for": the calculated factors of this chart, in plain words, strongest first
        $do = []; $dont = [];   // the reasons are available under "Why?"; separate do / avoid lists were too similar across topics
        return ['meta' => ['type' => 'interpretation', 'kind' => 'category_prediction', 'ruleset' => self::VERSION, 'lang' => $this->lang],
                'category' => ['id' => (int) $cat['id'], 'slug' => $cat['slug'], 'name' => $name, 'icon' => $cat['icon'], 'caution' => (bool) $cat['caution']],
                'period' => $period, 'range' => $range, 'score' => $score, 'score_label' => $this->t('pred.score_label'), 'level' => $level,
                'level_text' => $this->t("pred.level.$level"), 'headline' => $this->t("pred.headline.$level", $v),
                'explanation' => $this->t("pred.simple.$level", $v) . ' ' . $this->t('pred.summary.counts', ['p' => count($pos), 'n' => count($neg)]),
                'personal' => $personal, 'do' => $do, 'dont' => $dont, 'upay' => $upay,
                'caution' => $cat['caution'] ? $this->t('pred.caution') : null,
                'details' => ['positive' => $fx(fn($f) => $f['w'] > 0), 'challenging' => $fx(fn($f) => $f['w'] < 0)], 'positive' => $pos, 'challenging' => $neg,
                'disclaimer' => $this->t('pred.disclaimer')];
    }

    /** End date (Y-m-d) of the running mahadasha / antardasha at $date. */
    /** English wording of a localized Lal Kitab remedy, used to judge whether it fits the topic. */
    private function enOf(string $text): string {
        foreach ($this->by as $pl => $p) for ($i = 0; $i < 4; $i++)
            if ($this->c("lk.$pl.{$p['house']}.rem.$i") === $text) return \App\I18n\Lang::content('en', "lk.$pl.{$p['house']}.rem.$i");
        return $text;
    }

    private function dashaEnd(string $lvl, string $date): ?string {
        foreach ($this->k['dasha']['mahadasha'] as $md) if ($md['start'] <= $date && $date < $md['end']) {
            if ($lvl === 'mahadasha') return $md['end'];
            foreach ($md['antardasha'] ?? [] as $ad) if ($ad['start'] <= $date && $date < $ad['end']) return $ad['end'];
        }
        return null;
    }

    /**
     * Advice built from this kundali: which of the topic's planets are strong or weak here (all factors summed per planet),
     * the Lal Kitab reading and remedies for each planet in the house it actually occupies, matching Lal Kitab combinations,
     * the running dasha and today's timing. The admin's general tip for the category is added last.
     */
    private function advice(array $cat, array $F): array {
        $ps = []; foreach ($F as $f) if ($f['pl']) $ps[$f['pl']] = ($ps[$f['pl']] ?? 0) + $f['w'];
        $strong = array_keys(array_filter($ps, fn($w) => $w > 0.01)); usort($strong, fn($a, $b) => $ps[$b] <=> $ps[$a]);
        $weak = array_keys(array_filter($ps, fn($w) => $w < -0.01)); usort($weak, fn($a, $b) => $ps[$a] <=> $ps[$b]);
        $do = []; $dont = []; $upay = []; $personal = [];
        // what the Lal Kitab says about the topic's planets in this chart (strongest influence first)
        $rel = $this->relPlanets; usort($rel, fn($a, $b) => abs($ps[$b] ?? 0) <=> abs($ps[$a] ?? 0));
        foreach (array_slice($rel, 0, 4) as $pl) {
            $h = $this->by[$pl]['house']; $good = !in_array($h, self::LK_BAD[$pl], true);
            if (($x = $this->c("lk.$pl.$h." . ($good ? 'good' : 'bad'))) !== '') $personal[] = ['text' => $x, 'good' => $good];
        }
        foreach (array_slice($strong, 0, 2) as $pl) $do[] = $this->t("pred.pl.$pl.do");
        foreach (array_slice($weak, 0, 2) as $pl) { $dont[] = $this->t("pred.pl.$pl.dont"); $do[] = $this->t("pred.pl.$pl.fix"); }
        if ($this->lang === 'en') foreach ($this->relPlanets as $pl) { $c = $this->lkCombos($this->k, $pl); array_push($do, ...$c['g']); array_push($dont, ...$c['c']); }
        // nearest change: the antardasha if it matters here, else the mahadasha
        foreach (array_slice(array_reverse($this->timing), 0, 1) as $tm) if ($tm['until']) ($tm['good'] ? $do[] = $this->t('pred.time.good', ['date' => $tm['until']]) : $dont[] = $this->t('pred.time.bad', ['date' => $tm['until']]));
        foreach ($this->day as $d) ($d === 'tara_good' ? $do[] = $this->t("pred.time.$d") : $dont[] = $this->t("pred.time.$d"));
        // upay: Lal Kitab remedies for the planets that need help, for the houses they occupy in this chart
        foreach ($weak ?: array_slice($rel, 0, 1) as $pl) {
            $h = $this->by[$pl]['house'];
            for ($i = 0; $i < 4; $i++) if (($x = $this->c("lk.$pl.$h.rem.$i")) !== '') $upay[] = $x;
        }
        $upay = array_values(array_unique($upay));
        if (!in_array($cat['slug'], ['love', 'marriage'], true)) {
            $off = '/marr|wedding|spouse|wife|husband|son|daughter|child|\\b\\d{2}\\b|in-law|widow/i';
            $keep = []; foreach ($upay as $x) { $en = $this->enOf($x); if (!preg_match($off, $en)) $keep[] = $x; }
            $upay = $keep ?: array_map(fn($pl) => $this->t("pred.pl.$pl.upay"), array_slice($weak ?: $rel, 0, 1));
        }
        $upay = array_slice($upay, 0, 3);
        $one = fn(string $k) => array_slice($this->field($cat, $k), 0, 1);
        $u = fn(array $a) => array_values(array_unique($a));
        // the admin's general tip only fills in when the chart gives little to say
        $fill = fn(array $a, string $k) => $u(count($a) >= 2 ? $a : [...$a, ...$one($k)]);
        return [$fill($do, 'dos'), $fill($dont, 'donts'), $upay ?: ($one('upay') ?: [$this->t('pred.upay_default')]), $personal];
    }

}
