<?php
namespace App\Interp;

use App\Calc\{Dasha, KundaliService};

/**
 * "AI Astrologer" chat powered by Claude (Haiku 4.5 by default) through the official Anthropic PHP SDK
 * (bundled in vendor/, since Git deploys don't run Composer).
 * Claude gets a fact sheet of this kundali (chart, dasha, Lal Kitab results and remedies from the books) and one
 * tool, kundali_reading, which runs the site's own rule engines (predictions, best months, remedies, doshas).
 * It is told to answer only from those facts. The rule-based ChatBot stays separate and unchanged.
 */
final class ClaudeChat extends RuleEngine {
    public const DEFAULT_MODEL = 'claude-haiku-4-5';
    private const LANGS = ['en' => 'English', 'hi' => 'Hindi', 'gu' => 'Gujarati'];
    public static string $lastError = '';

    /** Facts are built in English (the engine's own wording is most precise there); $ui is the site language. */
    /** $chat = the chat's own language picker: en / hi / gu, or auto (reply in the language of each message). */
    public function __construct(private string $ui, private float $lat = 0, private float $lon = 0, private string $chat = 'auto') { parent::__construct('en'); }
    private function ui(string $key): string { return \App\I18n\Lang::t($this->ui, $key); }

    public static function enabled(): bool { return trim((string) (app_config()['claude']['key'] ?? '')) !== ''; }

    private static function client(): \Anthropic\Client {
        require_once __DIR__ . '/../../vendor/autoload.php';
        $c = app_config()['claude'];
        return new \Anthropic\Client(apiKey: trim((string) $c['key']), baseUrl: $c['url'] ?? null);   // url: only for local testing
    }
    private static function model(): string { return (string) (app_config()['claude']['model'] ?? self::DEFAULT_MODEL); }

    /** Admin check: key set, and a tiny request succeeds. */
    public static function status(): array {
        $out = ['key_set' => self::enabled(), 'model' => self::model(), 'ok' => false, 'error' => ''];
        if (!$out['key_set']) { $out['error'] = "No key: add 'claude' => ['key' => ...] to astro-config.php"; return $out; }
        try {
            $m = self::client()->messages->create(model: self::model(), maxTokens: 20, messages: [['role' => 'user', 'content' => 'Reply with the single word OK.']]);
            foreach ($m->content as $b) if ($b->type === 'text') $out['ok'] = true;
        } catch (\Throwable $e) { $out['error'] = self::why($e); }
        return $out;
    }

    private static function why(\Throwable $e): string {
        $t = $e instanceof \Anthropic\Core\Exceptions\APIStatusException ? ($e->type?->value ?? 'api_error') . ': ' : '';
        return mb_substr($t . $e->getMessage(), 0, 300);
    }

