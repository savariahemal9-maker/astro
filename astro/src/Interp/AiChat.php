<?php
namespace App\Interp;

use App\I18n\Lang;

/**
 * Optional AI layer for the chat (Google Gemini; the free tier is enough). The AI never predicts anything:
 *  1. understand(): turns a free-form question into an intent (topic / ask / period) for ChatBot,
 *  2. phrase(): rewrites ChatBot's answer in natural words.
 * A rewrite that names a planet, month or number not present in ChatBot's answer is thrown away.
 * Any error or missing key returns null, and the plain rule-based answer is shown instead.
 */
final class AiChat {
    private const ASKS = ['general', 'when', 'why', 'should', 'will', 'upay', 'no'];
    private const PERIODS = ['daily', 'weekly', 'monthly', 'yearly', 'lifetime'];
    private const LANGS = ['en' => 'English', 'hi' => 'Hindi', 'gu' => 'Gujarati'];
    private const PLANET_ROMAN = ['Sun' => 'surya|suraj|ravi', 'Moon' => 'chandra|chand', 'Mars' => 'mangal|kuja', 'Mercury' => 'budh|budha',
        'Jupiter' => 'guru|brihaspati', 'Venus' => 'shukra|sukra', 'Saturn' => 'shani|sani', 'Rahu' => 'rahu', 'Ketu' => 'ketu'];
    private const MONTHS_EN = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    public function __construct(private string $lang) {}

    public static function enabled(): bool { return trim((string) (app_config()['ai']['key'] ?? '')) !== ''; }

    /** @return ?array ['topic' => ?string, 'ask' => string, 'period' => ?string, 'offset' => int] */
    public function understand(string $msg, array $history, array $ctx, array $topics): ?array {
        $sys = "You read questions sent to a Vedic astrology chat and classify them. Users write English, Hindi, Gujarati, or Hindi/Gujarati in Latin letters "
            . "(e.g. 'kyare saro samay che' = when is a good time, 'dhan' = money, 'nokri' = job, 'lagan' = marriage, 'ha' = yes, 'na' = no).\n"
            . 'Return only JSON: {"topic": one of ' . json_encode($topics) . ' or null, "ask": one of ' . json_encode(self::ASKS)
            . ', "period": one of ' . json_encode(self::PERIODS) . " or null, \"offset\": 0 or 1}.\n"
            . "ask: when = best time / timing; why = reason or details; should = should I do it; will = will it happen; upay = remedies; no = user declines; general = anything else.\n"
            . "offset 1 = next day/week/month/year. Resolve follow-ups from the conversation: 'why?', 'and next month?', 'upay?' keep the previous topic. "
            . "A plain yes ('ha', 'yes', 'ok') means the user accepts what the assistant offered last: offered='" . ($ctx['offer'] ?? '') . "'.\n"
            . 'Previous topic: ' . ($ctx['topic'] ?? 'none') . '. Previous period: ' . ($ctx['period'] ?? 'none') . '.';
        $j = $this->call($sys, $this->convo($history) . "User: $msg", 0.0);
        if (!$j) return null;
        $topic = in_array($j['topic'] ?? null, $topics, true) ? $j['topic'] : null;
        $ask = in_array($j['ask'] ?? null, self::ASKS, true) ? $j['ask'] : 'general';
        $period = in_array($j['period'] ?? null, self::PERIODS, true) ? $j['period'] : null;
        return ['topic' => $topic, 'ask' => $ask, 'period' => $period, 'offset' => (int) (($j['offset'] ?? 0) == 1)];
    }

