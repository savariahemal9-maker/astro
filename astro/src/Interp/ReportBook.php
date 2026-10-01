<?php
namespace App\Interp;

use App\Calc\KundaliService;
use App\Calc\PanchangService;
use App\Calc\Zodiac;
use App\I18n\Lang;

/**
 * Long-form printable kundali book. Combines the computed chart and the existing
 * interpretation engines with the knowledge base in lang/kb_{lang}.php, and returns
 * chapters of blocks that the website renders as A4 pages:
 *   ['p', text] ['h', text] ['facts', [[label, value], …]] ['list', [text, …]]
 *   ['table', [head…], [[cell…], …]] ['chart', 'D1'|'D9'|'moon'|'varsh'|'vargas'] ['score', label, n]
 *   ['pros', [text…], [text…]] ['note', text]
 * A chapter is ['id', 'title', 'icon', 'blocks'] or, for one page per item, ['id', 'title', 'icon', 'sections' => [['title', 'sub', 'blocks'], …]].
 */
final class ReportBook {
    private array $kb;
    private array $T;
    private RuleEngine $re;
    private array $by = [];
    private array $score = [];

    public function __construct(private string $lang) {
        $l = in_array($lang, ['en', 'hi', 'gu'], true) ? $lang : 'en';
        $this->kb = require __DIR__ . "/../../lang/kb_$l.php";
        $this->T = $this->kb['t'];
        $this->re = new RuleEngine($l);
    }

    private function P(string $p): string { return Lang::t($this->lang, 'astro.planets.' . $p); }
    private function S(string $s): string { return Lang::t($this->lang, 'astro.signs.' . $s); }
    private function N(string $n): string { return Lang::t($this->lang, 'astro.nakshatras.' . $n); }
    private function W(string $d): string { return Lang::t($this->lang, 'astro.weekdays.' . $d); }
    private function t(string $k, array $v = []): string {
        $s = $this->T[$k] ?? $k;
        foreach ($v as $a => $b) $s = str_replace('{' . $a . '}', (string) $b, $s);
        return $s;
    }
    private function hn(int $h): string { return $this->T['house_n'][$h - 1]; }
    private function mantra(string $m): string { return $this->lang === 'gu' ? Lang::gu($m) : $m; }
    private function label(int $s): string { return $s >= 60 ? $this->t('strong') : ($s < 45 ? $this->t('weak') : $this->t('average')); }

    public function build(array $r): array {
        $k = $r['kundali']; $p = $r['profile'];
        foreach ($k['planets'] as $x) $this->by[$x['name']] = $x;
        foreach ($r['summary']['planets'] as $n => $x) $this->score[$n] = (int) $x['score'];
        $ch = [];
        $ch[] = $this->intro();
        $ch[] = $this->birth($k, $p);
        $ch[] = ['id' => 'charts', 'title' => $this->t('ch_charts'), 'icon' => 'grid_view', 'blocks' => [['p', $this->t('charts_intro')], ['charts2', 'D1', 'D9'], ['chart', 'moon'], ['planets']]];
        $ch[] = ['id' => 'vargas', 'title' => $this->t('ch_vargas'), 'icon' => 'apps', 'blocks' => [['p', $this->t('vargas_intro')], ['chart', 'vargas']]];
        $ch[] = $this->lagna($k);
        $ch[] = $this->moon($k);
        $ch[] = $this->nak($k);
        $ch[] = $this->planets($k, $r);
        $ch[] = $this->houses($k);
        $ch[] = $this->life($k, $r);
        $ch[] = $this->yogas($r);
        $ch[] = $this->doshas($r);
        $ch[] = $this->dasha($k, $p);
        $ch[] = $this->current($k);
        $ch[] = $this->transits($r);
        $ch[] = $this->varsh($r);
        $ch[] = $this->months($k, $p, $r);
        $ch[] = $this->remedies($r);
        $ch[] = $this->lucky($r);
        $ch[] = ['id' => 'gloss', 'title' => $this->t('ch_gloss'), 'icon' => 'menu_book', 'blocks' => [['table', ['', ''], $this->T['gloss']]]];
        $ch[] = ['id' => 'note', 'title' => $this->t('ch_note'), 'icon' => 'info', 'blocks' => [['note', $this->t('note')]]];
        return ['labels' => array_intersect_key($this->T, array_flip(['cover_title', 'cover_sub', 'prepared_for', 'generated', 'contents', 'table_planet', 'table_sign', 'table_house', 'table_deg', 'table_nak', 'table_status'])),
            'chapters' => $ch];
    }

