<?php
namespace App\Interp;

use App\Calc\{Analysis, Dasha, Zodiac};
use App\I18n\Lang;

/**
 * Interpretation layer. Reads calculated kundali/transit data (never computes positions itself)
 * and applies explicit, versioned rules. Every item lists the calculated facts it was derived from.
 * Rules and texts are DRAFTS: have them reviewed by a qualified astrologer before launch.
 */
class RuleEngine {
    public const VERSION = '0.8';
    protected const ELEMENTS = ['fire', 'earth', 'air', 'water'];
    protected const MODALITY = ['movable', 'fixed', 'dual'];
    protected const MANTRA = ['Sun' => 'ॐ सूर्याय नमः', 'Moon' => 'ॐ चन्द्राय नमः', 'Mars' => 'ॐ भौमाय नमः', 'Mercury' => 'ॐ बुधाय नमः',
        'Jupiter' => 'ॐ गुरवे नमः', 'Venus' => 'ॐ शुक्राय नमः', 'Saturn' => 'ॐ शनैश्चराय नमः', 'Rahu' => 'ॐ राहवे नमः', 'Ketu' => 'ॐ केतवे नमः'];
    protected const DAY = ['Sun' => 0, 'Moon' => 1, 'Mars' => 2, 'Mercury' => 3, 'Jupiter' => 4, 'Venus' => 5, 'Saturn' => 6, 'Rahu' => 6, 'Ketu' => 2];

    public function __construct(protected string $lang) {}

    public function report(array $k, ?array $dashaNow): array {
        $l = $k['lagna']; $moon = $this->p($k, 'Moon');
        $items = [
            $this->item('lagna_element', 'personality', 'interp.element.' . self::ELEMENTS[$l['sign'] % 4], [],
                [$this->fact('lagna.sign', $l['sign_name'])], 'Lagna sign element (fire/earth/air/water)'),
            $this->item('lagna_modality', 'personality', 'interp.modality.' . self::MODALITY[$l['sign'] % 3], [],
                [$this->fact('lagna.sign', $l['sign_name'])], 'Lagna sign modality (movable/fixed/dual)'),
            $this->item('moon_element', 'mind', 'interp.moon_element.' . self::ELEMENTS[$moon['sign'] % 4], [],
                [$this->fact('planets.Moon.sign', $moon['sign_name'])], 'Moon sign element'),
        ];
        if ($dashaNow) {
            $items[] = $this->item('dasha_theme', 'present', 'interp.dasha_theme.' . $dashaNow['mahadasha'],
                ['planet' => $this->t('astro.planets.' . $dashaNow['mahadasha'])],
                [$this->fact('dasha.current.mahadasha', $dashaNow['mahadasha']), $this->fact('dasha.current.antardasha', $dashaNow['antardasha'])],
                'Vimshottari mahadasha lord signification');
        }
        return $this->wrap('report', $items);
    }

    public function remedies(string $period, array $k, array $tr, int $weekday): array {
        $items = [];
        $now = $tr['dasha_now'];
        if ($period === 'daily') {
            $wd = $weekday;
            $lord = Zodiac::WEEKDAY_LORDS[$wd];
            $items[] = $this->mantra('day_lord', 'interp.remedy_day_lord', $lord, [$this->fact('today.weekday', Zodiac::WEEKDAYS[$wd])], 'Weekday lord');
            if ($tr['derived']['chandrashtama']['active'])
                $items[] = $this->item('chandrashtama', 'caution', 'interp.chandrashtama', [],
                    [$this->fact('transit.Moon.house_from_moon', 8)], $tr['derived']['chandrashtama']['rule']);
        }
        if ($period === 'weekly' && $now && $now['antardasha'])
            $items[] = $this->mantra('antardasha', 'interp.remedy_mantra', $now['antardasha'],
                [$this->fact('dasha.current.antardasha', $now['antardasha'])], 'Current antardasha lord');
        if ($period === 'monthly') {
            if ($now) $items[] = $this->mantra('mahadasha', 'interp.remedy_mantra', $now['mahadasha'],
                [$this->fact('dasha.mahadasha_in_month', $now['mahadasha'])], 'Mahadasha lord running in this month');
            if ($now && $now['antardasha'] && $now['antardasha'] !== $now['mahadasha']) $items[] = $this->mantra('antardasha', 'interp.remedy_mantra', $now['antardasha'],
                [$this->fact('dasha.antardasha_in_month', $now['antardasha'])], 'Antardasha lord running in this month');
            $ss = $tr['derived']['sade_sati'];
            $sf = [$this->fact('transit.Saturn.house_from_moon', $tr['planets'][6]['house_from_moon'])];
            if ($ss['active']) $items[] = $this->item('sade_sati', 'remedy', 'interp.sade_sati', ['phase' => $this->t('interp.sade_phase.' . $ss['phase'])], $sf, $ss['rule']);
            if ($tr['derived']['shani_dhaiya']['active'])
                $items[] = $this->item('dhaiya', 'remedy', 'interp.dhaiya_yes', ['h' => $tr['derived']['shani_dhaiya']['house_from_moon']], $sf, $tr['derived']['shani_dhaiya']['rule']);
        }
        if ($period === 'common') {
            $lord = Analysis::houseLord($k['lagna']['sign'], 1);
            $items[] = $this->mantra('lagna_lord', 'interp.remedy_lagna', $lord, [$this->fact('lagna.sign', $k['lagna']['sign_name']), $this->fact('lagna.lord', $lord)], 'Strengthen the lagna lord');
            foreach ($k['planets'] as $p) if ($p['dignity'] === 'debilitated' || !empty($p['combust']))
                $items[] = $this->mantra('weak_' . $p['name'], $p['dignity'] === 'debilitated' ? 'interp.debilitated' : 'interp.remedy_weak', $p['name'], $this->pfacts($p), 'Planet debilitated or combust in the birth chart');
            $m = $k['analysis']['mangal_dosha'];
            if ($m['present']) $items[] = $this->item('mangal', 'remedy', 'interp.mangal_remedy', [], [$this->fact('mangal.from_lagna.house', $m['from']['lagna']['house']),
                $this->fact('mangal.from_moon.house', $m['from']['moon']['house'])], $m['rule']);
            $ks = $k['analysis']['kaal_sarp'];
            if ($ks['present']) $items[] = $this->item('kaal_sarp', 'remedy', 'interp.ks_remedy', ['type' => $ks['type']], [$this->fact('kaal_sarp.type', $ks['type'])], $ks['rule']);
        }
        return $this->wrap('remedies', $items) + ['period' => $period];
    }

