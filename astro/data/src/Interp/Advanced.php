<?php
namespace App\Interp;

use App\Calc\{Analysis, Zodiac};

/** Chart-wide readings. All inputs are calculated kundali/transit data; outputs are interpretation. */
final class Advanced extends RuleEngine {
    protected const DAY = ['Sun' => 0, 'Moon' => 1, 'Mars' => 2, 'Mercury' => 3, 'Jupiter' => 4, 'Venus' => 5, 'Saturn' => 6, 'Rahu' => 6, 'Ketu' => 2];
    private const GEM_COLOR = ['Sun' => '#c8102e', 'Moon' => '#eef1f4', 'Mars' => '#e0533d', 'Mercury' => '#1f9d55', 'Jupiter' => '#f2c230', 'Venus' => '#dfe9f5', 'Saturn' => '#2447a8'];
    /** Traditional incompatible stone pairs (planetary enmity). */
    private const GEM_CONFLICT = ['Sun' => ['Saturn', 'Venus'], 'Moon' => [], 'Mars' => ['Mercury'], 'Mercury' => ['Mars', 'Moon'],
        'Jupiter' => ['Venus', 'Mercury'], 'Venus' => ['Sun', 'Moon', 'Jupiter'], 'Saturn' => ['Sun', 'Moon', 'Mars']];

    private function cv(string $key, array $v = []): string { $s = $this->c($key); foreach ($v as $k => $x) $s = str_replace('{' . $k . '}', (string) $x, $s); return $this->lang === 'gu' ? \App\I18n\Lang::gu($s) : $s; }
    private function sg(array $p): string { return $this->t('astro.signs.' . $p['sign_name']); }

    // ---------------- Scores (explained, not exact facts) ----------------
    public const SCORE_RULE = 'Score = 50 + dignity (exalted +25, own +15, debilitated −25) + house (kendra/trikona +12, upachaya +6, dusthana −12; nodes: 3/6/11 +12) − combust 12 + functional role for this lagna (yogakaraka +15, auspicious +8, inauspicious −8), limited to 5–95';
    public function planetScores(array $k): array {
        $fn = $this->functional($k); $out = [];
        foreach ($k['planets'] as $p) {
            $parts = [];
            $d = ['exalted' => 25, 'own' => 15, 'debilitated' => -25][$p['dignity'] ?? ''] ?? 0; if ($d) $parts['dignity'] = $d;
            $node = in_array($p['name'], ['Rahu', 'Ketu'], true);
            $hk = $node ? (in_array($p['house'], [3, 6, 11], true) ? 12 : (in_array($p['house'], [6, 8, 12], true) ? -12 : 0))
                        : ['kendra' => 12, 'trikona' => 12, 'upachaya' => 6, 'neutral' => 0, 'dusthana' => -12][$this->kind($p['house'])];
            if ($hk) $parts['house'] = $hk;
            if (!empty($p['combust'])) $parts['combust'] = -12;
            if (isset($fn[$p['name']])) { $f = ['yoga' => 15, 'good' => 8, 'mixed' => 0, 'bad' => -8][$fn[$p['name']]['class']]; if ($f) $parts['functional'] = $f; }
            $s = max(5, min(95, 50 + array_sum($parts)));
            $out[$p['name']] = ['score' => $s, 'parts' => $parts, 'class' => $fn[$p['name']]['class'] ?? null, 'house' => $p['house'], 'sign' => $p['sign_name'], 'dignity' => $p['dignity']];
        }
        return $out;
    }

    public function summary(array $k): array {
        $sc = $this->planetScores($k); $vals = array_column($sc, 'score');
        $pos = 0; $neg = 0; foreach ($sc as $s) foreach ($s['parts'] as $v) $v > 0 ? $pos += $v : $neg -= $v;
        $sorted = $sc; uasort($sorted, fn($a, $b) => $b['score'] <=> $a['score']); $names = array_keys($sorted);
        $sup = array_values(array_filter($names, fn($n) => $sc[$n]['score'] >= 60));
        $chal = array_values(array_reverse(array_filter($names, fn($n) => $sc[$n]['score'] <= 40)));
        return ['meta' => ['type' => 'interpretation', 'kind' => 'summary', 'ruleset' => self::VERSION],
            'overall' => (int) round(array_sum($vals) / count($vals)), 'planets' => $sc,
            'positive_pct' => $pos + $neg ? (int) round($pos / ($pos + $neg) * 100) : 50, 'challenging_pct' => $pos + $neg ? (int) round($neg / ($pos + $neg) * 100) : 50,
            'strongest' => $names[0], 'weakest' => end($names), 'supportive' => array_slice($sup, 0, 3), 'challenging' => array_slice($chal, 0, 3),
            'method' => ['planet' => $this->c('method.planet'), 'overall' => $this->c('method.overall'), 'percent' => $this->c('method.percent')]];
    }