    private function intro(): array {
        return ['id' => 'intro', 'title' => $this->t('ch_intro'), 'icon' => 'auto_stories',
            'blocks' => [['p', $this->t('intro1')], ['p', $this->t('intro2')], ['p', $this->t('intro3')], ['p', $this->t('intro4')]]];
    }

    private function birth(array $k, array $p): array {
        $b = $k['birth']; $date = substr($b['local'], 0, 10);
        $rows = [[Lang::t($this->lang, 'ui.name'), $p['label']], [Lang::t($this->lang, 'ui.birth_date'), $b['local']],
            [Lang::t($this->lang, 'ui.birth_place'), $p['place_name']], [Lang::t($this->lang, 'ui.lagna'), $this->S($k['lagna']['sign_name'])],
            [Lang::t($this->lang, 'ui.moon_sign'), $this->S($this->by['Moon']['sign_name'])], [Lang::t($this->lang, 'ui.nakshatra'), $this->N($this->by['Moon']['nakshatra']) . ' · ' . $this->by['Moon']['pada']]];
        $blocks = [['facts', $rows], ['p', $this->t('birth_intro')]];
        try {
            $pc = (new PanchangService())->compute($date, (float) $b['lat'], (float) $b['lon'], $b['tzid']);
            $at = fn(array $list) => array_values(array_filter($list, fn($x) => ($x['end_jd'] ?? 0) > $b['jd_ut']))[0] ?? $list[0];
            $ti = $at($pc['tithi']); $nk = $at($pc['nakshatra']); $yo = $at($pc['yoga']); $ka = $at($pc['karana']);
            $blocks[] = ['facts', [[Lang::t($this->lang, 'ui.vara'), $this->W($pc['vara']['name'])],
                [Lang::t($this->lang, 'ui.tithi'), Lang::t($this->lang, 'astro.paksha.' . $ti['paksha']) . ' ' . Lang::t($this->lang, 'astro.tithis.' . $ti['name'])],
                [Lang::t($this->lang, 'ui.nakshatra'), $this->N($nk['name'])], [Lang::t($this->lang, 'ui.yoga'), Lang::t($this->lang, 'astro.yogas.' . $yo['name'])],
                [Lang::t($this->lang, 'ui.karana'), Lang::t($this->lang, 'astro.karanas.' . $ka['name'])],
                [Lang::t($this->lang, 'ui.sunrise') . ' / ' . Lang::t($this->lang, 'ui.sunset'), substr((string) $pc['sunrise'], 11) . ' / ' . substr((string) $pc['sunset'], 11)]]];
        } catch (\Throwable) { /* panchang is optional */ }
        return ['id' => 'birth', 'title' => $this->t('ch_birth'), 'icon' => 'event', 'blocks' => $blocks];
    }

    private function lagna(array $k): array {
        $s = $k['lagna']['sign_name']; $S = $this->kb['sign'][$s]; $lord = $this->signLord($k['lagna']['sign']);
        $lp = $this->by[$lord]; $sc = $this->score[$lord] ?? 50;
        $q = $sc >= 55 ? $this->kb['planet'][$lord]['strong'] : $this->kb['planet'][$lord]['weak'];
        return ['id' => 'lagna', 'title' => $this->t('ch_lagna') . ': ' . $this->S($s), 'icon' => 'person',
            'blocks' => [['facts', [[Lang::t($this->lang, 'ui.lagna'), $this->S($s) . ' ' . $k['lagna']['dms']], ['🜂', $S['el'] . ' · ' . $S['mod']], ['♥', $S['body']], [Lang::t($this->lang, 'ui.lord'), $this->P($lord)]]],
                ['p', $S['nature']], ['p', $S['lagna']],
                ['h', Lang::t($this->lang, 'ui.lord') . ': ' . $this->P($lord)],
                ['p', $this->t('lagna_lord', ['lord' => $this->P($lord), 'h' => $this->hn($lp['house']), 'sign' => $this->S($lp['sign_name']), 'topics' => $this->kb['house'][$lp['house']]['topics'], 'q' => $q])],
                ['score', $this->P($lord), $sc]]];
    }

