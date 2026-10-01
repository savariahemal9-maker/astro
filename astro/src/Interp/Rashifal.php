<?php
namespace App\Interp;

use App\Calc\{Ephemeris, Zodiac};

/**
 * Rashifal (Moon-sign horoscope) for all 12 rashis — daily, weekly or monthly.
 * Calculated from the real planetary positions of that day / mid-week / mid-month, counted from each rashi
 * (classical gochar: GOCHAR_GOOD houses). Each life area is scored from the planets that rule it.
 */
final class Rashifal extends RuleEngine {
    // area => planets that decide it (daily readings lean on the fast Moon)
    private const AREAS = [
        'daily' => ['career' => ['Moon', 'Sun', 'Mercury'], 'money' => ['Moon', 'Venus', 'Mercury'], 'love' => ['Moon', 'Venus'], 'health' => ['Moon', 'Mars', 'Sun']],
        'weekly' => ['career' => ['Sun', 'Mercury', 'Mars'], 'money' => ['Venus', 'Mercury', 'Sun'], 'love' => ['Venus', 'Mars'], 'health' => ['Mars', 'Sun']],
        'monthly' => ['career' => ['Sun', 'Saturn', 'Mercury', 'Jupiter'], 'money' => ['Jupiter', 'Venus', 'Mercury', 'Rahu'], 'love' => ['Venus', 'Jupiter', 'Mars'], 'health' => ['Mars', 'Saturn', 'Sun', 'Ketu']],
    ];
    private const NUM = ['Sun' => 1, 'Moon' => 2, 'Jupiter' => 3, 'Rahu' => 4, 'Mercury' => 5, 'Venus' => 6, 'Ketu' => 7, 'Saturn' => 8, 'Mars' => 9];
    private const HEX = ['Sun' => '#f28c28', 'Moon' => '#eef1f4', 'Mars' => '#d93025', 'Mercury' => '#1e8e3e', 'Jupiter' => '#f4c20d',
        'Venus' => '#f8f4ec', 'Saturn' => '#1f3a93', 'Rahu' => '#607d8b', 'Ketu' => '#8d6e63'];
    private const ICON = ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓'];

    /** @param \DateTimeImmutable $date local date (India) */
    public function all(string $period, \DateTimeImmutable $date): array {
        [$from, $to, $at] = match ($period) {
            'daily' => [$date, $date, $date->setTime(12, 0)],
            'weekly' => [$m = $date->modify('monday this week'), $m->modify('+6 days'), $m->modify('+3 days')->setTime(12, 0)],
            default => [$f = $date->modify('first day of this month'), $date->modify('last day of this month'), $f->modify('+14 days')->setTime(12, 0)],
        };
        $jd = $at->getTimestamp() / 86400 + 2440587.5;
        $sign = [];
        foreach ((new Ephemeris())->chart($jd, 23.18, 75.78)['planets'] as $p) $sign[$p['name']] = Zodiac::signOf($p['lon']);  // Ujjain; only signs are used
        $out = [];
        for ($r = 0; $r < 12; $r++) $out[] = $this->rashi($r, $period, $sign);
        return ['period' => $period, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'rashis' => $out];
    }

    private function rashi(int $r, string $period, array $sign): array {
        $h = []; $good = [];
        foreach ($sign as $pl => $s) { $h[$pl] = ($s - $r + 12) % 12 + 1; $good[$pl] = in_array($h[$pl], self::GOCHAR_GOOD[$pl], true); }
        $pn = $this->t("pred.period.$period"); $lines = []; $areas = []; $total = 0; $troubled = [];
        foreach (self::AREAS[$period] as $area => $pls) {
            $sc = 0; foreach ($pls as $i => $pl) $sc += ($good[$pl] ? 1 : -1) * ($i === 0 ? 2 : 1);   // first planet weighs most
            $tone = $sc > 0 ? 'good' : ($sc < 0 ? 'bad' : 'mixed'); $total += $sc;
            // name the planet behind the result; prefer a slow planet over the Moon, which has its own line
            $order = array_merge(array_diff($pls, ['Moon']), in_array('Moon', $pls, true) ? ['Moon'] : []);
            $lead = $order[0]; foreach ($order as $pl) if (($tone === 'good') === $good[$pl]) { $lead = $pl; break; }
            $areas[$area] = $tone; if ($tone === 'bad') $troubled[] = $lead;
            $lines[] = ['area' => $area, 'tone' => $tone, 'text' => $this->t("rf.$area.$tone", ['planet' => $this->pn($lead), 'h' => $h[$lead], 'theme' => $this->t("interp.house_short.{$h[$lead]}"), 'period' => $pn])];
        }
        $ov = $total >= 3 ? 'good' : ($total <= -3 ? 'bad' : 'mixed');
        array_unshift($lines, ['area' => 'overall', 'tone' => $ov, 'text' => $this->t("rf.overall.$ov", ['period' => $pn])]);
        if ($period === 'daily') {
            $mh = $h['Moon'];
            $lines[] = ['area' => 'moon', 'tone' => $good['Moon'] ? 'good' : 'bad', 'text' => $this->t('rf.moon', ['h' => $mh, 'theme' => $this->t("interp.house_short.$mh")])
                . ($mh === 8 ? ' ' . $this->t('rf.chandrashtama') : '')];
        }
        if ($period === 'monthly' && in_array($h['Saturn'], [12, 1, 2], true)) $lines[] = ['area' => 'caution', 'tone' => 'bad', 'text' => $this->t('rf.sadesati')];
        // lucky = the best-placed benefic influence now; remedy = for the most troubling planet
        $rank = ['Jupiter', 'Venus', 'Mercury', 'Moon', 'Sun', 'Mars', 'Saturn', 'Rahu', 'Ketu'];
        $best = 'Jupiter'; foreach ($rank as $pl) if ($good[$pl]) { $best = $pl; break; }
        $worst = $troubled[0] ?? 'Saturn';
        if (!$troubled) foreach (['Saturn', 'Rahu', 'Mars', 'Sun', 'Ketu'] as $pl) if (!$good[$pl]) { $worst = $pl; break; }
        $lucky = ['color' => explode(', ', $this->c("color.$best"))[0], 'hex' => self::HEX[$best], 'num' => self::NUM[$best],
            'day' => $this->t('astro.weekdays.' . Zodiac::WEEKDAYS[self::DAY[$best]])];
        $remedy = ['planet' => $this->pn($worst), 'mantra' => $this->lang === 'gu' ? \App\I18n\Lang::gu(self::MANTRA[$worst]) : self::MANTRA[$worst]];
        $lines[] = ['area' => 'lucky', 'tone' => 'good', 'text' => $this->t('rf.lucky', $lucky)];
        $lines[] = ['area' => 'remedy', 'tone' => 'mixed', 'text' => $this->t('rf.remedy', $remedy)];
        $name = Zodiac::SIGNS[$r];
        return ['sign' => $r, 'key' => $name, 'name' => $this->t("astro.signs.$name"), 'icon' => self::ICON[$r], 'overall' => $ov,
                'score' => max(1, min(5, 3 + intdiv($total, 2))), 'areas' => $areas, 'lines' => $lines, 'lucky' => $lucky, 'remedy' => $remedy];
    }
}
