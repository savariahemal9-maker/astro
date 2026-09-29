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
    private const BUILTIN = ['child' => ['Jupiter', '5,9', 'child_care'], 'marriage' => ['Venus,Jupiter', '7,2', 'diversity_1'], 'property' => ['Mars,Saturn', '4,11', 'home'],
        'business' => ['Mercury,Jupiter', '7,10,11', 'storefront'], 'legal' => ['Saturn,Mars', '6,7', 'gavel'], 'family' => ['Moon,Sun', '2,4,9', 'family_restroom']];
    private const TOPICS = [
        'stock' => 'stock|share|market|trading|invest|crypto|शेयर|निवेश|શેર|રોકાણ',
        'love' => 'propose|prapose|gf|bf|girlfriend|boyfriend|crush|prem|pyar|pyaar|love|partner|relationship|प्रेम|प्यार|પ્રેમ|સંબંધ',
        'finance' => 'paisa|paise|rupiya|kamai|dhan|money|finance|wealth|income|saving|loan|debt|धन|पैसा|आय|कर्ज|ધન|પૈસ|આવક|લોન|દેવું',
        'sports' => 'sport|cricket|match|game|खेल|રમત',
        'health' => 'tabiyat|tabiyet|bimar|bimari|swasthya|health|disease|illness|sick|fitness|स्वास्थ्य|सेहत|बीमारी|સ્વાસ્થ્ય|તબિયત|બીમારી',
        'education' => 'bhanvu|bhanva|padhai|pariksha|study|exam|education|school|college|पढ़ाई|परीक्षा|शिक्षा|અભ્યાસ|પરીક્ષા|શિક્ષણ|ભણ',
        'travel' => 'videsh|pravas|bahar jav|travel|abroad|foreign|visa|journey|यात्रा|विदेश|પ્રવાસ|વિદેશ|મુસાફરી',
        'child' => 'baby|child|children|kid|santan|santaan|bachcha|bacha|balak|dikro|dikri|pregnan|garbh|संतान|बच्चा|गर्भ|સંતાન|બાળક|દીકરો|દીકરી|ગર્ભ',
        'marriage' => 'marriage|marry|wedding|lagan|lagn|shadi|shaadi|vivah|sagai|engagement|शादी|विवाह|लग्न|सगाई|લગ્ન|સગાઈ|વિવાહ',
        'property' => 'property|house|home|flat|land|plot|makan|ghar|jamin|zameen|vehicle|car|gadi|मकान|घर|ज़मीन|वाहन|મકાન|ઘર|જમીન|વાહન|ગાડી',
        'business' => 'business|startup|shop|dukan|dhandho|dhando|vepar|vyapar|partnership|व्यापार|दुकान|ધંધ|વેપાર|દુકાન',
        'legal' => 'court|case|legal|kes|kesh|dispute|police|कोर्ट|मुकदमा|કોર્ટ|કેસ',
        'family' => 'family|parent|mother|father|mummy|papa|mata|pita|parivar|ghar ma|परिवार|माता|पिता|પરિવાર|માતા|પિતા',
        'career' => 'naukri|nokri|nokari|dhandho|dhando|vepar|vyapar|business|kaam|kam |job|career|work|office|promotion|business|naukri|नौकरी|करियर|काम|व्यापार|નોકરી|કારકિર્દી|કામ|ધંધ|વ્યવસાય',
        'dasha' => 'mahadasha|antardasha|dasha|dasa|period|mahadasha|दशा|દશા',
        'remedy' => 'upay|upaay|totka|remed|upay|upaay|solution|mantra|उपाय|मंत्र|ઉપાય|મંત્ર',
        'pooja' => 'pooja|puja|worship|पूजा|પૂજા',
        'dosha' => 'panoti|dhaiya|manglik|dosha|dosh|mangal|manglik|kaal sarp|sade sati|sadesati|दोष|मांगलिक|साढ़ेसाती|દોષ|માંગલિક|સાડાસાતી',
        'chart' => 'lagna|ascendant|rashi|moon sign|nakshatra|chart|kundali|लग्न|राशि|नक्षत्र|कुंडली|લગ્ન|રાશિ|નક્ષત્ર|કુંડળી',
        'greet' => '^(hi|hello|hey|namaste|namaskar|jai shree krishna|kem cho)\b|नमस्ते|नमस्कार|નમસ્તે|કેમ છો|જય શ્રી કૃષ્ણ',
        'thanks' => 'thank|thanks|dhanyavad|धन्यवाद|शुक्रिया|આભાર',
    ];
    private const PERIODS = [
        'daily' => 'aaje|aaj|aavti kale|kale |today|tomorrow|aaj|kal|आज|कल|આજે|આવતીકાલે|કાલે',
        'weekly' => 'athvadiye|athvadiyu|athvadiya|hafte|hafta|week|सप्ताह|हफ्ते|હફ્તે|અઠવાડિ',
        'monthly' => 'mahine|mahino|mahina|mahinama|month|महीन|मास|મહિન|માસ',
        'yearly' => 'varshe|varas|varsh|saal|sal |year|साल|वर्ष|વર્ષ|સાલ',
        'lifetime' => 'jivan|jindagi|zindagi|life|lifetime|future|जीवन|भविष्य|જીવન|ભવિષ્ય',
    ];

    private const ASK = [
        'when' => 'when|kyare|kyarey|kab |kab$|kab\\?|best time|sahi samay|ક્યારે|कब',
        'why' => '^why|^kem|^kyu|^kyon|reason|karan|કેમ|क्यों|कारण|કારણ|details|vigat|विस्तार|વિગત',
        'should' => 'should|joie|joiye|joi e|chahiye|karu ke|karvu|karun|જોઈએ|चाहिए|કરું|करूँ|करना',
        'will' => 'will i|will my|thase|thashe|thay|milse|malse|hoga|hogi|milega|milegi|થશે|મળશે|होगा|होगी|मिलेगा|मिलेगी',
        'yes' => '^(ha|haa|han|haan|ho|hmm|yes|yeah|ok|okay|sure|bolo|kaho|kahe|jarur|jaroor|હા|હાં|હો|હમ|हाँ|हां|जी|ठीक|बताओ|બતાવો|કહો)[\\s!.?]*$',
        'no' => '^(na|naa|nahi|nai|no|nope|ના|નહીં|नहीं|ना)[\\s!.?]*$',
        'upay' => '^(upay|upaay|remedy|remedies|ઉપાય|उपाय)\\??$',
    ];
    private string $who = '';

    public function __construct(string $lang, private float $lat = 0, private float $lon = 0) { parent::__construct($lang); }
    /** Pick a phrasing variant, stable for the same message so answers don't flicker. */
    private function v(string $key, array $vars, string $seed): string {
        $opts = explode('|', $this->t("chat.$key", $vars + ['name' => $this->who]));
        return trim($opts[crc32($seed . $key) % count($opts)]);
    }

    /** @param array $ctx previous turn's ['topic' => ..., 'period' => ..., 'offset' => ...] */
    public function reply(array $k, string $msg, array $ctx, \DateTimeImmutable $now, string $who = ''): array {
        $m = mb_strtolower(trim($msg)); $this->who = $who;
        $ask = $this->cb_match(self::ASK, $m);
        $topic = $this->cb_match(self::TOPICS, $m); $period = $this->cb_match(self::PERIODS, $m);
        if ($ask === 'no') return ['reply' => [$this->t('chat.ok_no')], 'context' => $ctx, 'quick' => $this->quick(null)];
        if ($ask === 'yes' && !empty($ctx['offer'])) { $ask = $ctx['offer']; $m = $ctx['offer']; }
        // "why?" / "upay?" alone refer to the previous topic
        if (!$topic && in_array($ask, ['why', 'upay', 'when'], true) && !empty($ctx['topic'])) $topic = $ctx['topic'];
        // "upay?" right after a topic question means remedies for that topic
        $cats = ['career', 'love', 'finance', 'stock', 'sports', 'health', 'education', 'travel'];
        if ($ask === 'upay' && in_array($ctx['topic'] ?? '', $cats, true)) $topic = $ctx['topic'];
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
            $topic !== null => $this->cb_category($k, $topic, $period, $at, $ask, $m, $now),
            default => [$this->t('chat.unknown'), $this->t('chat.help')],
        };
        $isCat = $topic && ($this->isCat($topic));
        $offer = $isCat ? ($ask === 'why' ? 'upay' : ($ask === 'upay' ? 'when' : 'why')) : null;
        return ['reply' => array_values(array_filter($lines)), 'context' => ['topic' => in_array($topic, ['greet', 'thanks'], true) ? ($ctx['topic'] ?? null) : $topic, 'period' => $period, 'offer' => $offer],
                'quick' => $this->quick($isCat ? $topic : null, $ask), 'suggestions' => $this->cb_suggest($topic)];
    }

    private function isCat(string $t): bool { return isset(self::BUILTIN[$t]) || in_array($t, ['career', 'love', 'finance', 'stock', 'sports', 'health', 'education', 'travel'], true); }
    /** Buttons that fit the answer just given. */
    private function quick(?string $topic, ?string $ask = null): array {
        if (!$topic) return array_map(fn($x) => $this->t("chat.q.$x"), ['career', 'marriage', 'finance', 'child', 'dasha']);
        return array_map(fn($x) => $this->t("chat.qr.$x"), array_values(array_diff(['why', 'upay', 'when', 'next'], [$ask ?? ''])));
    }

    private function cb_match(array $map, string $m): ?string { foreach ($map as $key => $re) if (preg_match("/($re)/u", $m)) return $key; return null; }

    private function cb_shift(\DateTimeImmutable $d, string $period, int $n): \DateTimeImmutable {
        if (!$n) return $d;
        return $d->modify(['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => 'first day of next month', 'yearly' => '+1 year'][$period] ?? '+0 day');
    }

    private function cb_category(array $k, string $slug, string $period, \DateTimeImmutable $at, ?string $ask, string $seed, \DateTimeImmutable $now): array {
        $cat = Db::one('SELECT * FROM prediction_categories WHERE slug=? AND active=1', [$slug]);
        if (!$cat && isset(self::BUILTIN[$slug])) { [$pl, $hs, $ic] = self::BUILTIN[$slug]; $n = $this->t("chat.topic.$slug");
            $cat = ['id' => 0, 'slug' => $slug, 'name_en' => $n, 'name_hi' => $n, 'name_gu' => $n, 'icon' => $ic, 'planets' => $pl, 'houses' => $hs, 'caution' => 0]; }
        if (!$cat) return [$this->t('chat.unknown')];
        $cp = new CategoryPredictor($this->lang); $name = $cat['name_' . $this->lang] ?: $cat['name_en'];
        if ($ask === 'when') {                       // best months in the coming year, from month-by-month scores
            $rows = [];
            for ($i = 0; $i < 12; $i++) { $d = $now->modify("first day of +$i month"); $r = $cp->predict($k, $cat, 'monthly', $d, $this->lat, $this->lon); $rows[] = [$d, $r['score']]; }
            usort($rows, fn($x, $y) => $y[1] <=> $x[1]); $mo = fn($d) => explode('|', $this->t('chat.months'))[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
            $out = [$this->v('when', ['cat' => $name, 'm1' => $mo($rows[0][0]), 'm2' => $mo($rows[1][0]), 's1' => $rows[0][1]], $seed)];
            $out[] = $this->t('chat.when_avoid', ['m' => $mo(end($rows)[0])]);
            $out[] = $this->v('follow_when', ['cat' => $name], $seed);
            return $out;
        }
        $d = $cp->predict($k, $cat, $period, $at, $this->lat, $this->lon); $lvl = $d['level'];
        $pn = $this->t("pred.period.$period");
        if ($ask === 'why') {
            $out = [$this->v('why_intro', ['cat' => $name, 'period' => $pn], $seed)];
            foreach (array_slice($d['details']['positive'], 0, 2) as $x) $out[] = '✅ ' . $x['effect'];
            foreach (array_slice($d['details']['challenging'], 0, 2) as $x) $out[] = '⚠️ ' . $x['effect'];
            return $out;
        }
        if ($ask === 'upay') return array_merge([$this->v('upay_intro', ['cat' => $name], $seed)], array_map(fn($x) => '🌿 ' . $x, array_slice($d['upay'], 0, 3)));
        // short verdict first, like an astrologer would say it
        $verdict = $this->v(($ask === 'should' ? 'should_' : ($ask === 'will' ? 'will_' : 'gen_')) . $lvl, ['cat' => $name, 'period' => $pn], $seed);
        $key = $lvl === 'challenging' ? ($d['details']['challenging'][0]['effect'] ?? '') : ($d['details']['positive'][0]['effect'] ?? '');
        $out = [$verdict, $key, $this->v('follow', ['cat' => $name], $seed)];
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