    private function moon(array $k): array {
        $m = $this->by['Moon']; $S = $this->kb['sign'][$m['sign_name']]; $sc = $this->score['Moon'] ?? 50;
        return ['id' => 'moon', 'title' => $this->t('ch_moon') . ': ' . $this->S($m['sign_name']), 'icon' => 'dark_mode',
            'blocks' => [['p', $this->t('moon_intro', ['sign' => $this->S($m['sign_name']), 'h' => $this->hn($m['house'])])], ['p', $S['nature']], ['p', $S['moon']],
                ['p', $sc >= 55 ? $this->kb['planet']['Moon']['strong'] : $this->kb['planet']['Moon']['weak']], ['score', $this->P('Moon'), $sc]]];
    }

    private function nak(array $k): array {
        $m = $this->by['Moon']; [$deity, $sym, $text] = $this->kb['nak'][$m['nakshatra']];
        $d9 = $k['vargas']['D9']['planets']['Moon'] ?? null;
        $blocks = [['facts', [[Lang::t($this->lang, 'ui.nakshatra'), $this->N($m['nakshatra']) . ' · ' . $m['pada']], [Lang::t($this->lang, 'ui.lord'), $this->P($m['nakshatra_lord'])], ['✦', $deity], ['◈', $sym]]],
            ['p', $this->t('nak_intro', ['nak' => $this->N($m['nakshatra']), 'pada' => $m['pada'], 'lord' => $this->P($m['nakshatra_lord']), 'deity' => $deity, 'symbol' => $sym])],
            ['p', $text], ['p', $this->t('nak_dasha', ['lord' => $this->P($m['nakshatra_lord'])])]];
        if ($d9 !== null) $blocks[] = ['p', $this->t('nak_pada', ['navamsa' => $this->S(Zodiac::SIGNS[$d9])])];
        return ['id' => 'nak', 'title' => $this->t('ch_nak') . ': ' . $this->N($m['nakshatra']), 'icon' => 'star', 'blocks' => $blocks];
    }

    private function planets(array $k, array $r): array {
        $lords = $k['analysis']['lordships']; $pr = []; foreach ($r['planet_results']['items'] as $it) $pr[substr($it['id'], 3)] = $it;
        $secs = [];
        foreach (['Sun', 'Moon', 'Mars', 'Mercury', 'Jupiter', 'Venus', 'Saturn', 'Rahu', 'Ketu'] as $pl) {
            $x = $this->by[$pl]; $K = $this->kb['planet'][$pl]; $sc = $this->score[$pl] ?? 50; $h = $x['house'];
            $blocks = [['facts', [[$this->t('table_sign'), $this->S($x['sign_name']) . ' ' . $x['dms']], [$this->t('table_house'), $h], [$this->t('table_nak'), $this->N($x['nakshatra']) . ' · ' . $x['pada']],
                [$this->t('table_status'), $this->label($sc) . ($x['retrograde'] && !in_array($pl, ['Rahu', 'Ketu'], true) ? ' · ℞' : '')]]],
                ['p', $K['sig']],
                ['p', $this->t('p_place', ['planet' => $this->P($pl), 'sign' => $this->S($x['sign_name']), 'h' => $this->hn($h), 'hname' => $this->kb['house'][$h]['name'], 'topics' => $this->kb['house'][$h]['topics'], 'sign_nature' => $this->kb['sign'][$x['sign_name']]['nature']])],
                ['p', str_replace('{planet}', $this->P($pl), $this->kb['dignity'][$x['dignity'] ?? ''] ?? $this->kb['dignity']['own'])]];
            if (!empty($lords[$pl])) $blocks[] = ['p', $this->t('p_lords', ['planet' => $this->P($pl), 'houses' => implode(', ', array_map(fn($n) => $this->hn($n), $lords[$pl])),
                'topics' => implode('; ', array_map(fn($n) => $this->kb['house'][$n]['topics'], $lords[$pl])), 'h' => $this->hn($h)])];
            $nk = $this->kb['nak'][$x['nakshatra']];
            $blocks[] = ['p', $this->t('p_nak', ['nak' => $this->N($x['nakshatra']), 'pada' => $x['pada'], 'lord' => $this->P($x['nakshatra_lord']), 'nak_text' => $nk[2]])];
            if ($x['retrograde'] && !in_array($pl, ['Sun', 'Moon', 'Rahu', 'Ketu'], true)) $blocks[] = ['p', $this->t('p_retro', ['planet' => $this->P($pl)])];
            if (!empty($x['combust'])) $blocks[] = ['p', $this->t('p_combust', ['planet' => $this->P($pl)])];
            $blocks[] = ['p', $sc >= 50 ? $K['strong'] : $K['weak']];
            $blocks[] = ['score', $this->t('p_score', ['planet' => $this->P($pl), 'score' => $sc, 'label' => $this->label($sc)]), $sc];
            if ($it = $pr[$pl] ?? null) {
                $blocks[] = ['h', $this->t('p_lk')];
                $blocks[] = ['p', $it['text']];
                $c = $this->lang === 'en' ? $this->re->lkCombos($k, $pl) : ['g' => [], 'c' => []]; // data/lk_combos.php is English-only
                $blocks[] = ['pros', array_merge($it['points']['positive'] ?? [], $c['g']), array_merge($it['points']['negative'] ?? [], $c['c'])];
            }
            $blocks[] = ['h', $this->t('p_rem', ['planet' => $this->P($pl)])];
            $blocks[] = ['list', array_merge([$K['remedy']], $it['remedies'] ?? [])];
            $blocks[] = ['facts', [[$this->t('f_day'), $K['day']], [$this->t('f_color'), $K['color']], [$this->t('f_gem'), $K['gem']], [$this->t('f_metal'), $K['metal']],
                [$this->t('f_grain'), $K['grain']], [$this->t('f_deity'), $K['deity']], [$this->t('f_number'), $K['number']], [$this->t('f_mantra'), $this->mantra($K['mantra'])]]];
            $secs[] = ['title' => $this->P($pl), 'sub' => $this->S($x['sign_name']) . ' · ' . $this->hn($h) . ' ' . $this->t('table_house'), 'blocks' => $blocks];
        }
        return ['id' => 'planets', 'title' => $this->t('ch_planets'), 'icon' => 'public', 'sections' => $secs];
    }