    protected const GOCHAR_GOOD = ['Sun' => [3, 6, 10, 11], 'Moon' => [1, 3, 6, 7, 10, 11], 'Mars' => [3, 6, 11], 'Mercury' => [2, 4, 6, 8, 10, 11],
        'Jupiter' => [2, 5, 7, 9, 11], 'Venus' => [1, 2, 3, 4, 5, 8, 9, 11, 12], 'Saturn' => [3, 6, 11], 'Rahu' => [3, 6, 11], 'Ketu' => [3, 6, 11]];
    protected const GEM = ['Sun' => ['ring', 'gold', 0], 'Moon' => ['little', 'silver', 1], 'Mars' => ['ring', 'copper', 2], 'Mercury' => ['little', 'gold', 3],
        'Jupiter' => ['index', 'gold', 4], 'Venus' => ['middle', 'silver', 5], 'Saturn' => ['middle', 'panch', 6]];

    protected function kind(int $h): string {
        return in_array($h, [1, 4, 7, 10], true) ? 'kendra' : (in_array($h, [5, 9], true) ? 'trikona'
            : (in_array($h, [6, 8, 12], true) ? 'dusthana' : (in_array($h, [3, 11], true) ? 'upachaya' : 'neutral')));
    }
    /** Placement strength of a planet: house type + dignity + combustion. */
    protected function strength(array $p): array {
        $sc = in_array($p['name'], ['Rahu', 'Ketu'], true) ? (in_array($p['house'], [3, 6, 11], true) ? 1 : ($this->kind($p['house']) === 'dusthana' ? -1 : 0))
            : ['kendra' => 1, 'trikona' => 1, 'upachaya' => .5, 'neutral' => 0, 'dusthana' => -1][$this->kind($p['house'])];
        $sc += ['exalted' => 1, 'own' => 1, 'debilitated' => -1][$p['dignity'] ?? ''] ?? 0;
        if (!empty($p['combust'])) $sc -= .5;
        $dig = $p['dignity'] ? $this->t('interp.dig.' . $p['dignity']) : '';
        if (!empty($p['combust'])) $dig .= $this->t('interp.dig.combust');
        return [$sc >= 1 ? 'strong' : ($sc <= -1 ? 'weak' : 'mixed'), $dig, $sc];
    }
    protected function pn(string $n): string { return $this->t('astro.planets.' . $n); }
    protected function housesText(array $hs): string { return $hs ? $this->t('interp.rules_houses', ['list' => implode(', ', $hs)]) : $this->t('interp.rules_none'); }
    protected function themes(array $hs): string { return implode(', ', array_map(fn($h) => $this->t("interp.house_short.$h"), $hs)); }
    protected function by(array $k): array { $o = []; foreach ($k['planets'] as $p) $o[$p['name']] = $p; return $o; }
    protected function pfacts(array $p): array {
        return [$this->fact("planets.{$p['name']}.house", $p['house']), $this->fact("planets.{$p['name']}.sign", $p['sign_name']),
                $this->fact("planets.{$p['name']}.dignity", $p['dignity']), $this->fact("planets.{$p['name']}.combust", $p['combust'] ?? false)];
    }