    /** @return ?string[] the answer as paragraphs, or null when the AI failed or added facts of its own */
    public function phrase(string $msg, array $history, array $facts): ?array {
        $sys = "You are a warm, experienced Vedic astrologer chatting with a client. Rewrite the FACTS into a natural, caring reply to the client's last message.\n"
            . "Strict rules:\n- Use only what is in FACTS. Never add a planet, house, sign, month, year, date, number, remedy or prediction that is not in FACTS.\n"
            . "- You may shorten, reorder and join the facts; keep every remedy's meaning exactly.\n"
            . "- If FACTS say the question cannot be answered, say so kindly and mention what they can ask.\n"
            . "- 2 to 5 short sentences, no headings or markdown; remedies may be separate short lines.\n"
            . '- Reply in the same language and script as the client\'s last message (default ' . self::LANGS[$this->lang] . ").\n"
            . 'Return only JSON: {"reply": ["paragraph", ...]}';
        $j = $this->call($sys, $this->convo($history) . "Client: $msg\n\nFACTS:\n" . implode("\n", $facts), 0.4);
        $out = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', (array) ($j['reply'] ?? [])), 'strlen'));
        if (!$out) return null;
        $allowed = $this->marks(implode(' ', $facts));
        foreach ($this->marks(implode(' ', $out)) as $m) if (!in_array($m, $allowed, true)) { error_log("[ai] rewrite rejected: added $m"); return null; }
        return array_slice($out, 0, 8);
    }

    private function convo(array $history): string {
        $s = '';
        foreach (array_slice($history, -6) as $h) $s .= (!empty($h['me']) ? 'User: ' : 'Assistant: ') . mb_substr((string) ($h['text'] ?? ''), 0, 400) . "\n";
        return $s;
    }

    /** Checkable facts in a text: planets, months and numbers, normalised so any language/script compares equal. */
    private function marks(string $s): array {
        $orig = $s; $s = mb_strtolower($s); $out = [];
        $s = strtr($s, array_combine(array_merge(mb_str_split('०१२३४५६७८९'), mb_str_split('૦૧૨૩૪૫૬૭૮૯')), array_merge(range(0, 9), range(0, 9))));
        // whole words only (Indic scripts: a letter or vowel sign on either side means it's part of another word)
        $has = fn(string $w) => (bool) preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '(?![\p{L}\p{M}])/u', $s);
        foreach (self::PLANET_ROMAN as $en => $rom)
            foreach (array_merge(explode('|', $rom), [strtolower($en), Lang::t('hi', "astro.planets.$en"), Lang::t('gu', "astro.planets.$en")]) as $w)
                if ($has($w)) { $out[] = "p:$en"; break; }
        foreach (['hi', 'gu'] as $l) foreach (explode('|', Lang::t($l, 'chat.months')) as $i => $mo)
            if (($mo = mb_strtolower(trim($mo))) !== '' && $has($mo)) $out[] = 'm:' . $i;
        // English month names only when capitalised, so "you may" / "march on" are not months
        foreach (self::MONTHS_EN as $i => $mo) if (preg_match('/\b' . ucfirst($mo) . '\b/', $orig)) $out[] = 'm:' . $i;
        preg_match_all('/\d+/', $s, $n);
        foreach ($n[0] as $x) $out[] = 'n:' . (int) $x;
        return array_values(array_unique($out));
    }

    private function call(string $sys, string $user, float $temp): ?array {
        $c = app_config()['ai'] ?? []; $model = (string) ($c['model'] ?? 'gemini-2.5-flash');
        $url = rtrim((string) ($c['url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/') . '/models/' . rawurlencode($model) . ':generateContent';
        $gen = ['temperature' => $temp, 'responseMimeType' => 'application/json'];
        if (str_contains($model, '2.5-flash')) $gen['thinkingConfig'] = ['thinkingBudget' => 0];   // faster replies
        $body = ['systemInstruction' => ['parts' => [['text' => $sys]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]], 'generationConfig' => $gen];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => (int) ($c['timeout'] ?? 12),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['key']]]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($r === false || $code !== 200) { error_log("[ai] request failed ($code)"); return null; }
        $t = json_decode($r, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $j = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $t)), true);
        return is_array($j) ? $j : null;
    }
}
