<?php
namespace App\Interp;

use App\Calc\{Analysis, KundaliService, Zodiac};
use App\I18n\Lang;

/**
 * Personal remedy plan and pooja suggestions, built only from this kundali:
 * planet strength scores, houses (Lal Kitab), running dasha, doshas and transits.
 * Every item carries the chart reason it was chosen for, so two charts that share
 * a remedy still show why each one got it.
 */
final class RemedyPlanner extends RuleEngine {
    private Advanced $a; private array $sc = []; private array $by = []; private array $need = []; private array $k = [];
    private const FAST = ['Sun', 'Moon', 'Mars', 'Mercury', 'Venus'];
    private const JAAP = ['Sun' => 7000, 'Moon' => 11000, 'Mars' => 10000, 'Mercury' => 9000, 'Jupiter' => 19000, 'Venus' => 16000, 'Saturn' => 23000, 'Rahu' => 18000, 'Ketu' => 17000];

    public function __construct(string $lang, private float $lat = 0, private float $lon = 0) { parent::__construct($lang); $this->a = new Advanced($lang); }

    /** Planets that need help in this chart, most needy first: low strength, running dasha, difficult Lal Kitab house, lagna lord. */
    private function prepare(array $k, float $jd): void {
        $this->k = $k; $this->sc = $this->a->planetScores($k); $this->by = $this->by($k);
        $now = (new KundaliService())->transits($k, $jd, $this->lat, $this->lon)['dasha_now'] ?? [];
        $ll = Analysis::houseLord($k['lagna']['sign'], 1); $need = [];
        foreach ($this->sc as $pl => $s) {
            $w = 100 - $s['score'] + (($now['mahadasha'] ?? '') === $pl ? 30 : 0) + (($now['antardasha'] ?? '') === $pl ? 20 : 0)
               + (in_array($s['house'], self::LK_BAD[$pl], true) ? 10 : 0) + ($pl === $ll ? 10 : 0);
            if ($s['score'] < 50 || $w >= 60) $need[$pl] = $w;
        }
        arsort($need); $this->need = $need;
    }

    /** One-line chart reason for a planet, e.g. "Saturn: strength 32/100 · debilitated · house 8 (difficult in Lal Kitab) · Mahadasha till 2031-05-01". */
    private function why(string $pl, float $jd): string {
        $s = $this->sc[$pl]; $bits = [$this->t('rp.r.score', ['planet' => $this->pn($pl), 's' => $s['score']])];
        if ($s['dignity']) $bits[] = $this->t('astro.dignity.' . $s['dignity']);
        if (!empty($s['parts']['combust'])) $bits[] = $this->t('ui.combust');
        $bits[] = $this->t(in_array($s['house'], self::LK_BAD[$pl], true) ? 'rp.r.house_bad' : 'rp.r.house', ['h' => $s['house']]);
        if ($s['class'] === 'bad') $bits[] = $this->t('rp.r.malefic');
        foreach ($this->k['dasha']['mahadasha'] as $md) if ($md['start_jd'] <= $jd && $jd < $md['end_jd']) {
            if ($md['lord'] === $pl) $bits[] = $this->t('rp.r.md', ['to' => $md['end']]);
            foreach ($md['antardasha'] ?? [] as $ad) if ($ad['start_jd'] <= $jd && $jd < $ad['end_jd'] && $ad['lord'] === $pl) $bits[] = $this->t('rp.r.ad', ['to' => $ad['end']]);
        }
        return implode(' · ', $bits);
    }

    private function rem(string $text, ?string $pl, string $reason, int $prio, string $freq, ?string $date = null): array {
        return ['text' => $text, 'planet' => $pl, 'planet_name' => $pl ? $this->pn($pl) : null, 'reason' => $reason, 'priority' => $prio, 'freq' => $freq, 'date' => $date];
    }