    /** Complete life reading: personality, twelve houses, dasha timeline. */
    public function life(array $k, float $jdNow): array {
        $items = $this->report($k, null)['items'];
        $by = $this->by($k); $lagna = $k['lagna']['sign'];
        for ($h = 1; $h <= 12; $h++) {
            $lord = Analysis::houseLord($lagna, $h); $lp = $by[$lord];
            [$v, $dig] = $this->strength($lp);
            $occ = array_values(array_filter($k['planets'], fn($p) => $p['house'] === $h));
            $ben = array_filter($occ, fn($p) => in_array($p['name'], Analysis::NATURAL_BENEFICS, true));
            $okey = !$occ ? 'none' : (!$ben ? 'malefic' : (count($ben) === count($occ) ? 'benefic' : 'both'));
            $items[] = $this->item("house_$h", 'houses', 'interp.life_house', ['n' => $h, 'topic' => $this->t("interp.house_topic.$h"),
                'lord' => $this->pn($lord), 'm' => $lp['house'], 'kind' => $this->t('interp.house_kind.' . $this->kind($lp['house'])), 'dig' => $dig,
                'verdict' => $this->t("interp.verdict.$v"), 'occ' => $this->t("interp.occ.$okey", ['list' => implode(', ', array_map(fn($p) => $this->pn($p['name']), $occ))])],
                array_merge([$this->fact('lagna.sign', $k['lagna']['sign_name']), $this->fact("house_$h.lord", $lord),
                    $this->fact("house_$h.occupants", array_column($occ, 'name'))], $this->pfacts($lp)),
                'House lord placement (kendra/trikona/dusthana) + dignity + combustion; natural benefic/malefic occupants');
        }
        foreach ($k['dasha']['mahadasha'] as $md) {
            $when = $jdNow >= $md['end_jd'] ? 'past' : ($jdNow >= $md['start_jd'] ? 'current' : 'future');
            $items[] = $this->dashaItem('dasha_' . $md['lord'], 'timeline', 'interp.dasha_period', $md, $k, $when);
        }
        return $this->wrap('life', $items);
    }

    protected function dashaItem(string $id, string $sec, string $key, array $d, array $k, string $when = 'current'): array {
        $by = $this->by($k); $p = $by[$d['lord']]; $hs = $k['analysis']['lordships'][$d['lord']] ?? [];
        [$v, $dig] = $this->strength($p);
        $themes = $this->themes(array_unique(array_merge($hs, [$p['house']])));
        return $this->item($id, $sec, $key, ['start' => $d['start'], 'end' => $d['end'], 'planet' => $this->pn($d['lord']), 'when' => $this->t("interp.when.$when"),
            'houses' => $this->housesText($hs), 'm' => $p['house'], 'dig' => $dig, 'themes' => $themes, 'verdict' => $this->t("interp.verdict.$v")],
            array_merge([$this->fact('dasha.lord', $d['lord']), $this->fact('dasha.period', $d['start'] . ' – ' . $d['end']), $this->fact('lordships', $hs)], $this->pfacts($p)),
            'Dasha lord: houses ruled + house occupied + placement strength');
    }

    public const LIFE_SECTIONS = ['character', 'fortune', 'lifestyle', 'employment', 'business', 'health', 'hobbies', 'love', 'finance', 'education'];