    private function signLord(int $s): string { return ['Mars', 'Venus', 'Mercury', 'Moon', 'Sun', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Saturn', 'Jupiter'][$s]; }

    private function houses(array $k): array {
        $secs = [];
        for ($h = 1; $h <= 12; $h++) {
            $H = $this->kb['house'][$h]; $sg = ($k['lagna']['sign'] + $h - 1) % 12; $lord = $this->signLord($sg); $lh = $this->by[$lord]['house'];
            $occ = array_values(array_filter($this->by, fn($x) => $x['house'] === $h));
            $blocks = [['p', $H['about']],
                ['p', $this->t('h_sign', ['sign' => $this->S(Zodiac::SIGNS[$sg]), 'lord' => $this->P($lord), 'sign_nature' => $this->kb['sign'][Zodiac::SIGNS[$sg]]['nature']])],
                ['p', $this->t('h_lord', ['lord' => $this->P($lord), 'h2' => $this->hn($lh), 'topics2' => $this->kb['house'][$lh]['topics']]) . ' '
                    . (in_array($lh, [6, 8, 12], true) ? $this->t('h_lord_hard', ['lord' => $this->P($lord)]) : (in_array($lh, [1, 2, 4, 5, 7, 9, 10, 11], true) ? $this->t('h_lord_good') : $this->t('h_lord_mid', ['lord' => $this->P($lord)])))]];
            if ($occ) {
                $blocks[] = ['p', $this->t('h_occ', ['list' => implode(', ', array_map(fn($x) => $this->P($x['name']), $occ))])];
                foreach ($occ as $x) $blocks[] = ['p', $this->t('h_occ_one', ['planet' => $this->P($x['name']), 'sig' => $this->firstSentences($this->kb['planet'][$x['name']]['sig'], 2)])];
            } else $blocks[] = ['p', $this->t('h_empty', ['lord' => $this->P($lord)])];
            $secs[] = ['title' => $this->hn($h) . ' ' . $this->t('table_house') . ' · ' . $H['name'], 'sub' => $H['topics'], 'blocks' => $blocks];
        }
        return ['id' => 'houses', 'title' => $this->t('ch_houses'), 'icon' => 'home', 'flow' => true, 'sections' => $secs];
    }

    private function firstSentences(string $s, int $n): string {
        $parts = preg_split('/(?<=[.।!?])\s+/u', $s); return implode(' ', array_slice($parts, 0, $n));
    }

    private function life(array $k, array $r): array {
        $map = ['life_character' => 1, 'life_fortune' => 9, 'life_lifestyle' => 4, 'life_employment' => 10, 'life_business' => 7, 'life_health' => 6, 'life_hobbies' => 5, 'life_love' => 7, 'life_finance' => 2, 'life_education' => 4];
        $secs = [];
        foreach ($r['predictions']['life']['sections'] as $s) {
            $h = $map[$s['id']] ?? 1; $sg = ($k['lagna']['sign'] + $h - 1) % 12; $lord = $this->signLord($sg);
            $secs[] = ['title' => $s['title'], 'blocks' => [['p', $s['text']], ['p', $this->kb['house'][$h]['about']],
                ['p', $this->t('life_house', ['h' => $this->hn($h), 'lord' => $this->P($lord), 'h2' => $this->hn($this->by[$lord]['house'])])],
                ['p', ($this->score[$lord] ?? 50) >= 50 ? $this->kb['planet'][$lord]['strong'] : $this->kb['planet'][$lord]['weak']]]];
        }
        return ['id' => 'life', 'title' => $this->t('ch_life'), 'icon' => 'diversity_3', 'flow' => true, 'sections' => $secs];
    }

    private function yogas(array $r): array {
        $blocks = [];
        foreach ($r['yogas']['items'] as $y) if ($y['present'])
            $blocks = array_merge($blocks, [['h', $y['name']], ['p', $this->t('yoga_present') . ' ' . $this->t('yoga_rule') . ': ' . $y['rule'] . '.'], ['p', $this->t('yoga_effect') . ': ' . $y['effect']]]);
        $blocks[] = ['note', $this->t('yoga_use')];
        $absent = array_map(fn($y) => $y['name'] . ' — ' . $y['rule'], array_filter($r['yogas']['items'], fn($y) => !$y['present']));
        if ($absent) { $blocks[] = ['h', $this->t('yoga_absent')]; $blocks[] = ['list', array_values($absent)]; }
        return ['id' => 'yogas', 'title' => $this->t('ch_yoga'), 'icon' => 'join', 'blocks' => $blocks];
    }

    private function doshas(array $r): array {
        $blocks = [];
        foreach ($r['dosha_report']['items'] as $d) {
            $blocks[] = ['h', $d['name'] . ' — ' . ($d['present'] ? $this->t('dosha_present') : $this->t('dosha_absent'))];
            $blocks[] = ['p', $this->kb['dosha'][$d['id']] ?? $d['rule']];
            if ($d['present']) {
                $blocks[] = ['p', $this->t('dosha_effects') . ': ' . $d['effects']];
                $blocks[] = ['list', array_values(array_filter(array_map('trim', preg_split('/[;\n]/u', (string) $d['remedy']))))];
            }
        }
        $rin = $r['dosha_report']['rin'] ?? null;
        if ($rin) {
            $blocks[] = ['h', Lang::t($this->lang, 'ui.rin')]; $blocks[] = ['p', $this->t('rin_intro')];
            foreach ($rin['items'] as $x) if ($x['present']) {
                $blocks[] = ['h', $x['name']]; $blocks[] = ['p', $x['rule'] . ' ' . $x['effect']];
                $blocks[] = ['list', array_values(array_filter(array_map('trim', explode(';', (string) $x['remedy']))))];
            }
        }
        return ['id' => 'doshas', 'title' => $this->t('ch_dosha'), 'icon' => 'health_and_safety', 'blocks' => $blocks];
    }

    private function dasha(array $k, array $p): array {
        $birthY = (float) substr($k['birth']['local'], 0, 4) + ((int) substr($k['birth']['local'], 5, 2) - 1) / 12;
        $age = fn(string $d) => max(0, (int) floor((float) substr($d, 0, 4) + ((int) substr($d, 5, 2) - 1) / 12 - $birthY));
        $today = gmdate('Y-m-d'); $lords = $k['analysis']['lordships']; $secs = [];
        foreach ($k['dasha']['mahadasha'] as $md) {
            $pl = $md['lord']; $x = $this->by[$pl]; $now = $md['start'] <= $today && $today < $md['end'];
            $pts = $this->re->dashaPoints($k, $pl);
            $hs = $lords[$pl] ?? []; $topics = implode('; ', array_map(fn($n) => $this->kb['house'][$n]['topics'], array_unique(array_merge($hs, [$x['house']]))));
            $blocks = [['facts', [[Lang::t($this->lang, 'ui.from'), $md['start']], [Lang::t($this->lang, 'ui.to'), $md['end']], ['⏳', $this->t('d_period', ['from' => substr($md['start'], 0, 4), 'to' => substr($md['end'], 0, 4), 'years' => Zodiac::DASHA_YEARS[$pl], 'a1' => $age($md['start']), 'a2' => $age($md['end'])])]]],
                ['p', $this->kb['planet'][$pl]['dasha']],
                ['p', $this->t('d_place', ['lord' => $this->P($pl), 'sign' => $this->S($x['sign_name']), 'h' => $this->hn($x['house']), 'houses' => $hs ? implode(', ', array_map(fn($n) => $this->hn($n), $hs)) : '—', 'topics' => $topics])],
                ['pros', $pts['positive'], $pts['negative']],
                ['h', $this->t('d_ad')],
                ['table', [Lang::t($this->lang, 'ui.antardasha'), Lang::t($this->lang, 'ui.from'), Lang::t($this->lang, 'ui.to')],
                    array_map(fn($a) => [($a['start'] <= $today && $today < $a['end'] ? '▶ ' : '') . $this->P($a['lord']), $a['start'], $a['end']], $md['antardasha'])]];
            $secs[] = ['title' => $this->P($pl) . ' ' . Lang::t($this->lang, 'ui.mahadasha'), 'sub' => $md['start'] . ' → ' . $md['end'] . ($now ? ' · ' . $this->t('d_now') : ''), 'blocks' => $blocks, 'now' => $now];
        }
        return ['id' => 'dasha', 'title' => $this->t('ch_dasha'), 'icon' => 'timeline', 'sections' => $secs];
    }

    private function current(array $k): array {
        $today = gmdate('Y-m-d'); $md = null; $ad = null;
        foreach ($k['dasha']['mahadasha'] as $m) if ($m['start'] <= $today && $today < $m['end']) { $md = $m; foreach ($m['antardasha'] as $a) if ($a['start'] <= $today && $today < $a['end']) $ad = $a; }
        if (!$md) return ['id' => 'current', 'title' => $this->t('ch_current'), 'icon' => 'schedule', 'blocks' => []];
        $ad ??= $md['antardasha'][0];
        $b = [['p', $this->t('cur_intro', ['md' => $this->P($md['lord']), 'md_to' => $md['end'], 'ad' => $this->P($ad['lord']), 'ad_to' => $ad['end']])],
            ['p', $this->kb['planet'][$md['lord']]['dasha']], ['p', $this->t('cur_ad', ['ad' => $this->P($ad['lord'])]) . ' ' . $this->kb['planet'][$ad['lord']]['sig']],
            ['p', ($this->score[$ad['lord']] ?? 50) >= 50 ? $this->kb['planet'][$ad['lord']]['strong'] : $this->kb['planet'][$ad['lord']]['weak']],
            ['h', $this->t('p_rem', ['planet' => $this->P($ad['lord'])])], ['list', [$this->kb['planet'][$ad['lord']]['remedy'], $this->kb['planet'][$md['lord']]['remedy']]]];
        return ['id' => 'current', 'title' => $this->t('ch_current'), 'icon' => 'schedule', 'blocks' => $b];
    }

    private function transits(array $r): array {
        $b = [['p', $this->t('tr_intro')]];
        foreach ($r['transits']['planets'] as $x) {
            $hm = (int) $x['house_from_moon'];
            $b[] = ['h', $this->P($x['name']) . ' · ' . $this->S($x['sign_name'])];
            $b[] = ['p', $this->t('tr_row', ['planet' => $this->P($x['name']), 'sign' => $this->S($x['sign_name']), 'hm' => $this->hn($hm), 'hl' => $this->hn((int) $x['house_from_lagna'])]) . ' '
                . $this->kb['house'][$hm]['about']];
        }
        return ['id' => 'transit', 'title' => $this->t('ch_transit'), 'icon' => 'travel_explore', 'blocks' => $b];
    }

    private function varsh(array $r): array {
        $b = [['p', $this->t('varsh_intro')], ['chart', 'varsh'], ['note', $r['annual']['muntha']]];
        foreach ($r['annual']['items'] as $i) {
            $b[] = ['h', $i['title'] . ' · ' . Lang::t($this->lang, 'ui.v_' . $i['verdict'])];
            $b[] = ['p', trim($i['prediction'] . ' ' . ($i['description'] ?? ''))];
            if (!empty($i['reason'])) $b[] = ['p', $i['reason']];
            if (!empty($i['guidance'])) $b[] = ['p', $i['guidance']];
        }
        return ['id' => 'varsh', 'title' => $this->t('ch_varsh') . ' ' . substr($r['varshphal']['return_local'], 0, 4), 'icon' => 'cake', 'blocks' => $b];
    }

    private function months(array $k, array $p, array $r): array {
        $A = new Advanced($this->lang); $ks = new KundaliService(); $secs = [];
        $tz = new \DateTimeZone($p['tzid'] ?: 'Asia/Kolkata'); $start = new \DateTimeImmutable('first day of this month 12:00', $tz);
        $fmt = class_exists(\IntlDateFormatter::class) ? new \IntlDateFormatter(['en' => 'en_IN', 'hi' => 'hi_IN', 'gu' => 'gu_IN'][$this->lang] ?? 'en_IN', 0, 0, $p['tzid'] ?: 'Asia/Kolkata', null, 'MMMM y') : null;
        for ($i = 0; $i < 12; $i++) {
            $d = $start->modify("+$i month")->modify('+14 days'); $jd = $d->getTimestamp() / 86400 + 2440587.5;
            $m = $A->monthly($k, $ks->transits($k, $jd, (float) $p['lat'], (float) $p['lon']));
            $b = [['score', $this->t('m_score', ['score' => $m['overall'], 'label' => $this->label((int) $m['overall'])]), (int) $m['overall']]];
            foreach ($m['items'] as $it) {
                $b[] = ['h', $it['title'] . ' · ' . $it['score']];
                $b[] = ['p', trim($it['prediction'] . ' ' . ($it['description'] ?? '') . ' ' . ($it['guidance'] ?? ''))];
            }
            $secs[] = ['title' => $fmt ? $fmt->format($d) : $d->format('F Y'), 'blocks' => $b];
        }
        return ['id' => 'months', 'title' => $this->t('ch_months'), 'icon' => 'calendar_month', 'flow' => true, 'intro' => $this->t('month_intro'), 'sections' => $secs];
    }

    private function remedies(array $r): array {
        $b = [['p', $this->t('rem_intro')], ['h', $this->t('rem_priority')]];
        foreach ($r['priority_remedies']['items'] as $i) $b[] = ['p', $i['rank'] . '. ' . $i['title'] . ' — ' . $i['issue'] . ' ' . $i['how'] . ' ' . ($i['benefit'] ?? '')];
        $weak = array_keys(array_filter($this->score, fn($s) => $s < 50));
        if ($weak) {
            $b[] = ['h', $this->t('rem_mantra')];
            $b[] = ['table', [$this->t('table_planet'), $this->t('f_mantra'), $this->t('f_day'), $this->t('f_grain')],
                array_map(fn($pl) => [$this->P($pl), $this->mantra($this->kb['planet'][$pl]['mantra']), $this->kb['planet'][$pl]['day'], $this->kb['planet'][$pl]['grain']], $weak)];
        }
        $b[] = ['h', $this->t('rem_gems')];
        foreach (array_merge($r['gem_report']['recommended'], $r['gem_report']['caution']) as $g) $b[] = ['p', $g['gem'] . ' (' . $this->P($g['planet']) . '): ' . $g['why']];
        $b[] = ['note', $this->t('rem_gem_note')];
        return ['id' => 'remedy', 'title' => $this->t('ch_remedy'), 'icon' => 'spa', 'blocks' => $b];
    }

    private function lucky(array $r): array {
        $L = $r['luck']; $u = $L['lucky'];
        $rows = [[$this->t('f_color'), $u['color']], [$this->t('f_number'), implode(', ', $u['number'])], [$this->t('f_day'), implode(', ', array_map(fn($d) => $this->W($d), $u['day']))],
            ['📅', implode(', ', $u['dates'])], [$this->t('f_deity'), $L['aradhya']['text'] ?? '']];
        $b = [['p', $this->t('lucky_intro')], ['facts', $rows]];
        foreach ($u['from'] as $f) $b[] = ['p', $this->kb['planet'][$f['planet']]['sig']];
        return ['id' => 'lucky', 'title' => $this->t('ch_lucky'), 'icon' => 'auto_awesome', 'blocks' => $b];
    }
}