    // ---------------- Doshas: every check listed, present only when the rule is met ----------------
    public function doshaReport(array $k, array $tr): array {
        $by = $this->by($k); $a = $k['analysis']; $same = fn($x, $y) => $by[$x]['sign'] === $by[$y]['sign'];
        $moon = $by['Moon']['sign']; $near = fn($off) => array_filter($k['planets'], fn($p) => !in_array($p['name'], ['Sun', 'Moon', 'Rahu', 'Ketu'], true) && $p['sign'] === ($moon + $off + 12) % 12);
        $kemaCancel = array_filter($k['planets'], fn($p) => !in_array($p['name'], ['Moon', 'Rahu', 'Ketu'], true) && in_array(($p['sign'] - $moon + 12) % 12, [0, 3, 6, 9], true));
        $m = $a['mangal_dosha']; $ks = $a['kaal_sarp']; $ss = $tr['derived']['sade_sati']; $dh = $tr['derived']['shani_dhaiya'];
        $checks = [
            'mangal' => [$m['present'], $m['rule'], ['mars_from_lagna' => $m['from']['lagna']['house'], 'mars_from_moon' => $m['from']['moon']['house'], 'mars_sign' => $m['mars_sign']]],
            'kaal_sarp' => [$ks['present'], $ks['rule'], ['rahu_house' => $ks['rahu_house'], 'type' => $ks['type']]],
            'sade_sati' => [$ss['active'], $ss['rule'], ['saturn_from_moon' => $tr['planets'][6]['house_from_moon'], 'phase' => $ss['phase']]],
            'dhaiya' => [$dh['active'], $dh['rule'], ['saturn_from_moon' => $dh['house_from_moon']]],
            'grahan' => [$same('Sun', 'Rahu') || $same('Sun', 'Ketu') || $same('Moon', 'Rahu') || $same('Moon', 'Ketu'), 'Sun or Moon in the same sign as Rahu or Ketu',
                         ['sun_sign' => $by['Sun']['sign_name'], 'moon_sign' => $by['Moon']['sign_name'], 'rahu_sign' => $by['Rahu']['sign_name'], 'ketu_sign' => $by['Ketu']['sign_name']]],
            'guru_chandal' => [$same('Jupiter', 'Rahu'), 'Jupiter in the same sign as Rahu', ['jupiter_sign' => $by['Jupiter']['sign_name'], 'rahu_sign' => $by['Rahu']['sign_name']]],
            'kemadruma' => [!$near(1) && !$near(-1) && !$kemaCancel, 'No planet (except Sun, Rahu, Ketu) in 2nd or 12th from Moon, and no planet in a kendra from Moon (cancellation)',
                            ['planets_2nd_from_moon' => count($near(1)), 'planets_12th_from_moon' => count($near(-1)), 'kendra_from_moon' => count($kemaCancel)]],
            'pitra' => [$same('Sun', 'Rahu') || $same('Sun', 'Ketu') || $by['Rahu']['house'] === 9, 'Sun with Rahu/Ketu in one sign, or Rahu in the 9th house',
                        ['sun_sign' => $by['Sun']['sign_name'], 'rahu_house' => $by['Rahu']['house']]],
        ];
        $items = [];
        foreach ($checks as $id => [$present, $rule, $facts]) {
            $f = []; foreach ($facts as $kk => $v) $f[] = $this->fact($kk, $v);
            $items[] = ['id' => $id, 'name' => $this->c("dosha.$id.name"), 'present' => (bool) $present, 'rule' => $this->lang === 'en' ? $rule : ($this->c("dosha_rule.$id") ?: $rule), 'factors' => $f,
                'effects' => $present ? $this->cv("dosha.$id.effects", ['type' => (string) ($ks['type'] ?? ''), 'phase' => $ss['phase'] ? $this->t('interp.sade_phase.' . $ss['phase']) : '']) : null,
                'remedy' => $present ? $this->c("dosha.$id.remedy") : null,
                'period' => $id === 'sade_sati' ? $tr['sade_sati_cycle'] : null];
        }
        return ['meta' => ['type' => 'interpretation', 'kind' => 'doshas', 'ruleset' => self::VERSION], 'rin' => $this->rin($k), 'checked' => count($items),
                'present' => count(array_filter($items, fn($i) => $i['present'])), 'items' => $items];
    }