    /** Life reading sections (AstroSage-style accordion). Each section keyed on one calculated factor. */
    public function lifeSections(array $k): array {
        $by = $this->by($k); $L = $k['lagna']; $lh = fn(int $h) => $by[Analysis::houseLord($L['sign'], $h)];
        $src = [
            'character' => ['sign', $L['sign'], [$this->fact('lagna.sign', $L['sign_name'])], 'Lagna sign'],
            'fortune'   => ['sign', $by['Moon']['sign'], [$this->fact('planets.Moon.sign', $by['Moon']['sign_name'])], 'Moon sign'],
            'lifestyle' => ['house', $lh(1)['house'], [$this->fact('lagna.lord', $lh(1)['name']), $this->fact('lagna_lord.house', $lh(1)['house'])], 'House occupied by the lagna lord'],
            'employment'=> ['house', $lh(10)['house'], [$this->fact('house_10.lord', $lh(10)['name']), $this->fact('house_10_lord.house', $lh(10)['house'])], 'House occupied by the 10th lord'],
            'business'  => ['house', $lh(7)['house'], [$this->fact('house_7.lord', $lh(7)['name']), $this->fact('house_7_lord.house', $lh(7)['house'])], 'House occupied by the 7th lord'],
            'health'    => ['sign', $L['sign'], [$this->fact('lagna.sign', $L['sign_name'])], 'Lagna sign'],
            'hobbies'   => ['house', $lh(3)['house'], [$this->fact('house_3.lord', $lh(3)['name']), $this->fact('house_3_lord.house', $lh(3)['house'])], 'House occupied by the 3rd lord'],
            'love'      => ['sign', $by['Venus']['sign'], [$this->fact('planets.Venus.sign', $by['Venus']['sign_name'])], 'Venus sign'],
            'finance'   => ['house', $lh(2)['house'], [$this->fact('house_2.lord', $lh(2)['name']), $this->fact('house_2_lord.house', $lh(2)['house'])], 'House occupied by the 2nd lord'],
            'education' => ['house', $lh(5)['house'], [$this->fact('house_5.lord', $lh(5)['name']), $this->fact('house_5_lord.house', $lh(5)['house'])], 'House occupied by the 5th lord'],
        ];
        $items = [];
        foreach ($src as $sec => [$type, $v, $facts, $rule]) {
            $idx = $type === 'sign' ? $v : $v - 1;
            $extra = [];
            $el = ['fire', 'earth', 'air', 'water'][$L['sign'] % 4]; $md = ['movable', 'fixed', 'dual'][$L['sign'] % 3];
            if ($sec === 'character') $extra = [$this->t("interp.element.$el"), $this->t("interp.modality.$md")];
            elseif ($sec === 'health') { $x = $lh(1); $extra = [$this->t("interp.element.$el"), $this->c("planet_house.{$x['name']}." . ($x['house'] - 1))]; }
            elseif ($sec === 'fortune') $extra = [$this->t('interp.moon_element.' . ['fire', 'earth', 'air', 'water'][$by['Moon']['sign'] % 4]), $this->c('planet_house.Moon.' . ($by['Moon']['house'] - 1))];
            elseif ($sec === 'love') $extra = [$this->c('planet_house.Venus.' . ($by['Venus']['house'] - 1))];
            else { $hh = ['lifestyle' => 1, 'employment' => 10, 'business' => 7, 'hobbies' => 3, 'finance' => 2, 'education' => 5][$sec]; $x = $lh($hh); $extra = [$this->c("planet_house.{$x['name']}." . ($x['house'] - 1)), $this->lk($k, $x['name'])['effect']]; }
            $items[] = ['id' => "life_$sec", 'section' => $sec, 'title' => $this->t("ui.life_$sec"), 'text' => implode(' ', array_merge([$this->c("life.$sec.$idx")], $extra)),
                        'derivation' => ['rule' => $rule, 'ruleset' => self::VERSION, 'from_calculated' => $facts]];
        }
        return $this->wrap('life_sections', $items);
    }

    /** Mahadasha phal: result of each dasha lord by its natal house and sign. */
    public function mahadashaPhal(array $k, float $jdNow): array {
        $by = $this->by($k); $items = [];
        foreach ($k['dasha']['mahadasha'] as $md) {
            $p = $by[$md['lord']];
            $when = $jdNow >= $md['end_jd'] ? 'past' : ($jdNow >= $md['start_jd'] ? 'current' : 'future');
            $items[] = ['id' => 'mdphal_' . $md['lord'], 'section' => $when, 'points' => $this->dashaPoints($k, $md['lord']),
                'title' => $this->t('interp.md_title', ['planet' => $this->pn($md['lord']), 'start' => $md['start'], 'end' => $md['end']]),
                'subtitle' => $this->t('interp.planet_in', ['planet' => $this->pn($md['lord']), 'sign' => $this->t('astro.signs.' . $p['sign_name']), 'h' => $p['house']]),
                'text' => $this->lk($k, $md['lord'])['effect'] . ' ' . $this->c("planet_house.{$md['lord']}." . ($p['house'] - 1)) . ' ' . $this->t('interp.dasha_theme.' . $md['lord'], ['planet' => $this->pn($md['lord'])]) . ' '
                    . $this->t('interp.md_emphasis') . ' ' . $this->t('interp.verdict.' . $this->strength($p)[0]),
                'derivation' => ['rule' => 'Dasha lord results by natal house placement', 'ruleset' => self::VERSION,
                    'from_calculated' => array_merge([$this->fact('dasha.lord', $md['lord']), $this->fact('dasha.period', $md['start'] . ' – ' . $md['end'])], $this->pfacts($p))]];
        }
        return $this->wrap('mahadasha_phal', $items);
    }

    /** Planet-by-planet results and traditional remedies. */
    public function planetResults(array $k): array {
        $items = [];
        foreach ($k['planets'] as $p) {
            $rem = []; for ($i = 0; $i < 3; $i++) $rem[] = $this->c("remedies.{$p['name']}.$i");
            $items[] = ['id' => 'pr_' . $p['name'], 'section' => 'planet', 'title' => $this->t('interp.pr_title', ['planet' => $this->pn($p['name'])]),
                'subtitle' => $this->t('interp.planet_in', ['planet' => $this->pn($p['name']), 'sign' => $this->t('astro.signs.' . $p['sign_name']), 'h' => $p['house']]),
                'text' => ($L = $this->lk($k, $p['name']))['effect'] . ' ' . $this->c("planet_house.{$p['name']}." . ($p['house'] - 1)), 'remedies' => $L['remedies'] ?: $rem, 'benefic' => $L['benefic'],
                'derivation' => ['rule' => 'Planet results by natal house; remedies are traditional for the planet', 'ruleset' => self::VERSION, 'from_calculated' => $this->pfacts($p)]];
        }
        return $this->wrap('planet_results', $items);
    }

    protected function c(string $key): string { return \App\I18n\Lang::content($this->lang, $key); }