    /**
     * @param array $history [['me' => bool, 'text' => string], ...] previous turns, oldest first
     * @return array ['reply' => string[], 'engine' => 'claude'] or ['reply' => [...], 'engine' => 'error', 'why' => ...]
     */
    public function reply(array $k, array $p, string $msg, array $history, \DateTimeImmutable $now): array {
        $bot = new ChatBot('en', $this->lat, $this->lon);            // facts in English; Claude answers in the user's language
        $system = [
            ['type' => 'text', 'text' => $this->persona()],
            ['type' => 'text', 'text' => $this->facts($k, $p, $now), 'cacheControl' => ['type' => 'ephemeral']],
        ];
        $tools = [[
            'name' => 'kundali_reading',
            'description' => "Runs this website's astrology engine on the client's kundali and returns its reading as plain sentences. "
                . 'Use it for any prediction, timing, remedy, dasha, dosha or pooja question. Call it again for another topic or period if needed.',
            'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['topic', 'ask', 'period', 'next'],
                'properties' => [
                    'topic' => ['type' => 'string', 'enum' => $bot->topics(), 'description' => 'Life area (career, love, marriage, finance, stock, health, child, property, business, travel, education, legal, family, ...) or dasha / remedy / pooja / dosha / chart.'],
                    'ask' => ['type' => 'string', 'enum' => ['general', 'when', 'why', 'should', 'will', 'upay'], 'description' => 'when = best months in the next 12 months; why = reasons; upay = remedies; should / will = yes-no style; general = outlook.'],
                    'period' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'yearly', 'lifetime']],
                    'next' => ['type' => 'boolean', 'description' => 'true for the next day / week / month / year instead of the current one'],
                ]],
            'strict' => true,
        ]];
        $messages = [];
        foreach (array_slice($history, -10) as $h) {
            $t = trim(mb_substr((string) ($h['text'] ?? ''), 0, 1500)); if ($t === '') continue;
            $role = !empty($h['me']) ? 'user' : 'assistant';
            if ($messages && end($messages)['role'] === $role) { $messages[count($messages) - 1]['content'] .= "\n" . $t; continue; }
            if (!$messages && $role === 'assistant') continue;           // must start with the user
            $messages[] = ['role' => $role, 'content' => $t];
        }
        if ($messages && end($messages)['role'] === 'user') array_pop($messages);   // unanswered turn; the new message replaces it
        $messages[] = ['role' => 'user', 'content' => $msg];

        try {
            $client = self::client(); $facts = [];
            for ($i = 0; $i < 4; $i++) {
                $r = $client->messages->create(model: self::model(), maxTokens: 2048, temperature: 0.4, system: $system, tools: $tools, messages: $messages);
                if ($r->stopReason !== 'tool_use') break;
                $results = [];
                foreach ($r->content as $b) if ($b->type === 'tool_use') {
                    $in = (array) $b->input;
                    $lines = $bot->reply($k, '', [], $now, (string) $p['label'], ['topic' => $in['topic'] ?? null, 'ask' => $in['ask'] ?? 'general',
                        'period' => $in['period'] ?? 'monthly', 'offset' => !empty($in['next']) ? 1 : 0])['reply'];
                    $facts = array_merge($facts, $lines);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $b->id, 'content' => implode("\n", $lines)];
                }
                $messages[] = ['role' => 'assistant', 'content' => $r->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
            if ($r->stopReason === 'refusal') return ['reply' => [$this->ui('chat.unknown')], 'engine' => 'error', 'why' => 'refusal'];
            $text = ''; foreach ($r->content as $b) if ($b->type === 'text') $text .= $b->text;
            // safety net: strip any markdown the model still used
            $text = preg_replace(['/\*\*|__|`/u', '/^\s{0,3}#{1,6}\s*/mu', '/^\s*[-*]\s+/mu'], ['', '', '• '], $text);
            $paras = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/u', trim($text))), 'strlen'));
            if (!$paras) return ['reply' => [$this->ui('chat.unknown')], 'engine' => 'error', 'why' => 'empty reply (' . $r->stopReason . ')'];
            return ['reply' => array_slice($paras, 0, 10), 'engine' => 'claude'];
        } catch (\Throwable $e) {
            self::$lastError = self::why($e); error_log('[claude] ' . self::$lastError);
            return ['reply' => [$this->ui('chat.ai_busy')], 'engine' => 'error', 'why' => self::$lastError];
        }
    }

    private function persona(): string {
        return "You are Acharya of GrahaSetu, a senior astrologer with more than 10 years of daily practice in Vedic (Parashari) and Lal Kitab astrology, "
            . "chatting with a client about their own kundali. You speak the way a seasoned, trusted family astrologer does: calm, confident, kind and practical. "
            . "You read the chart before you speak, name the planet and house behind what you say, relate it to the client's real life, and end with clear guidance.\n"
            . "How you work:\n"
            . "- Everything you say must come from the KUNDALI FACTS below or from kundali_reading results. Never invent planets, houses, dates, months, years, dasha periods or remedies.\n"
            . "- For predictions, timing, remedies, dasha, doshas or poojas, call kundali_reading first (more than once if the question spans topics or periods) and base the answer on it.\n"
            . "- Answer the actual question in the first sentence, then give the astrological reason in simple words, then practical advice; offer at most 2-3 remedies, taken from the facts.\n"
            . "- If the chart doesn't settle something (an exact count, a guaranteed yes/no, another person's chart), say so honestly as an experienced astrologer would, and share what the chart does indicate.\n"
            . "- Personal or sensitive topics (relationships, intimacy, health): answer with maturity and dignity, from the relevant houses and planets, without explicit detail.\n"
            . "- Keep replies short and conversational: about 3-6 sentences. Ask one gentle follow-up question only when it truly helps.\n"
            . "- Plain text only: no markdown at all (no **, no #, no tables). Separate paragraphs with a blank line; use '• ' for a short list.\n"
            . $this->languageRule()
            . "- For health, legal or money decisions add a gentle reminder to also consult a professional. Never predict death or frighten the client.";
    }

    /** Reply language from the chat's picker; facts are English, so give the model the site's own terms. */
    private function languageRule(): string {
        if ($this->chat === 'en') return "- Always reply in simple English, whatever language the client writes in. Terms like dasha, upay, Lal Kitab are fine.\n";
        if ($this->chat === 'auto')
            return "- Reply in the language of the client's latest message: English gets English; Gujarati or Hindi (in their own script or typed in English letters, e.g. 'kyare saro samay che') gets a reply in that language written in its own script. "
                . "In Gujarati or Hindi replies do not mix in English words.\n- Gujarati terms: " . $this->glossary('gu') . "\n- Hindi terms: " . $this->glossary('hi') . "\n";
        $L = self::LANGS[$this->chat];
        return "- Always reply in natural, simple $L written in $L script, even if the client writes in English or English letters. "
            . "Do not mix in English words: translate every term, and write dates with month names in $L. Only people's and place names may stay as they are.\n"
            . "- Use these $L terms: " . $this->glossary($this->chat) . "\n";
    }

    private function glossary(string $l): string {
        $g = [];
        foreach (['planets', 'signs'] as $grp) foreach (\App\I18n\Lang::all('en')['astro'][$grp] as $k => $v) $g[] = "$v = " . \App\I18n\Lang::t($l, "astro.$grp.$k");
        return implode(', ', $g) . ', ' . ($l === 'gu'
            ? 'kundali = કુંડળી, ascendant/lagna = લગ્ન, house = ભાવ/ઘર, Moon sign = ચંદ્ર રાશિ, nakshatra = નક્ષત્ર, mahadasha = મહાદશા, antardasha = અંતરદશા, Mangal dosha = મંગળ દોષ, Kaal sarp = કાલસર્પ, Sade Sati = સાડાસાતી, remedy = ઉપાય, Lal Kitab = લાલ કિતાબ, Vedic = વૈદિક, benefic = શુભ, malefic = અશુભ, exalted = ઉચ્ચ, debilitated = નીચ, retrograde = વક્રી, career = કારકિર્દી, astrology = જ્યોતિષ'
            : 'kundali = कुंडली, ascendant/lagna = लग्न, house = भाव/घर, Moon sign = चंद्र राशि, nakshatra = नक्षत्र, mahadasha = महादशा, antardasha = अंतरदशा, Mangal dosha = मांगलिक दोष, Kaal sarp = कालसर्प, Sade Sati = साढ़ेसाती, remedy = उपाय, Lal Kitab = लाल किताब, Vedic = वैदिक, benefic = शुभ, malefic = अशुभ, exalted = उच्च, debilitated = नीच, retrograde = वक्री, career = करियर, astrology = ज्योतिष');
    }

    /** Compact English fact sheet of the chart, current dasha and Lal Kitab placements. */
    private function facts(array $k, array $p, \DateTimeImmutable $now): string {
        $by = $this->by($k); $jd = $now->getTimestamp() / 86400 + 2440587.5; $moon = $by['Moon'];
        $s = "KUNDALI FACTS (today is " . $now->format('Y-m-d') . ")\n"
            . "Name: {$p['label']}; born {$p['birth_date']} {$p['birth_time']} at {$p['place_name']}.\n"
            . "Lagna (ascendant): {$k['lagna']['sign_name']}. Moon sign (rashi): {$moon['sign_name']}, nakshatra {$moon['nakshatra']} pada {$moon['pada']}. Sun sign: {$by['Sun']['sign_name']}.\n\nPlanets (house counted from lagna):\n";
        foreach ($k['planets'] as $x)
            $s .= "- {$x['name']}: {$x['sign_name']}, house {$x['house']}" . ($x['dignity'] ? ", {$x['dignity']}" : '') . (!empty($x['retrograde']) && !in_array($x['name'], ['Rahu', 'Ketu'], true) ? ', retrograde' : '') . (!empty($x['combust']) ? ', combust' : '') . "\n";
        $day = fn($j) => gmdate('Y-m-d', (int) (($j - 2440587.5) * 86400));
        if ($d = Dasha::current(['mahadasha' => $k['dasha']['mahadasha']], $jd))
            $s .= "\nCurrent Vimshottari dasha: {$d['mahadasha']} mahadasha until " . $day($d['md_end_jd']) . ", {$d['antardasha']} antardasha until " . $day($d['ad_end_jd']) . ".\n";
        $a = $k['analysis'];
        $s .= "Mangal dosha: " . (!empty($a['mangal_dosha']['present']) ? 'present' : 'not present') . ". Kaal sarp: " . (!empty($a['kaal_sarp']['present']) ? 'present' : 'not present') . ".\n";
        try {
            $tr = (new KundaliService())->transits($k, $jd, $this->lat, $this->lon)['derived'];
            $s .= 'Sade Sati now: ' . ($tr['sade_sati']['active'] ? 'yes (' . $tr['sade_sati']['phase'] . ')' : 'no') . '. Shani dhaiya now: ' . ($tr['shani_dhaiya']['active'] ? 'yes' : 'no') . ".\n";
        } catch (\Throwable $e) {}
        $s .= "\nLal Kitab reading of each planet (from the Lal Kitab books):\n";
        foreach (array_keys($by) as $pl) {
            $L = $this->lk($k, $pl); $C = $this->lkCombos($k, $pl);
            $s .= "- $pl in house {$L['house']}: " . ($L['benefic'] ? 'benefic' : 'malefic') . '. ' . $L['effect']
                . ($C['g'] ? ' Also: ' . implode(' ', array_slice($C['g'], 0, 2)) : '') . ($C['c'] ? ' Caution: ' . implode(' ', array_slice($C['c'], 0, 2)) : '')
                . ($L['remedies'] ? ' Remedies: ' . implode(' | ', array_slice($L['remedies'], 0, 3)) : '') . "\n";
        }
        return $s;
    }
}