    // ---------------- Priority remedies ----------------
    public function priorityRemedies(array $k, array $tr): array {
        $by = $this->by($k); $sc = $this->planetScores($k); $lagnaLord = Analysis::houseLord($k['lagna']['sign'], 1); $c = [];
        $add = function (string $type, int $weight, ?string $pl, array $facts) use (&$c) { $c[$type . $pl] = [$type, $weight, $pl, $facts]; };
        if ($sc[$lagnaLord]['score'] < 55) $add('lagna_lord', 90, $lagnaLord, [$this->fact('lagna.lord', $lagnaLord), $this->fact("score.$lagnaLord", $sc[$lagnaLord]['score'])]);
        $now = $tr['dasha_now'];
        foreach (array_filter([$now['mahadasha'] ?? null, $now['antardasha'] ?? null]) as $i => $pl)
            if ($sc[$pl]['score'] < 50) $add('dasha_lord', 85 - $i * 10, $pl, [$this->fact($i ? 'dasha.antardasha' : 'dasha.mahadasha', $pl), $this->fact("score.$pl", $sc[$pl]['score'])]);
        foreach ($sc as $pl => $s) if (in_array($s['class'], ['good', 'yoga'], true) && ($s['dignity'] === 'debilitated' || isset($s['parts']['combust']) || ($s['parts']['house'] ?? 0) < 0))
            $add('weak_benefic', 70, $pl, [$this->fact("planets.$pl.dignity", $s['dignity']), $this->fact("planets.$pl.house", $s['house']), $this->fact("functional.$pl", $s['class'])]);
        if ($sc['Moon']['score'] < 45) $add('weak_moon', 65, 'Moon', [$this->fact('score.Moon', $sc['Moon']['score']), $this->fact('planets.Moon.house', $by['Moon']['house'])]);
        foreach ($this->doshaReport($k, $tr)['items'] as $d) if ($d['present'])
            $add('dosha', ['sade_sati' => 80, 'mangal' => 75, 'kaal_sarp' => 72, 'dhaiya' => 68, 'pitra' => 60, 'grahan' => 58, 'guru_chandal' => 55, 'kemadruma' => 55][$d['id']], $d['id'], $d['factors']);
        uasort($c, fn($a, $b) => $b[1] <=> $a[1]); $items = []; $n = 0;
        foreach (array_slice($c, 0, 7) as [$type, $w, $pl, $facts]) {
            $n++;
            if ($type === 'dosha') { $name = $this->c("dosha.$pl.name"); $db = RemedyRules::forDosha($pl, $this->lang);
                $items[] = ['id' => "pr_$pl", 'rank' => $n, 'title' => $name, 'issue' => $this->c("dosha.$pl.effects_short"), 'how' => $db['text'] ?? $this->c("dosha.$pl.remedy"),

                    'benefit' => $this->c("dosha.$pl.benefit"), 'derivation' => ['rule' => 'Dosha present in calculated chart; priority weight ' . $w, 'ruleset' => self::VERSION, 'from_calculated' => $facts]];
                continue; }
            $vars = ['planet' => $this->pn($pl), 'mantra' => self::MANTRA[$pl], 'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$pl]])];
            $cond = ($sc[$pl]['class'] ?? '') === 'bad' ? 'malefic' : ($sc[$pl]['score'] < 45 ? 'weak' : 'benefic');
            $db = RemedyRules::forPlanet($pl, (int) $sc[$pl]['house'], $cond, $this->lang);
            $lkr = $this->lk($k, $pl)['remedies'];
            $how = $db['text'] ?? ($this->cv('prem.how', $vars) . ' ' . implode(' ', $lkr ?: array_map(fn($i) => $this->c("remedies.$pl.$i"), [0, 1, 2])));
            $items[] = ['id' => "pr_$type$pl", 'rank' => $n, 'title' => $this->cv("prem.$type.title", $vars), 'issue' => $this->cv("prem.$type.issue", $vars), 'how' => $how,

                'benefit' => $this->cv("prem.$type.benefit", $vars), 'derivation' => ['rule' => "Priority weight $w; " . self::SCORE_RULE, 'ruleset' => self::VERSION, 'from_calculated' => $facts]];
        }
        return ['meta' => ['type' => 'interpretation', 'kind' => 'priority_remedies', 'ruleset' => self::VERSION], 'items' => $items];
    }

    // ---------------- Gemstone report with whole-chart suitability ----------------
    public function gemReport(array $k): array {
        $sc = $this->planetScores($k); $fn = $this->functional($k); $by = $this->by($k); $lagnaLord = Analysis::houseLord($k['lagna']['sign'], 1);
        $cand = array_values(array_unique(array_merge([$lagnaLord], array_keys(array_filter($fn, fn($f) => in_array($f['class'], ['yoga', 'good'], true))))));
        $rec = []; $caution = [];
        foreach ($cand as $pl) {
            $p = $by[$pl]; $issues = [];
            if ($p['dignity'] === 'debilitated') $issues[] = $this->t('astro.dignity.debilitated');
            if (!empty($p['combust'])) $issues[] = $this->t('ui.combust');
            if (in_array($p['house'], [6, 8, 12], true)) $issues[] = $this->t('ui.house') . ' ' . $p['house'];
            $conf = array_values(array_intersect(self::GEM_CONFLICT[$pl], array_column($rec, 'planet')));
            $f = [$this->fact('lagna.sign', $k['lagna']['sign_name']), $this->fact("lordships.$pl", $fn[$pl]['houses']), $this->fact("functional.$pl", $fn[$pl]['class'])] + [];
            $f = array_merge($f, $this->pfacts($p));
            $row = ['planet' => $pl, 'gem' => $this->t("astro.gems.$pl"), 'color' => self::GEM_COLOR[$pl], 'score' => $sc[$pl]['score'],
                'why' => $this->cv($pl === $lagnaLord ? 'gem.why_lagna' : 'gem.why_benefic', ['planet' => $this->pn($pl), 'houses' => implode(', ', $fn[$pl]['houses']), 'sign' => $this->sg($p), 'h' => $p['house']]),
                'helps' => $this->c("gem.helps.$pl"), 'when' => $this->cv('gem.when', ['day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$pl]])]),
                'process' => ['metal' => $this->t('astro.metals.' . self::GEM[$pl][1]), 'finger' => $this->t('astro.fingers.' . self::GEM[$pl][0]),
                    'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$pl]]), 'weight' => $this->c("gem.weight.$pl"),
                    'preparation' => $this->cv('gem.prep', ['mantra' => self::MANTRA[$pl]])],
                'derivation' => ['rule' => 'Lagna lord or functional benefic; checked for debilitation, combustion, dusthana and conflicts with other recommended stones', 'ruleset' => self::VERSION, 'from_calculated' => $f]];
            if ($issues || $conf) { $row['caution'] = $this->cv('gem.caution', ['why' => implode(', ', array_merge($issues, array_map(fn($x) => $this->t("astro.gems.$x"), $conf)))]); $caution[] = $row; }
            else $rec[] = $row;
        }
        $avoid = [];
        foreach ($fn as $pl => $f) if ($f['class'] === 'bad' && $pl !== $lagnaLord) $avoid[] = ['planet' => $pl, 'gem' => $this->t("astro.gems.$pl"), 'color' => self::GEM_COLOR[$pl],
            'why' => $this->cv('gem.avoid', ['planet' => $this->pn($pl), 'houses' => implode(', ', $f['houses'])])];
        return ['meta' => ['type' => 'interpretation', 'kind' => 'gem_report', 'ruleset' => self::VERSION], 'recommended' => $rec, 'caution' => $caution, 'avoid' => $avoid,
                'note' => $this->c('gem.note')];
    }

    // ---------------- Topic readings (annual chart / daily transits) ----------------
    private const TOPICS = ['career' => [10, ['Sun', 'Saturn']], 'business' => [7, ['Mercury']], 'finance' => [2, ['Jupiter', 'Venus']],
                            'relationships' => [7, ['Venus', 'Moon']], 'health' => [1, ['Sun', 'Mars']], 'education' => [5, ['Mercury', 'Jupiter']]];

    public function annual(array $natal, array $v): array {
        $by = []; foreach ($v['planets'] as $p) $by[$p['name']] = $p; $L = $v['lagna']['sign']; $items = [];
        foreach (self::TOPICS as $topic => [$h, $karakas]) {
            $lord = Analysis::houseLord($L, $h); $lp = $by[$lord];
            [$verdict, $dig, $s] = $this->strength($lp);
            $occ = array_values(array_filter($v['planets'], fn($p) => $p['house'] === $h));
            $ben = count(array_filter($occ, fn($p) => in_array($p['name'], Analysis::NATURAL_BENEFICS, true))); $mal = count($occ) - $ben;
            $s += $ben * .5 - $mal * .5; if ($v['muntha']['house'] === $h) $s += .5;
            $verdict = $s >= 1 ? 'strong' : ($s <= -1 ? 'weak' : 'mixed');
            $items[] = ['id' => "yr_$topic", 'topic' => $topic, 'title' => $this->c("topic.$topic"), 'verdict' => $verdict,
                'prediction' => $this->c("annual.$topic.$verdict"),
                'reason' => $this->cv('annual.reason', ['h' => $h, 'lord' => $this->pn($lord), 'm' => $lp['house'], 'sign' => $this->sg($lp), 'dig' => $dig,
                    'occ' => $occ ? implode(', ', array_map(fn($p) => $this->pn($p['name']), $occ)) : '—']),
                'description' => $this->cv('annual.desc', ['lord' => $this->pn($lord), 'm' => $lp['house']]) . ' ' . $this->c("planet_house.$lord." . ($lp['house'] - 1)),
                'guidance' => $this->c("guide.$verdict") . ' ' . $this->c("remedies.$lord.0"),
                'derivation' => ['rule' => 'Annual chart: house lord placement + dignity + benefic/malefic occupants + Muntha', 'ruleset' => self::VERSION,
                    'from_calculated' => [$this->fact("varsha.house_$h.lord", $lord), $this->fact("varsha.$lord.house", $lp['house']), $this->fact("varsha.$lord.sign", $lp['sign_name']),
                        $this->fact("varsha.house_$h.occupants", array_column($occ, 'name')), $this->fact('varsha.muntha_house', $v['muntha']['house'])]]];
        }
        return ['meta' => ['type' => 'interpretation', 'kind' => 'annual', 'ruleset' => self::VERSION], 'muntha' => $this->cv('annual.muntha', ['h' => $v['muntha']['house'],
            'sign' => $this->t('astro.signs.' . $v['muntha']['sign_name'])]) . ' ' . $this->c('muntha_house.' . ($v['muntha']['house'] - 1)), 'items' => $items];
    }


    private const NUM = ['Sun' => 1, 'Moon' => 2, 'Jupiter' => 3, 'Rahu' => 4, 'Mercury' => 5, 'Venus' => 6, 'Ketu' => 7, 'Saturn' => 8, 'Mars' => 9];
    private function dates(int $n): array { $o = []; for ($d = 1; $d <= 31; $d++) if (($d - 1) % 9 + 1 === $n) $o[] = $d; return $o; }

    /** Lucky/unlucky factors, key years and career direction — each with the planet it comes from. */
    public function luck(array $k): array {
        $sc = $this->planetScores($k); $fn = $this->functional($k); $by = $this->by($k); $L = $k['lagna']['sign'];
        $ll = Analysis::houseLord($L, 1); $l9 = Analysis::houseLord($L, 9);
        $gems = $this->gemReport($k); $ratna = $gems['recommended'][0] ?? null;
        foreach ($gems['recommended'] as $g) if ($g['planet'] === $l9) $ratna = $g;
        $bad = array_keys(array_filter($fn, fn($f) => $f['class'] === 'bad')); usort($bad, fn($a, $b) => $sc[$a]['score'] <=> $sc[$b]['score']);
        $worst = $bad[0] ?? array_key_last(array_filter($sc, fn($x) => true));
        $periods = [];
        foreach ($k['dasha']['mahadasha'] as $md) foreach ($md['antardasha'] as $ad) {
            $periods[] = ['md' => $md['lord'], 'ad' => $ad['lord'], 'from' => substr($ad['start'], 0, 4), 'to' => substr($ad['end'], 0, 4), 'start' => $ad['start'], 'end' => $ad['end'],
                          'score' => (int) round(($sc[$md['lord']]['score'] * 2 + $sc[$ad['lord']]['score']) / 3)]; }
        $byScore = $periods; usort($byScore, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = array_values(array_filter(array_slice($byScore, 0, 6), fn($p) => $p['score'] >= 55));
        $worstP = array_values(array_filter(array_slice(array_reverse($byScore), 0, 6), fn($p) => $p['score'] <= 48));
        $lim = (int) substr($k['birth']['local'], 0, 4) + 85;
        $merge = function (array $arr) use ($lim) { $arr = array_filter($arr, fn($p) => (int) $p['from'] <= $lim); usort($arr, fn($a, $b) => strcmp($a['start'], $b['start'])); $o = [];
            foreach ($arr as $p) { $n = count($o); if ($n && $o[$n - 1]['end'] === $p['start']) { $o[$n - 1]['end'] = $p['end']; $o[$n - 1]['to'] = $p['to']; $o[$n - 1]['ad'] .= ', ' . $p['ad']; } else $o[] = $p; }
            return $o; };
        $best = $merge(array_filter($byScore, fn($p) => $p['score'] >= 60)); $worstP = $merge(array_filter($byScore, fn($p) => $p['score'] <= 45));
        $best = array_slice($best, 0, 6); $worstP = array_slice($worstP, 0, 6);
        $l10 = Analysis::houseLord($L, 10); $l6 = Analysis::houseLord($L, 6); $l7 = Analysis::houseLord($L, 7);
        $in10 = array_column(array_filter($k['planets'], fn($p) => $p['house'] === 10), 'name');
        $fieldPl = array_values(array_unique(array_merge([$l10], $in10)));
        $govBonus = (in_array($l10, ['Sun', 'Jupiter', 'Saturn'], true) || array_intersect($in10, ['Sun', 'Jupiter', 'Saturn'])) ? 15 : 0;
        $career = ['job' => (int) round(($sc[$l10]['score'] + $sc[$l6]['score']) / 2), 'business' => (int) round(($sc[$l7]['score'] + $sc['Mercury']['score']) / 2),
                   'govt' => min(95, (int) round(($sc['Sun']['score'] + $sc['Jupiter']['score']) / 2) + $govBonus)];
        arsort($career);
        $pv = fn(string $pl) => ['planet' => $pl, 'name' => $this->pn($pl)];
        return ['meta' => ['type' => 'interpretation', 'kind' => 'luck', 'ruleset' => self::VERSION],
            'rashi' => $by['Moon']['sign_name'], 'lagna' => $k['lagna']['sign_name'], 'nakshatra' => $by['Moon']['nakshatra'],
            'aradhya' => ['text' => $this->c("deity.$ll"), 'from' => $pv($ll), 'also' => $l9 !== $ll ? $this->c("deity.$l9") : null],
            'bhagya_ratna' => $ratna ? ['gem' => $ratna['gem'], 'color' => $ratna['color'], 'from' => $pv($ratna['planet'])] : null,
            'lucky' => ['color' => implode(', ', array_unique(array_merge(explode(', ', $this->c("color.$ll")), $l9 !== $ll ? explode(', ', $this->c("color.$l9")) : []))), 'number' => array_values(array_unique([self::NUM[$ll], self::NUM[$l9]])),
                        'day' => array_values(array_unique([$this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$ll]]), $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$l9]])])),
                        'dates' => array_values(array_unique(array_merge($this->dates(self::NUM[$ll]), $this->dates(self::NUM[$l9])))), 'from' => [$pv($ll), $pv($l9)]],
            'unlucky' => ['planets' => array_map($pv, array_slice($bad, 0, 2)), 'color' => $this->c("color.$worst"), 'number' => self::NUM[$worst], 'from' => $pv($worst)],
            'best_years' => $best, 'negative_years' => $worstP,
            'career' => ['ranking' => $career, 'best' => array_key_first($career), 'fields' => array_map(fn($pl) => ['planet' => $pl, 'name' => $this->pn($pl), 'fields' => $this->c("field.$pl")], $fieldPl),
                         'method' => $this->c('career.method'), 'l10' => $l10],
            'method' => ['lucky' => 'lagna lord + 9th lord', 'years' => 'Antardasha periods ranked by (2×Mahadasha lord score + Antardasha lord score) ÷ 3']];
    }

    public function yogas(array $k): array {
        $by = $this->by($k); $L = $k['lagna']['sign']; $moon = $by['Moon']['sign']; $sc = $this->planetScores($k);
        $lord = fn($h) => Analysis::houseLord($L, $h); $same = fn($a, $b) => $by[$a]['sign'] === $by[$b]['sign'];
        $fromMoon = fn($pl) => ($by[$pl]['sign'] - $moon + 12) % 12 + 1; $kendra = [1, 4, 7, 10]; $trik = [1, 5, 9];
        $strong = fn($pl) => in_array($by[$pl]['dignity'], ['own', 'exalted'], true);
        $pairs = function (array $ha, array $hb) use ($lord, $same, $by) { $o = [];
            foreach ($ha as $a) foreach ($hb as $b) { $x = $lord($a); $y = $lord($b); if ($x === $y) continue;
                $exch = Zodiac::SIGN_LORDS[$by[$x]['sign']] === $y && Zodiac::SIGN_LORDS[$by[$y]['sign']] === $x;
                if ($same($x, $y) || $exch) $o[] = "$x+$y"; }
            return array_values(array_unique($o)); };
        $raj = $pairs([1, 4, 7, 10], [5, 9]); $dhana = $pairs([2, 11], [5, 9]);
        $vip = []; foreach ([6, 8, 12] as $h) if (in_array($by[$lord($h)]['house'], [6, 8, 12], true)) $vip[] = $lord($h) . '→' . $by[$lord($h)]['house'];
        $nb = []; foreach ($k['planets'] as $p) if ($p['dignity'] === 'debilitated') { $d = Zodiac::SIGN_LORDS[$p['sign']];
            if (in_array($by[$d]['house'], $kendra, true) || in_array((($by[$d]['sign'] - $moon + 12) % 12) + 1, $kendra, true)) $nb[] = $p['name']; }
        $adhi = array_filter(['Jupiter', 'Venus', 'Mercury'], fn($pl) => in_array($fromMoon($pl), [6, 7, 8], true));
        $in10 = array_filter($k['planets'], fn($p) => $p['house'] === 10 && in_array($p['name'], ['Jupiter', 'Venus', 'Mercury'], true));
        $good = [1, 2, 4, 5, 7, 9, 10]; $l9 = $lord(9);
        $mp = fn($pl) => $strong($pl) && in_array($by[$pl]['house'], $kendra, true);
        $checks = [
            'gajakesari' => [in_array($fromMoon('Jupiter'), $kendra, true), ['jupiter_from_moon' => $fromMoon('Jupiter')]],
            'budhaditya' => [$same('Sun', 'Mercury'), ['sun_sign' => $by['Sun']['sign_name'], 'mercury_sign' => $by['Mercury']['sign_name']]],
            'ruchaka' => [$mp('Mars'), ['Mars.house' => $by['Mars']['house'], 'Mars.dignity' => $by['Mars']['dignity']]],
            'bhadra' => [$mp('Mercury'), ['Mercury.house' => $by['Mercury']['house'], 'Mercury.dignity' => $by['Mercury']['dignity']]],
            'hamsa' => [$mp('Jupiter'), ['Jupiter.house' => $by['Jupiter']['house'], 'Jupiter.dignity' => $by['Jupiter']['dignity']]],
            'malavya' => [$mp('Venus'), ['Venus.house' => $by['Venus']['house'], 'Venus.dignity' => $by['Venus']['dignity']]],
            'shasha' => [$mp('Saturn'), ['Saturn.house' => $by['Saturn']['house'], 'Saturn.dignity' => $by['Saturn']['dignity']]],
            'chandra_mangal' => [$same('Moon', 'Mars'), ['moon_sign' => $by['Moon']['sign_name'], 'mars_sign' => $by['Mars']['sign_name']]],
            'raj' => [(bool) $raj, ['planets' => $raj]], 'dhana' => [(bool) $dhana, ['planets' => $dhana]], 'vipreet' => [(bool) $vip, ['planets' => $vip]],
            'neech_bhanga' => [(bool) $nb, ['planets' => $nb]], 'adhi' => [count($adhi) >= 2, ['planets' => array_values($adhi)]],
            'lakshmi' => [$strong($l9) && in_array($by[$l9]['house'], [1, 4, 5, 7, 9, 10], true) && $sc[$lord(1)]['score'] >= 55, ['house_9.lord' => $l9, "$l9.house" => $by[$l9]['house'], "$l9.dignity" => $by[$l9]['dignity']]],
            'saraswati' => [count(array_filter(['Jupiter', 'Venus', 'Mercury'], fn($pl) => in_array($by[$pl]['house'], $good, true))) === 3 && $by['Jupiter']['dignity'] !== 'debilitated',
                            ['Jupiter.house' => $by['Jupiter']['house'], 'Venus.house' => $by['Venus']['house'], 'Mercury.house' => $by['Mercury']['house']]],
            'amala' => [(bool) $in10, ['planets' => array_values(array_column($in10, 'name'))]],
        ];
        $items = [];
        foreach ($checks as $id => [$ok, $facts]) { $f = []; foreach ($facts as $kk => $v) $f[] = $this->fact($kk, $v);
            $items[] = ['id' => $id, 'name' => $this->c("yoga.$id.name"), 'present' => $ok, 'rule' => $this->c("yoga.$id.rule"), 'effect' => $this->c("yoga.$id.effect"), 'factors' => $f]; }
        return ['meta' => ['type' => 'interpretation', 'kind' => 'yogas', 'ruleset' => self::VERSION], 'checked' => count($items),
                'present' => count(array_filter($items, fn($i) => $i['present'])), 'items' => $items];
    }

    private const MONTHLY = ['career' => ['Sun', 'Saturn', 'Jupiter', 'Mercury'], 'finance' => ['Jupiter', 'Venus', 'Mercury', 'Saturn'], 'relationships' => ['Venus', 'Jupiter', 'Mars'],
                             'health' => ['Sun', 'Mars', 'Saturn'], 'mind' => ['Saturn', 'Rahu', 'Jupiter'], 'education' => ['Mercury', 'Jupiter', 'Sun']];
    /** Topic reading for a period from transits (daily or monthly). Description is composed of every contributing transit. */
    private function topics(array $k, array $tr, array $map, string $txt, bool $daily): array {
        $t = []; foreach ($tr['planets'] as $p) $t[$p['name']] = $p; $items = []; $total = 0; $by = $this->by($k);
        $ti = $tr['derived']['tara']['index']; $tara = $ti === 0 ? 0 : (in_array($ti, [2, 4, 6], true) ? -1 : 1);
        $now = $tr['dasha_now'];
        foreach ($map as $topic => $pls) {
            $s = 0; $why = []; $lines = []; $pts = [];
            foreach ($pls as $pl) { $g = in_array($t[$pl]['house_from_moon'], self::GOCHAR_GOOD[$pl], true); $s += $g ? 1 : -1;
                $hl = $t[$pl]['house_from_lagna']; $lg = !in_array($hl, self::LK_BAD[$pl], true);
                $pts[] = ['planet' => $pl, 'house' => $t[$pl]['house_from_moon'], 'good' => $g, 'domain' => $this->t("interp.planet_domain.$pl"),
                          'lk' => $this->c("lk.$pl.$hl." . ($lg ? 'good' : 'bad')), 'lk_house' => $hl];
                $why[] = $this->pn($pl) . ' ' . $t[$pl]['house_from_moon'] . ($g ? ' ✓' : ' ✗');
                $lines[] = $this->t($g ? 'interp.gochar_good' : 'interp.gochar_bad', ['planet' => $this->pn($pl), 'h' => $t[$pl]['house_from_moon'], 'domain' => $this->t("interp.planet_domain.$pl")]); }
            $notes = [];
            if ($daily && in_array($topic, ['mind', 'relationships'], true)) { $s += $tara; $lines[] = $notes[] = $this->t('interp.tara.' . ($ti === 0 ? 'mixed' : ($tara < 0 ? 'bad' : 'good')), ['tara' => $this->t("astro.taras.$ti")]); }
            if ($daily && $topic === 'mind' && $tr['derived']['chandrashtama']['active']) { $s -= 1; $lines[] = $notes[] = $this->t('interp.chandrashtama'); }
            if (!$daily && $now) $lines[] = $notes[] = $this->t('interp.dasha_theme.' . $now['mahadasha'], ['planet' => $this->pn($now['mahadasha'])]);
            $pct = (int) round(50 + 50 * max(-1, min(1, $s / max(1, count($pls))))); $total += $pct;
            $verdict = $pct >= 60 ? 'strong' : ($pct <= 40 ? 'weak' : 'mixed');
            $items[] = ['id' => "$txt$topic", 'topic' => $topic, 'title' => $this->c("topic.$topic"), 'verdict' => $verdict, 'score' => $pct,
                'prediction' => $this->c("$txt.$topic.$verdict"), 'description' => implode(' ', $lines), 'points' => $pts, 'notes' => $notes, 'reason' => $this->cv('daily.reason', ['list' => implode(', ', $why)]),
                'guidance' => $this->c("dguide.$topic.$verdict"),
                'remedy' => ($bp = array_values(array_filter($pts, fn($x) => !$x['good']))) ? $this->c("lk.{$bp[0]['planet']}." . $by[$bp[0]['planet']]['house'] . '.rem.0') : null,
                'derivation' => ['rule' => 'Transits from natal Moon (✓ favourable, ✗ unfavourable). Score = 50 ± 50 × net/planets', 'ruleset' => self::VERSION,
                    'from_calculated' => array_map(fn($pl) => $this->fact("transit.$pl.house_from_moon", $t[$pl]['house_from_moon']), $pls)]];
        }
        return ['meta' => ['type' => 'interpretation', 'kind' => $daily ? 'daily' : 'monthly', 'ruleset' => self::VERSION], 'as_on_utc' => $tr['meta']['utc'],
                'overall' => (int) round($total / count($items)), 'tara' => $this->t("astro.taras.$ti"), 'moon_sign' => $t['Moon']['sign_name'],
                'moon_nakshatra' => $t['Moon']['nakshatra'], 'items' => $items];
    }
    public function monthly(array $k, array $tr): array { return $this->topics($k, $tr, self::MONTHLY, 'monthly', false); }
    private const DAILY = ['career' => ['Sun', 'Saturn', 'Mercury'], 'finance' => ['Jupiter', 'Venus', 'Mercury'], 'relationships' => ['Venus', 'Moon'],
                           'health' => ['Sun', 'Mars', 'Moon'], 'mind' => ['Moon'], 'education' => ['Mercury', 'Jupiter']];
    public function daily(array $k, array $tr): array { return $this->topics($k, $tr, self::DAILY, 'daily', true); }

    /** Karmic debts (Rin): planet groups in specific houses. */
    private const RIN = ['pitru' => [['Venus', 'Mercury', 'Rahu'], [2, 5, 9, 12]], 'sva' => [['Venus', 'Rahu', 'Ketu'], [5]], 'matri' => [['Ketu'], [4]],
        'stri' => [['Sun', 'Rahu', 'Moon'], [2, 7]], 'bandhu' => [['Mercury', 'Ketu'], [1, 8]], 'behen' => [['Moon'], [3, 6]],
        'nirdayi' => [['Sun', 'Moon', 'Mars'], [10, 11]], 'ajanma' => [['Sun', 'Venus', 'Mars'], [12]], 'daivik' => [['Moon', 'Mars'], [6]]];
    public function rin(array $k): array {
        $by = $this->by($k); $items = [];
        // a debt is active only when houses 2, 5, 9 or 12 are afflicted (a planet unfavourably placed there)
        $afflicted = (bool) array_filter($k['planets'], fn($p) => in_array($p['house'], [2, 5, 9, 12], true) && in_array($p['house'], self::LK_BAD[$p['name']], true));
        foreach (self::RIN as $id => [$pls, $hs]) {
            $hit = $afflicted ? array_values(array_filter($pls, fn($pl) => in_array($by[$pl]['house'], $hs, true))) : [];
            $items[] = ['id' => $id, 'name' => $this->c("rin.$id.name"), 'present' => (bool) $hit, 'rule' => $this->c("rin.$id.rule"),
                'effect' => $hit ? $this->c("rin.$id.effect") : null, 'remedy' => $hit ? $this->c("rin.$id.remedy") : null,
                'factors' => array_map(fn($pl) => $this->fact("planets.$pl.house", $by[$pl]['house']), $hit)];
        }
        return ['checked' => count($items), 'present' => count(array_filter($items, fn($i) => $i['present'])), 'items' => $items];
    }

    /** House-shift annual chart: each natal house moves to a new house by running year of age. */
    public function lkVarsh(array $k, int $year): array {
        static $M = null; $M ??= require __DIR__ . '/../../data/lk_matrix.php';
        $age = $year - (int) substr($k['birth']['local'], 0, 4) + 1;
        if (!isset($M[$age])) throw new \App\Core\ApiException('validation', 'Supported age: 1–87', 422);
        $rows = []; $D1 = ['lagna' => 0, 'planets' => [], 'dignity' => []];
        foreach ($k['planets'] as $p) {
            $h = $M[$age][$p['house'] - 1]; $good = !in_array($h, self::LK_BAD[$p['name']], true);
            $rem = []; for ($i = 0; $i < 4; $i++) { $x = $this->c("lk.{$p['name']}.$h.rem.$i"); if ($x !== '') $rem[] = $x; }
            $rows[] = ['name' => $p['name'], 'natal_house' => $p['house'], 'house' => $h, 'benefic' => $good, 'retrograde' => $p['retrograde'], 'combust' => $p['combust'] ?? false,
                       'sign_name' => Zodiac::SIGNS[$h - 1], 'effect' => $this->c("lk.{$p['name']}.$h." . ($good ? 'good' : 'bad')), 'remedies' => $rem];
            $D1['planets'][$p['name']] = $h - 1;
        }
        $g = count(array_filter($rows, fn($r) => $r['benefic']));
        return ['meta' => ['type' => 'interpretation', 'kind' => 'house_varsh', 'ruleset' => self::VERSION], 'year' => $year, 'age' => $age,
                'score' => (int) round($g / 9 * 100), 'planets' => $rows, 'vargas' => ['D1' => $D1]];
    }

}