    /** House-based benefic/malefic rule per planet (house counted from lagna) with effect text and remedies. */
    protected const LK_BAD = ['Sun' => [6, 7, 10], 'Moon' => [6, 8, 10, 11, 12], 'Mars' => [4, 8], 'Mercury' => [3, 8, 9, 10, 11, 12], 'Jupiter' => [6, 7, 10],
        'Venus' => [1, 5, 6, 8, 9], 'Saturn' => [1, 4, 5, 6], 'Rahu' => [1, 5, 7, 8, 9, 11], 'Ketu' => [3, 4, 6, 8]];
    public function lk(array $k, string $pl): array {
        $h = $this->by($k)[$pl]['house']; $good = !in_array($h, self::LK_BAD[$pl], true);
        $rem = []; for ($i = 0; $i < 4; $i++) { $x = $this->c("lk.$pl.$h.rem.$i"); if ($x !== '') $rem[] = $x; }
        return ['benefic' => $good, 'house' => $h, 'effect' => $this->c("lk.$pl.$h." . ($good ? 'good' : 'bad')), 'other' => $this->c("lk.$pl.$h." . ($good ? 'bad' : 'good')), 'remedies' => $rem];
    }

    /** Positive / caution points for a dasha lord from the houses it rules and occupies, and its strength. */
    public function dashaPoints(array $k, string $pl): array {
        $by = $this->by($k); $p = $by[$pl]; $hs = array_unique(array_merge($k['analysis']['lordships'][$pl] ?? [], [$p['house']]));
        [$v] = $this->strength($p); $fn = $this->functional($k)[$pl]['class'] ?? null; $pos = []; $neg = [];
        foreach ($hs as $h) {
            $topic = $this->t("interp.house_topic.$h");
            if (in_array($h, [6, 8, 12], true)) $neg[] = $this->t('interp.md_neg', ['topic' => $topic]);
            elseif ($v === 'weak' || $fn === 'bad') $neg[] = $this->t('interp.md_delay', ['topic' => $topic]);
            else $pos[] = $this->t('interp.md_pos', ['topic' => $topic]);
        }
        $why = array_filter([$p['dignity'] ? $this->t('astro.dignity.' . $p['dignity']) : null, !empty($p['combust']) ? $this->t('ui.combust') : null]);
        if ($v === 'strong') $pos[] = $this->t('interp.md_strong', ['planet' => $this->pn($pl)]);
        if ($v === 'weak') $neg[] = $this->t('interp.md_weak', ['planet' => $this->pn($pl), 'why' => $why ? implode(', ', $why) : $this->t('ui.house') . ' ' . $p['house']]);
        if (in_array($fn, ['good', 'yoga'], true)) $pos[] = $this->t('interp.md_fn_good', ['planet' => $this->pn($pl)]);
        if ($fn === 'bad') $neg[] = $this->t('interp.md_fn_bad', ['planet' => $this->pn($pl)]);
        $L = $this->lk($k, $pl); if ($L['benefic']) { $pos[] = $L['effect']; $neg[] = $L['other']; } else { $neg[] = $L['effect']; }
        if (in_array($pl, ['Rahu', 'Ketu'], true)) { $opp = ($p['house'] + 5) % 12 + 1; $neg[] = $this->t('interp.md_neg', ['topic' => $this->t("interp.house_topic.$opp")]); }
        if (count($neg) < 2) $neg[] = $this->t("interp.md_nat.$pl");
        if (!$pos) $pos[] = $this->t('interp.dasha_theme.' . $pl, ['planet' => $this->pn($pl)]);
        return ['positive' => array_values(array_unique($pos)), 'negative' => array_values(array_unique($neg))];
    }

