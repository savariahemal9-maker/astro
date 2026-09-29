<?php
namespace App\Interp;

use App\Calc\KundaliService;
use App\Core\Db;

/**
 * Free, rule-based astrology chat. It understands a question by keywords (English / Hindi / Gujarati),
 * answers only from this kundali's calculated data and the site's own rule engines, and keeps the
 * topic and period of the conversation so follow-ups like "and next month?" work. No external AI.
 */
final class ChatBot extends RuleEngine {
    // topic => keywords (lower-case, any language); category topics map to prediction_categories.slug
    private const TOPICS = [
        'career' => 'job|career|work|office|promotion|business|naukri|नौकरी|करियर|काम|व्यापार|નોકરી|કારકિર્દી|કામ|ધંધ|વ્યવસાય',
        'love' => 'love|marriage|marry|partner|wife|husband|relationship|प्रेम|प्यार|शादी|विवाह|पति|पत्नी|પ્રેમ|લગ્ન|પતિ|પત્ની|સંબંધ',
        'finance' => 'money|finance|wealth|income|saving|loan|debt|धन|पैसा|आय|कर्ज|ધન|પૈસ|આવક|લોન|દેવું',
        'stock' => 'stock|share|market|trading|invest|crypto|शेयर|निवेश|શેર|રોકાણ',
        'sports' => 'sport|cricket|match|game|खेल|રમત',
        'health' => 'health|disease|illness|sick|fitness|स्वास्थ्य|सेहत|बीमारी|સ્વાસ્થ્ય|તબિયત|બીમારી',
        'education' => 'study|exam|education|school|college|पढ़ाई|परीक्षा|शिक्षा|અભ્યાસ|પરીક્ષા|શિક્ષણ|ભણ',
        'travel' => 'travel|abroad|foreign|visa|journey|यात्रा|विदेश|પ્રવાસ|વિદેશ|મુસાફરી',
        'dasha' => 'dasha|dasa|period|mahadasha|दशा|દશા',
        'remedy' => 'remed|upay|upaay|solution|mantra|उपाय|मंत्र|ઉપાય|મંત્ર',
        'pooja' => 'pooja|puja|worship|पूजा|પૂજા',
        'dosha' => 'dosha|dosh|mangal|manglik|kaal sarp|sade sati|sadesati|दोष|मांगलिक|साढ़ेसाती|દોષ|માંગલિક|સાડાસાતી',
        'chart' => 'lagna|ascendant|rashi|moon sign|nakshatra|chart|kundali|लग्न|राशि|नक्षत्र|कुंडली|લગ્ન|રાશિ|નક્ષત્ર|કુંડળી',
        'greet' => '^(hi|hello|hey|namaste|namaskar|jai shree krishna|kem cho)\b|नमस्ते|नमस्कार|નમસ્તે|કેમ છો|જય શ્રી કૃષ્ણ',
        'thanks' => 'thank|thanks|dhanyavad|धन्यवाद|शुक्रिया|આભાર',
    ];
    private const PERIODS = [
        'daily' => 'today|tomorrow|aaj|kal|आज|कल|આજે|આવતીકાલે|કાલે',
        'weekly' => 'week|सप्ताह|हफ्ते|હફ્તે|અઠવાડિ',
        'monthly' => 'month|महीन|मास|મહિન|માસ',
        'yearly' => 'year|साल|वर्ष|વર્ષ|સાલ',
        'lifetime' => 'life|lifetime|future|जीवन|भविष्य|જીવન|ભવિષ્ય',
    ];

    public function __construct(string $lang, private float $lat = 0, private float $lon = 0) { parent::__construct($lang); }

    /** @param array $ctx previous turn's ['topic' => ..., 'period' => ..., 'offset' => ...] */
    public function reply(array $k, string $msg, array $ctx, \DateTimeImmutable $now): array {
        $m = mb_strtolower(trim($msg));
        $topic = $this->cb_match(self::TOPICS, $m); $period = $this->cb_match(self::PERIODS, $m);
        $offset = preg_match('/next|agle|अगले|अगला|આવતા|આવતું|આગામી|tomorrow|कल|આવતીકાલે/u', $m) ? 1 : 0;
        // follow-up like "and next month?" keeps the previous topic
        if (!$topic && ($period || $offset) && !empty($ctx['topic'])) $topic = $ctx['topic'];
        if ($topic && !$period && !empty($ctx['period']) && $topic === ($ctx['topic'] ?? null)) $period = $ctx['period'];
        $period ??= 'monthly';
        $at = $this->cb_shift($now, $period, $offset);
        $lines = match (true) {
            $topic === 'greet' => [$this->t('chat.greet', ['name' => '']), $this->t('chat.help')],
            $topic === 'thanks' => [$this->t('chat.thanks')],
            $topic === 'dasha' => $this->cb_dasha($k, $at),
            $topic === 'remedy' => $this->cb_remedies($k, $at),
            $topic === 'pooja' => $this->cb_poojas($k, $at),
            $topic === 'dosha' => $this->cb_doshas($k, $at),
            $topic === 'chart' => $this->cb_chart($k),
            $topic !== null => $this->cb_category($k, $topic, $period, $at),
            default => [$this->t('chat.unknown'), $this->t('chat.help')],
        };
        return ['reply' => array_values(array_filter($lines)), 'context' => ['topic' => in_array($topic, ['greet', 'thanks'], true) ? ($ctx['topic'] ?? null) : $topic, 'period' => $period],
                'suggestions' => $this->cb_suggest($topic)];
    }