    /** Frequency of a Lal Kitab remedy, read from its English wording (same index in every language). */
    private function freqOf(string $key): string {
        $en = strtolower(Lang::content('en', $key));
        return match (true) {
            (bool) preg_match('/daily|every day|morning|night|always|avoid|never|don\'t|do not|reduce|keep|wear|respect|honou?r|serve/', $en) => 'daily',
            (bool) preg_match('/sunday|monday|tuesday|wednesday|thursday|friday|saturday|week/', $en) => 'weekly',
            (bool) preg_match('/month|amavasya|purnima|new moon|full moon/', $en) => 'monthly',
            default => 'yearly',
        };
    }

    private function mantraLine(string $pl, string $when): string { return $this->t('rp.mantra', ['mantra' => self::MANTRA[$pl], 'planet' => $this->pn($pl), 'when' => $when]); }
    private function dayName(int $wd): string { return $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[$wd]); }

    /** Remedies to follow for life, grouped by how often. */
    public function lifelong(array $k, float $jd): array {
        $this->prepare($k, $jd); $g = ['daily' => [], 'weekly' => [], 'monthly' => [], 'yearly' => []];
        $top = array_slice(array_keys($this->need), 0, 4);
        foreach ($top as $i => $pl) {
            $why = $this->why($pl, $jd); $prio = (int) $this->need[$pl];
            if ($i < 2) $g['daily'][] = $this->rem($this->mantraLine($pl, $this->t('rp.every_day')), $pl, $why, $prio, 'daily');
            $g['weekly'][] = $this->rem($this->t('rp.on_day', ['day' => $this->dayName(Advanced::DAY_OF[$pl]), 'text' => $this->c("remedies.$pl.0")]), $pl, $why, $prio - 5, 'weekly');
            $h = $this->sc[$pl]['house'];
            for ($r = 0, $n = 0; $r < 4 && $n < 2; $r++) { $key = "lk.$pl.$h.rem.$r"; if (($x = $this->c($key)) !== '') { $f = $this->freqOf($key); $g[$f][] = $this->rem($x, $pl, $why, $prio - 10 - $r, $f); $n++; } }
        }
        if (isset($this->need['Moon'])) $g['monthly'][] = $this->rem($this->t('rp.purnima'), 'Moon', $this->why('Moon', $jd), (int) $this->need['Moon'], 'monthly');
        foreach (['Saturn', 'Rahu', 'Ketu'] as $pl) if (isset($this->need[$pl])) { $g['monthly'][] = $this->rem($this->t('rp.amavasya'), $pl, $this->why($pl, $jd), (int) $this->need[$pl], 'monthly'); break; }
        if ($top) $g['yearly'][] = $this->rem($this->t('rp.birthday', ['planet' => $this->pn($top[0]), 'text' => $this->c("remedies.{$top[0]}.1")]), $top[0], $this->why($top[0], $jd), (int) $this->need[$top[0]] - 15, 'yearly');
        $seen = [];
        foreach ($g as &$list) { usort($list, fn($x, $y) => $y['priority'] <=> $x['priority']);
            $list = array_slice(array_values(array_filter($this->uniq($list), function ($i) use (&$seen) { if (isset($seen[$i['text']])) return false; return $seen[$i['text']] = true; })), 0, 4); }
        unset($list);
        return ['groups' => $g, 'planets' => array_map(fn($pl) => ['planet' => $pl, 'name' => $this->pn($pl), 'score' => $this->sc[$pl]['score']], $top)];
    }

    /** Remedies for one day, from that day's transits and weekday. */
    public function day(array $k, \DateTimeImmutable $d): array {
        $jd = $this->jd($d); $this->prepare($k, $jd); $tr = (new KundaliService())->transits($k, $jd, $this->lat, $this->lon);
        return ['date' => $d->format('Y-m-d'), 'items' => $this->uniq($this->dayItems($tr, $d, $jd)) ?: [$this->rem($this->t('rp.nothing_today'), null, $this->t('rp.r.calm_day'), 0, 'daily')]];
    }

    private function dayItems(array $tr, \DateTimeImmutable $d, float $jd): array {
        $out = []; $wd = (int) $d->format('w'); $lord = Zodiac::WEEKDAY_LORDS[$wd]; $ds = $d->format('Y-m-d');
        if (isset($this->need[$lord])) $out[] = $this->rem($this->t('rp.on_day', ['day' => $this->dayName($wd), 'text' => $this->c("remedies.$lord.0")]) . ' ' . $this->mantraLine($lord, $this->t('rp.today')),
            $lord, $this->t('rp.r.weekday', ['day' => $this->dayName($wd), 'planet' => $this->pn($lord)]) . ' · ' . $this->why($lord, $jd), (int) $this->need[$lord] + 5, 'daily', $ds);
        foreach ($tr['planets'] as $t) if (in_array($t['name'], self::FAST, true) && isset($this->need[$t['name']]) && !in_array($t['house_from_moon'], self::GOCHAR_GOOD[$t['name']], true))
            $out[] = $this->rem($this->mantraLine($t['name'], $this->t('rp.today')), $t['name'], $this->t('rp.r.transit', ['planet' => $this->pn($t['name']), 'h' => $t['house_from_moon']]) . ' · ' . $this->why($t['name'], $jd), (int) $this->need[$t['name']], 'daily', $ds);
        if ($tr['derived']['chandrashtama']['active']) $out[] = $this->rem($this->t('rp.chandrashtama'), 'Moon', $this->t('rp.r.chandrashtama'), 90, 'daily', $ds);
        $ti = $tr['derived']['tara']['index'];
        if (in_array($ti, [2, 4, 6], true)) $out[] = $this->rem($this->t('rp.tara_bad'), 'Moon', $this->t('rp.r.tara', ['tara' => $this->t("astro.taras.$ti")]), 60, 'daily', $ds);
        if (($tr['derived']['sade_sati']['active'] || $tr['derived']['shani_dhaiya']['active']) && $wd === 6)
            $out[] = $this->rem($this->t('rp.shani_sat'), 'Saturn', $this->t($tr['derived']['sade_sati']['active'] ? 'rp.r.sade_sati' : 'rp.r.dhaiya'), 85, 'weekly', $ds);
        usort($out, fn($x, $y) => $y['priority'] <=> $x['priority']); return $out;
    }

    /** Week (Monday–Sunday) around the date: a dated list of what to do on which day. */
    public function week(array $k, \DateTimeImmutable $d): array {
        $s = $d->setTime(12, 0)->modify('monday this week'); $this->prepare($k, $this->jd($s->modify('+3 days'))); $items = [];
        for ($i = 0; $i < 7; $i++) { $day = $s->modify("+$i days"); $jd = $this->jd($day);
            foreach ($this->dayItems((new KundaliService())->transits($k, $jd, $this->lat, $this->lon), $day, $jd) as $it) $items[] = $it; }
        // the same remedy on several days becomes one line listing its dates
        $m = []; foreach ($items as $it) { $key = $it['text']; if (isset($m[$key])) $m[$key]['dates'][] = $it['date']; else $m[$key] = $it + ['dates' => [$it['date']]]; }
        $m = array_values($m); usort($m, fn($x, $y) => $y['priority'] <=> $x['priority']);
        return ['start' => $s->format('Y-m-d'), 'end' => $s->modify('+6 days')->format('Y-m-d'), 'items' => $m];
    }

    /** Month: full moon, new moon and Sun's sign change dates of the month, when they matter for this chart, plus the running sub-period. */
    public function month(array $k, \DateTimeImmutable $d): array {
        $s = $d->setTime(12, 0)->modify('first day of this month'); $n = (int) $s->format('t'); $this->prepare($k, $this->jd($s->modify('+14 days')));
        $ks = new KundaliService(); $rows = []; $prevSun = null; $items = [];
        for ($i = 0; $i < $n; $i++) { $day = $s->modify("+$i days"); $tr = $ks->transits($k, $this->jd($day), $this->lat, $this->lon);
            $el = fmod($tr['planets'][1]['lon'] - $tr['planets'][0]['lon'] + 360, 360); $rows[] = [$day, $el, $tr];
            if ($prevSun !== null && $tr['planets'][0]['sign'] !== $prevSun && isset($this->need['Sun']))
                $items[] = $this->rem($this->t('rp.sankranti', ['sign' => $this->t('astro.signs.' . $tr['planets'][0]['sign_name'])]), 'Sun', $this->why('Sun', $this->jd($day)), (int) $this->need['Sun'], 'monthly', $day->format('Y-m-d'));
            $prevSun = $tr['planets'][0]['sign']; }
        $near = fn(float $t) => array_reduce($rows, fn($b, $r) => $b === null || abs(fmod($r[1] - $t + 540, 360) - 180) < abs(fmod($b[1] - $t + 540, 360) - 180) ? $r : $b);
        [$fm] = $near(180); [$nm] = $near(0); $mid = $this->jd($s->modify('+14 days'));
        if (isset($this->need['Moon'])) $items[] = $this->rem($this->t('rp.purnima'), 'Moon', $this->why('Moon', $mid), (int) $this->need['Moon'], 'monthly', $fm->format('Y-m-d'));
        foreach (['Saturn', 'Rahu', 'Ketu'] as $pl) if (isset($this->need[$pl])) { $items[] = $this->rem($this->t('rp.amavasya'), $pl, $this->why($pl, $mid), (int) $this->need[$pl], 'monthly', $nm->format('Y-m-d')); break; }
        $now = $rows[14][2]['dasha_now'] ?? null;
        foreach (array_filter([$now['antardasha'] ?? null, $now['mahadasha'] ?? null]) as $pl) if (isset($this->need[$pl])) {
            $items[] = $this->rem($this->mantraLine($pl, $this->t('rp.every_day_month')), $pl, $this->why($pl, $mid), (int) $this->need[$pl] + 10, 'monthly'); break; }
        $tr = $rows[14][2];
        if ($tr['derived']['sade_sati']['active'] || $tr['derived']['shani_dhaiya']['active'])
            $items[] = $this->rem($this->t('rp.shani_sat'), 'Saturn', $this->t($tr['derived']['sade_sati']['active'] ? 'rp.r.sade_sati' : 'rp.r.dhaiya'), 85, 'weekly');
        usort($items, fn($x, $y) => $y['priority'] <=> $x['priority']);
        return ['month' => $s->format('Y-m'), 'start' => $s->format('Y-m-d'), 'end' => $s->modify('last day of this month')->format('Y-m-d'), 'items' => $this->uniq($items, true)];
    }

    /** Highest-priority remedies: lifelong plus today's, top 5, each with its planet and reason. */
    public function important(array $k, \DateTimeImmutable $d): array {
        $all = []; foreach ($this->lifelong($k, $this->jd($d))['groups'] as $list) array_push($all, ...$list);
        array_push($all, ...$this->day($k, $d)['items']);
        usort($all, fn($x, $y) => $y['priority'] <=> $x['priority']);
        // at most two per planet, so every planet that needs help is represented
        $per = []; $top = [];
        foreach ($this->uniq(array_filter($all, fn($i) => $i['planet'])) as $i) { if (($per[$i['planet']] ?? 0) >= 2) continue; $per[$i['planet']] = ($per[$i['planet']] ?? 0) + 1; $top[] = $i; if (count($top) === 5) break; }
        return ['items' => $top];
    }

    /**
     * Poojas that this chart points to. Each rule checks the whole chart, not a single trigger
     * (e.g. Mangal dosha only when Mars is not already strong), and says why, when and roughly what it costs.
     */
    public function poojas(array $k, \DateTimeImmutable $d): array {
        $jd = $this->jd($d); $this->prepare($k, $jd); $tr = (new KundaliService())->transits($k, $jd, $this->lat, $this->lon);
        $ds = []; foreach ($this->a->doshaReport($k, $tr)['items'] as $x) if ($x['present']) $ds[$x['id']] = $x;
        $sc = $this->sc; $out = [];
        $add = function (string $id, int $prio, array $factors, array $vars = []) use (&$out) {
            $f = fn(string $x) => $this->t("pooja.$id.$x", $vars);
            $out[] = ['id' => $id, 'priority' => $prio, 'name' => $f('name'), 'purpose' => $f('purpose'), 'factors' => $factors, 'why' => $f('why'),
                'timing' => $f('timing'), 'involves' => $f('involves'), 'cost' => $f('cost')];
        };
        if (isset($ds['mangal']) && $sc['Mars']['score'] < 60) $add('mangal', 80, [$this->t('rp.f.dosha', ['d' => $ds['mangal']['name']]), $this->why('Mars', $jd)]);
        if (isset($ds['kaal_sarp'])) $add('kaal_sarp', 78, [$this->t('rp.f.dosha', ['d' => $ds['kaal_sarp']['name']]), $this->why('Rahu', $jd)]);
        if (isset($ds['sade_sati']) || isset($ds['dhaiya'])) $add('shani', isset($ds['sade_sati']) ? 82 : 70, [$this->t('rp.f.dosha', ['d' => ($ds['sade_sati'] ?? $ds['dhaiya'])['name']]), $this->why('Saturn', $jd)]);
        if (isset($ds['pitra'])) $add('pitru', 72, [$this->t('rp.f.dosha', ['d' => $ds['pitra']['name']]), $this->why('Sun', $jd)]);
        if (isset($ds['grahan'])) $add('grahan', 66, [$this->t('rp.f.dosha', ['d' => $ds['grahan']['name']]), $this->why('Rahu', $jd)]);
        if (isset($ds['guru_chandal']) && $sc['Jupiter']['score'] < 60) $add('guru', 62, [$this->t('rp.f.dosha', ['d' => $ds['guru_chandal']['name']]), $this->why('Jupiter', $jd)]);
        if ((isset($ds['kemadruma']) || $sc['Moon']['score'] < 40) && !isset($ds['sade_sati'])) $add('rudra', 60, array_filter([isset($ds['kemadruma']) ? $this->t('rp.f.dosha', ['d' => $ds['kemadruma']['name']]) : null, $this->why('Moon', $jd)]));
        $now = $tr['dasha_now'] ?? [];
        foreach (array_unique(array_filter([$now['mahadasha'] ?? null, $now['antardasha'] ?? null])) as $i => $pl)
            if ($sc[$pl]['score'] < 45 && !($pl === 'Saturn' && (isset($ds['sade_sati']) || isset($ds['dhaiya']))))
                $add('graha_shanti', 75 - $i * 8, [$this->why($pl, $jd)], ['planet' => $this->pn($pl), 'jaap' => number_format(self::JAAP[$pl]), 'day' => $this->dayName(Advanced::DAY_OF[$pl]), 'deity' => $this->t("pooja.deity.$pl")]);
        usort($out, fn($x, $y) => $y['priority'] <=> $x['priority']);
        return ['items' => array_slice($out, 0, 5), 'note' => $this->t('pooja.note')];
    }

    private function jd(\DateTimeImmutable $d): float { return $d->setTime(12, 0)->getTimestamp() / 86400 + 2440587.5; }
    /** Drop repeated texts; with $dated, the same text on different dates is kept. */
    private function uniq(array $l, bool $dated = false): array { $seen = []; $o = [];
        foreach ($l as $i) { $key = $i['text'] . ($dated ? $i['date'] : ''); if (!isset($seen[$key])) { $seen[$key] = 1; $o[] = $i; } } return $o; }
}