    public function dashaNow(array $k, float $jd): array {
        $items = [];
        foreach ($k['dasha']['mahadasha'] as $md) if ($jd >= $md['start_jd'] && $jd < $md['end_jd']) {
            $it = $this->dashaItem('mahadasha', 'mahadasha', 'interp.dasha_period', $md, $k, 'current'); $pm = $this->by($k)[$md['lord']];
            $it += ['lord' => $md['lord'], 'start' => $md['start'], 'end' => $md['end'], 'progress' => round(($jd - $md['start_jd']) / ($md['end_jd'] - $md['start_jd']) * 100),
                    'placement' => $this->t('interp.planet_in', ['planet' => $this->pn($md['lord']), 'sign' => $this->t('astro.signs.' . $pm['sign_name']), 'h' => $pm['house']]),
                    'details' => $this->lk($k, $md['lord'])['effect'] . ' ' . $this->c("planet_house.{$md['lord']}." . ($pm['house'] - 1)), 'points' => $this->dashaPoints($k, $md['lord']),
                    'lk_remedies' => $this->lk($k, $md['lord'])['remedies'],
                    'mantra' => $this->t('interp.remedy_mantra', ['planet' => $this->pn($md['lord']), 'mantra' => self::MANTRA[$md['lord']], 'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$md['lord']]])])];
            $items[] = $it;
            if ($dt = Dasha::current(['mahadasha' => [$md]], $jd))
                $items[] = $this->item('dasha_theme', 'mahadasha', 'interp.dasha_theme.' . $md['lord'], ['planet' => $this->pn($md['lord'])],
                    [$this->fact('dasha.current.mahadasha', $md['lord'])], 'Vimshottari mahadasha lord signification');
            foreach ($md['antardasha'] as $ad) if ($jd >= $ad['start_jd'] && $jd < $ad['end_jd'])
                $items[] = $this->dashaItem('antardasha', 'antardasha', 'interp.antardasha_now', $ad, $k) + ['lord' => $ad['lord'], 'start' => $ad['start'], 'end' => $ad['end'],
                    'progress' => round(($jd - $ad['start_jd']) / ($ad['end_jd'] - $ad['start_jd']) * 100), 'points' => $this->dashaPoints($k, $ad['lord'])];
            foreach ($md['antardasha'] as $i => $ad) if ($jd >= $ad['start_jd'] && $jd < $ad['end_jd'] && isset($md['antardasha'][$i + 1])) { $nx = $md['antardasha'][$i + 1];
                $items[] = ['id' => 'next_ad', 'section' => 'next', 'lord' => $nx['lord'], 'start' => $nx['start'], 'end' => $nx['end'], 'text' => '', 'points' => $this->dashaPoints($k, $nx['lord']),
                    'derivation' => ['rule' => 'Next antardasha', 'ruleset' => self::VERSION, 'from_calculated' => [$this->fact('dasha.antardasha', $nx['lord'])]]]; }
        }
        return $this->wrap('dasha', $items);
    }

    /** Daily or monthly horoscope from gochar relative to the natal Moon (tr computed for the target date). */
    public function horoscope(string $period, array $k, array $tr): array {
        $items = []; $score = 0;
        $list = $period === 'daily' ? ['Moon', 'Sun', 'Mars', 'Mercury', 'Venus'] : ['Sun', 'Mars', 'Mercury', 'Venus', 'Jupiter', 'Saturn', 'Rahu', 'Ketu'];
        foreach ($tr['planets'] as $p) if (in_array($p['name'], $list, true)) {
            $good = in_array($p['house_from_moon'], self::GOCHAR_GOOD[$p['name']], true); $score += $good ? 1 : -1;
            $items[] = $this->item('gochar_' . $p['name'], 'gochar', $good ? 'interp.gochar_good' : 'interp.gochar_bad',
                ['planet' => $this->pn($p['name']), 'h' => $p['house_from_moon'], 'domain' => $this->t('interp.planet_domain.' . $p['name'])],
                [$this->fact("transit.{$p['name']}.house_from_moon", $p['house_from_moon']), $this->fact("transit.{$p['name']}.sign", $p['sign_name'])],
                'Classical gochar: favourable houses from the natal Moon for ' . $p['name'] . ' = ' . implode(', ', self::GOCHAR_GOOD[$p['name']]));
        }
        if ($period === 'daily') {
            $ti = $tr['derived']['tara']['index']; $tv = $ti === 0 ? 'mixed' : (in_array($ti, [2, 4, 6], true) ? 'bad' : 'good');
            $score += ['good' => 1, 'bad' => -1, 'mixed' => 0][$tv];
            $items[] = $this->item('tara', 'tara', "interp.tara.$tv", ['tara' => $this->t("astro.taras.$ti")],
                [$this->fact('transit.tara_index', $ti)], $tr['derived']['tara']['rule']);
            if ($tr['derived']['chandrashtama']['active'])
                $items[] = $this->item('chandrashtama', 'caution', 'interp.chandrashtama', [], [$this->fact('transit.Moon.house_from_moon', 8)], $tr['derived']['chandrashtama']['rule']);
        }
        $ov = $score >= 2 ? 'good' : ($score <= -2 ? 'bad' : 'mixed');
        array_unshift($items, $this->item('overall', 'summary', "interp.overall.$ov", ['period' => $this->t('interp.period.' . ($period === 'daily' ? 'day' : 'month'))],
            [$this->fact('gochar.score', $score)], 'Sum of favourable (+1) and unfavourable (−1) transits'));
        return $this->wrap('horoscope_' . $period, $items) + ['as_on_utc' => $tr['meta']['utc']];
    }

    public function doshas(array $k, array $tr): array {
        $a = $k['analysis']; $m = $a['mangal_dosha']; $ks = $a['kaal_sarp']; $items = [];
        $mf = [$this->fact('mangal.from_lagna.house', $m['from']['lagna']['house']), $this->fact('mangal.from_moon.house', $m['from']['moon']['house']),
               $this->fact('mangal.from_venus.house', $m['from']['venus']['house']), $this->fact('planets.Mars.sign', $m['mars_sign'])];
        if ($m['present']) {
            $hs = []; foreach (['lagna', 'moon'] as $r) if ($m['from'][$r]['present']) $hs[] = $m['from'][$r]['house'] . ' (' . $this->t('ui.' . ($r === 'lagna' ? 'from_lagna' : 'from_moon')) . ')';
            $items[] = $this->item('mangal', 'mangal', 'interp.mangal_yes', ['houses' => implode(', ', $hs)], $mf, $m['rule']);
            if ($m['mitigation_own_or_exalted']) $items[] = $this->item('mangal_mitig', 'mangal', 'interp.mangal_mitig', [], $mf, $m['rule']);
            $items[] = $this->item('mangal_remedy', 'mangal', 'interp.mangal_remedy', [], $mf, $m['rule']);
        } else $items[] = $this->item('mangal', 'mangal', 'interp.mangal_no', [], $mf, $m['rule']);
        $kf = [$this->fact('kaal_sarp.present', $ks['present']), $this->fact('planets.Rahu.house', $ks['rahu_house'])];
        $items[] = $this->item('kaal_sarp', 'kaal_sarp', $ks['present'] ? 'interp.ks_yes' : 'interp.ks_no', ['type' => (string) $ks['type']], $kf, $ks['rule']);
        $ss = $tr['derived']['sade_sati']; $cy = $tr['sade_sati_cycle'];
        $sf = [$this->fact('transit.Saturn.house_from_moon', $tr['planets'][6]['house_from_moon']), $this->fact('sade_sati.period', $cy ? $cy['start'] . ' – ' . $cy['end'] : null)];
        $items[] = $ss['active'] && $cy ? $this->item('sade_sati', 'sade_sati', 'interp.ss_active', ['phase' => $this->t('interp.sade_phase.' . $ss['phase']), 'start' => $cy['start'], 'end' => $cy['end']], $sf, $cy['rule'])
            : ($cy ? $this->item('sade_sati', 'sade_sati', 'interp.ss_next', ['start' => $cy['start'], 'end' => $cy['end']], $sf, $cy['rule'])
                   : $this->item('sade_sati', 'sade_sati', 'interp.ss_none', [], $sf, $ss['rule']));
        if ($tr['derived']['shani_dhaiya']['active'])
            $items[] = $this->item('dhaiya', 'sade_sati', 'interp.dhaiya_yes', ['h' => $tr['derived']['shani_dhaiya']['house_from_moon']], $sf, $tr['derived']['shani_dhaiya']['rule']);
        return $this->wrap('doshas', $items);
    }

    /** Functional nature per planet for this lagna (simplified Parashari). */
    public function functional(array $k): array {
        $L = $k['analysis']['lordships']; $lagnaLord = Analysis::houseLord($k['lagna']['sign'], 1); $out = [];
        foreach ($L as $pl => $hs) {
            $sc = 0; $ben = in_array($pl, Analysis::NATURAL_BENEFICS, true);
            foreach ($hs as $h) $sc += [1 => 2, 5 => 2, 9 => 2, 3 => -1, 6 => -1, 11 => -1, 8 => -2, 2 => 0, 12 => 0][$h] ?? ($ben ? -.5 : .5);
            $yoga = array_intersect($hs, [4, 7, 10]) && array_intersect($hs, [5, 9]);
            if ($pl === $lagnaLord || array_intersect($hs, [5, 9])) $sc = max($sc, 1);   // lagna and trikona lordship dominate
            $out[$pl] = ['houses' => $hs, 'class' => $yoga ? 'yoga' : ($sc >= 1 ? 'good' : ($sc <= -1 ? 'bad' : 'mixed'))];
        }
        return $out;
    }

    public function influences(array $k): array {
        $items = []; $by = $this->by($k);
        foreach ($this->functional($k) as $pl => $f)
            $items[] = $this->item("fn_$pl", $f['class'] === 'bad' ? 'inauspicious' : ($f['class'] === 'mixed' ? 'mixed' : 'auspicious'), 'interp.inf_' . $f['class'],
                ['planet' => $this->pn($pl), 'houses' => $this->housesText($f['houses'])], [$this->fact('lagna.sign', $k['lagna']['sign_name']), $this->fact("lordships.$pl", $f['houses'])],
                'Functional nature by lordship: 1/5/9 +2 (never below auspicious), 3/6/11 −1, 8 −2, 2/12 0, kendra ∓0.5 (benefic/malefic); kendra+trikona = yogakaraka');
        foreach (['Rahu', 'Ketu'] as $n) {
            $h = $by[$n]['house']; $good = in_array($h, [3, 6, 11], true);
            $items[] = $this->item("node_$n", $good ? 'auspicious' : 'inauspicious', $good ? 'interp.inf_node_good' : 'interp.inf_node_bad',
                ['planet' => $this->pn($n), 'm' => $h, 'themes' => $this->themes([$h])], [$this->fact("planets.$n.house", $h)], 'Nodes are traditionally helpful in upachaya houses 3, 6, 11');
        }
        foreach ($k['planets'] as $p) {
            $why = array_filter([$p['dignity'] ? $this->t('astro.dignity.' . $p['dignity']) : null, !empty($p['combust']) ? $this->t('ui.combust') : null,
                                 $p['retrograde'] && !in_array($p['name'], ['Rahu', 'Ketu'], true) ? $this->t('ui.retro') : null]);
            if (in_array($p['dignity'], ['exalted', 'own'], true)) $items[] = $this->item('str_' . $p['name'], 'auspicious', 'interp.inf_strong', ['planet' => $this->pn($p['name']), 'why' => implode(', ', $why)], $this->pfacts($p), 'Exalted or own-sign placement');
            elseif ($p['dignity'] === 'debilitated' || !empty($p['combust'])) $items[] = $this->item('str_' . $p['name'], 'inauspicious', 'interp.inf_weak', ['planet' => $this->pn($p['name']), 'why' => implode(', ', $why)], $this->pfacts($p), 'Debilitation or combustion');
        }
        return $this->wrap('influences', $items);
    }

    public function gemstones(array $k): array {
        $items = []; $by = $this->by($k); $fn = $this->functional($k); $lagnaLord = Analysis::houseLord($k['lagna']['sign'], 1);
        $wear = function (string $pl) {
            [$f, $m, $d] = self::GEM[$pl];
            return $this->t('interp.gem_wear', ['finger' => $this->t("astro.fingers.$f"), 'metal' => $this->t("astro.metals.$m"), 'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[$d])]);
        };
        $caution = function (string $pl) use ($by) {
            $p = $by[$pl]; $why = array_filter([$p['dignity'] === 'debilitated' ? $this->t('astro.dignity.debilitated') : null,
                !empty($p['combust']) ? $this->t('ui.combust') : null, in_array($p['house'], [6, 8, 12], true) ? $this->t('ui.house') . ' ' . $p['house'] : null]);
            return $why ? ' ' . $this->t('interp.gem_caution', ['planet' => $this->pn($pl), 'why' => implode(', ', $why)]) : '';
        };
        $rule = 'Lagna lord stone first; then stones of functional benefics (lords of 5 and 9, yogakaraka); avoid stones of functional malefics';
        $cand = [$lagnaLord];
        foreach ($fn as $pl => $f) if ($pl !== $lagnaLord && in_array($f['class'], ['yoga', 'good'], true)) $cand[] = $pl;
        foreach ($cand as $i => $pl) {
            $text = $this->t($i === 0 ? 'interp.gem_primary' : 'interp.gem_benefic', ['gem' => $this->t("astro.gems.$pl"), 'planet' => $this->pn($pl),
                'houses' => $this->housesText($fn[$pl]['houses']), 'themes' => $this->themes($fn[$pl]['houses'])]) . ' ' . $wear($pl) . $caution($pl)
                . ($pl === 'Saturn' ? ' ' . $this->t('interp.gem_saturn') : '');
            $items[] = ['id' => "gem_$pl", 'section' => $i === 0 ? 'primary' : 'supportive', 'text' => $text, 'gem' => $this->t("astro.gems.$pl"), 'planet' => $pl,
                'derivation' => ['rule' => $rule, 'ruleset' => self::VERSION, 'from_calculated' => array_merge([$this->fact('lagna.sign', $k['lagna']['sign_name']),
                    $this->fact("lordships.$pl", $fn[$pl]['houses'])], $this->pfacts($by[$pl]))]];
        }
        foreach ($fn as $pl => $f) if ($f['class'] === 'bad' && $pl !== $lagnaLord)
            $items[] = $this->item("avoid_$pl", 'avoid', 'interp.gem_avoid', ['gem' => $this->t("astro.gems.$pl"), 'planet' => $this->pn($pl), 'houses' => $this->housesText($f['houses'])],
                [$this->fact("lordships.$pl", $f['houses'])], $rule);
        $r = $this->wrap('gemstones', $items);
        $r['meta']['disclaimer'] .= ' ' . $this->t('interp.gem_disclaimer');
        return $r;
    }

    protected function mantra(string $id, string $key, string $planet, array $facts, string $rule): array {
        return $this->item($id, 'remedy', $key, ['planet' => $this->t('astro.planets.' . $planet), 'mantra' => self::MANTRA[$planet],
            'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$planet]])], $facts, $rule);
    }

    protected function item(string $id, string $section, string $key, array $vars, array $facts, string $rule): array {
        return ['id' => $id, 'section' => $section, 'text' => $this->t($key, $vars),
                'derivation' => ['rule' => $rule, 'ruleset' => self::VERSION, 'from_calculated' => $facts]];
    }

    protected function wrap(string $kind, array $items): array {
        return ['meta' => ['type' => 'interpretation', 'kind' => $kind, 'ruleset' => self::VERSION, 'lang' => $this->lang,
                           'disclaimer' => $this->t('interp.disclaimer')], 'items' => $items];
    }

    protected function fact(string $path, mixed $value): array { return ['path' => $path, 'value' => $value]; }
    protected function t(string $key, array $vars = []): string { return Lang::t($this->lang, $key, $vars); }
    protected function p(array $k, string $n): array { foreach ($k['planets'] as $p) if ($p['name'] === $n) return $p; return []; }
}