    private function cb_match(array $map, string $m): ?string { foreach ($map as $key => $re) if (preg_match("/($re)/u", $m)) return $key; return null; }

    private function cb_shift(\DateTimeImmutable $d, string $period, int $n): \DateTimeImmutable {
        if (!$n) return $d;
        return $d->modify(['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => 'first day of next month', 'yearly' => '+1 year'][$period] ?? '+0 day');
    }

    private function cb_category(array $k, string $slug, string $period, \DateTimeImmutable $at): array {
        $cat = Db::one('SELECT * FROM prediction_categories WHERE slug=? AND active=1', [$slug]);
        if (!$cat) return [$this->t('chat.unknown')];
        $d = (new CategoryPredictor($this->lang))->predict($k, $cat, $period, $at, $this->lat, $this->lon);
        $when = $d['range']['date'] ?? (isset($d['range']['start']) ? $d['range']['start'] . ' → ' . $d['range']['end'] : ($d['range']['year'] ?? ''));
        $out = [$d['headline'] . ($when ? " ($when)" : '') . ' — ' . $this->t('chat.score', ['s' => $d['score']]), $d['explanation']];
        if ($d['details']['positive']) $out[] = $this->t('chat.because_good') . ' ' . $d['details']['positive'][0]['text'] . ' ' . $d['details']['positive'][0]['effect'];
        if ($d['details']['challenging']) $out[] = $this->t('chat.because_bad') . ' ' . $d['details']['challenging'][0]['text'] . ' ' . $d['details']['challenging'][0]['effect'];
        if ($d['do']) $out[] = $this->t('chat.do') . ' ' . implode(' ', array_slice($d['do'], 0, 2));
        if ($d['dont']) $out[] = $this->t('chat.dont') . ' ' . implode(' ', array_slice($d['dont'], 0, 2));
        if ($d['upay']) $out[] = $this->t('chat.upay') . ' ' . $d['upay'][0];
        if ($d['caution']) $out[] = $d['caution'];
        return $out;
    }

    private function cb_dasha(array $k, \DateTimeImmutable $at): array {
        $jd = $at->setTime(12, 0)->getTimestamp() / 86400 + 2440587.5;
        foreach ($k['dasha']['mahadasha'] as $md) if ($md['start_jd'] <= $jd && $jd < $md['end_jd']) {
            $ad = null; foreach ($md['antardasha'] ?? [] as $a) if ($a['start_jd'] <= $jd && $jd < $a['end_jd']) $ad = $a;
            $L = $this->lk($k, $md['lord']);
            $out = [$this->t('chat.dasha_now', ['md' => $this->pn($md['lord']), 'md_to' => $md['end'], 'ad' => $ad ? $this->pn($ad['lord']) : '—', 'ad_to' => $ad['end'] ?? '—']),
                    $this->t($L['benefic'] ? 'chat.dasha_good' : 'chat.dasha_bad', ['planet' => $this->pn($md['lord']), 'h' => $L['house']]) . ' ' . $L['effect']];
            if (!$L['benefic'] && $L['remedies']) $out[] = $this->t('chat.upay') . ' ' . $L['remedies'][0];
            return $out;
        }
        return [$this->t('chat.unknown')];
    }

    private function cb_remedies(array $k, \DateTimeImmutable $at): array {
        $items = (new RemedyPlanner($this->lang, $this->lat, $this->lon))->important($k, $at)['items'];
        $out = [$this->t('chat.remedy_intro')];
        foreach (array_slice($items, 0, 3) as $i => $r) $out[] = ($i + 1) . '. ' . $r['text'] . ' (' . $this->t('ui.why_this') . ': ' . $r['reason'] . ')';
        return $out;
    }

    private function cb_poojas(array $k, \DateTimeImmutable $at): array {
        $d = (new RemedyPlanner($this->lang, $this->lat, $this->lon))->poojas($k, $at);
        if (!$d['items']) return [$this->t('ui.no_pooja')];
        $out = [$this->t('chat.pooja_intro')];
        foreach (array_slice($d['items'], 0, 2) as $p) $out[] = '• ' . $p['name'] . ': ' . $p['why'] . ' ' . $p['timing'];
        $out[] = $d['note'];
        return $out;
    }

    private function cb_doshas(array $k, \DateTimeImmutable $at): array {
        $tr = (new KundaliService())->transits($k, $at->setTime(12, 0)->getTimestamp() / 86400 + 2440587.5, $this->lat, $this->lon);
        $present = array_filter((new Advanced($this->lang))->doshaReport($k, $tr)['items'], fn($d) => $d['present']);
        if (!$present) return [$this->t('chat.no_dosha')];
        return [$this->t('chat.dosha_list', ['list' => implode(', ', array_map(fn($d) => $d['name'], $present))]), $this->t('chat.dosha_more')];
    }

    private function cb_chart(array $k): array {
        $moon = $this->by($k)['Moon'];
        return [$this->t('chat.chart', ['lagna' => $this->t('astro.signs.' . $k['lagna']['sign_name']), 'rashi' => $this->t('astro.signs.' . $moon['sign_name']),
                'nak' => $this->t('astro.nakshatras.' . $moon['nakshatra'])])];
    }

    private function cb_suggest(?string $topic): array {
        $s = ['career', 'love', 'finance', 'health', 'dasha', 'remedy', 'pooja', 'dosha'];
        if ($topic && !in_array($topic, ['greet', 'thanks'], true)) array_unshift($s, 'next');
        return array_map(fn($x) => $this->t("chat.q.$x"), array_slice(array_values(array_diff($s, [$topic])), 0, 5));
    }
}
