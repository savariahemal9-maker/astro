<?php
namespace App\Interp;

use App\Core\Db;
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

    /** Why the last call fell back to the rule-based answer (shown to admins, never contains the key). */
    public static string $lastError = '';

    public function __construct(private string $lang) {}

    /** Admin check: is a key set, and does a tiny test request succeed? */
    public static function status(): array {
        $c = app_config()['ai'] ?? []; $key = trim((string) ($c['key'] ?? ''));
        $out = ['key_set' => $key !== '', 'key_length' => strlen($key), 'model' => self::model(), 'ok' => false, 'error' => ''];
        if ($key === '') { $out['error'] = "No key: add 'ai' => ['key' => ...] to astro-config.php"; return $out; }
        $j = (new self('en'))->call('Reply with JSON only.', 'Return {"ok": true}', 0.0);
        $out['model'] = self::model();                            // may have switched to Google's replacement
        $out['ok'] = ($j['ok'] ?? false) === true; $out['error'] = $out['ok'] ? '' : self::$lastError;
        return $out;
    }

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
            . "- Answer the client's actual question first, in your own words, like a real astrologer talking — do not copy the FACTS sentence by sentence.\n"
            . "- You may shorten, reorder and join the facts; keep every remedy's meaning exactly.\n"
            . "- If the question asks for something FACTS do not contain (e.g. an exact count or a yes/no the facts don't settle), say honestly the chart does not show that, then share what FACTS do say.\n"
            . "- If FACTS say the question cannot be answered, say so kindly and mention what they can ask.\n"
            . "- 2 to 5 short sentences, no headings or markdown; remedies may be separate short lines.\n"
            . '- Reply in the same language and script as the client\'s last message (default ' . self::LANGS[$this->lang] . ").\n"
            . 'Return only JSON: {"reply": ["paragraph", ...]}';
        $user = $this->convo($history) . "Client: $msg\n\nFACTS:\n" . implode("\n", $facts);
        // numbers, months and planets the client or the facts already used are fine to repeat
        $allowed = $this->marks(implode(' ', $facts) . ' ' . $msg . ' ' . implode(' ', array_map(fn($h) => (string) ($h['text'] ?? ''), $history)));
        $out = null;
        for ($try = 0; $try < 2 && !$out; $try++) {                // one retry, told what it added
            $j = $this->call($sys, $user, $try ? 0.1 : 0.5);
            $out = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', (array) ($j['reply'] ?? [])), 'strlen'));
            if (!$out) return null;
            if ($extra = array_values(array_diff($this->marks(implode(' ', $out)), $allowed))) {
                self::$lastError = 'rewrite_rejected: added ' . implode(', ', $extra); error_log('[ai] ' . self::$lastError);
                $user .= "\n\nYour previous reply mentioned things that are not in FACTS (" . implode(', ', $extra) . '). Write it again using only FACTS.';
                $out = null;
            }
        }
        if (!$out) return null;
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

    public const DEFAULT_MODEL = 'gemini-3.8-flash';

    /** Configured model, unless Google retired it and named a replacement earlier (remembered in the cache table). */
    private static ?string $override = null;                  // backup model for a single retry
    private static function model(): string {
        if (self::$override !== null) return self::$override;
        $cfg = (string) ((app_config()['ai'] ?? [])['model'] ?? self::DEFAULT_MODEL);
        try { $o = Db::one('SELECT payload FROM panchang_cache WHERE cache_key = ?', ['ai_model:' . $cfg]); } catch (\Throwable $e) { $o = null; }
        return $o ? (string) json_decode($o['payload'], true) : $cfg;
    }

    private function call(string $sys, string $user, float $temp, bool $retried = false): ?array {
        $c = app_config()['ai'] ?? []; $model = self::model();
        $url = rtrim((string) ($c['url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/') . '/models/' . rawurlencode($model) . ':generateContent';
        $gen = ['temperature' => $temp, 'responseMimeType' => 'application/json'];
        if (str_contains($model, '2.5-flash')) $gen['thinkingConfig'] = ['thinkingBudget' => 0];   // faster replies
        $body = ['systemInstruction' => ['parts' => [['text' => $sys]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]], 'generationConfig' => $gen];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_TIMEOUT => (int) ($c['timeout'] ?? 20),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $c['key']]]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($r === false || $code !== 200) {
            $why = $r === false ? 'network error' : (string) (json_decode($r, true)['error']['message'] ?? 'no details');
            // "model X is no longer available ... use models/Y": switch to Y once and remember it
            if ($code === 404 && !$retried && preg_match_all('#models/([a-z0-9.\-]+)#i', $why, $mm) && ($next = end($mm[1])) && $next !== $model) {
                $cfg = (string) ($c['model'] ?? self::DEFAULT_MODEL);
                Db::exec('REPLACE INTO panchang_cache (cache_key, payload) VALUES (?,?)', ['ai_model:' . $cfg, json_encode($next)]);
                error_log("[ai] model $model retired, switching to $next");
                return $this->call($sys, $user, $temp, true);
            }
            // busy / rate-limited: wait a moment and try once more, then a backup model if one is configured
            if (in_array($code, [429, 500, 503], true) && !$retried) {
                usleep(1200000);
                $backup = (string) ($c['backup_model'] ?? '');
                if ($backup !== '' && $backup !== $model) {
                    $saved = self::$override; self::$override = $backup;
                    $j = $this->call($sys, $user, $temp, true); self::$override = $saved;
                    if ($j !== null) return $j;
                }
                return $this->call($sys, $user, $temp, true);
            }
            self::$lastError = "http_$code ($model): " . mb_substr($why, 0, 200); error_log('[ai] ' . self::$lastError); return null;
        }
        $t = json_decode($r, true)['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $j = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $t)), true);
        if (!is_array($j)) { self::$lastError = 'bad_json: ' . mb_substr($t, 0, 120); error_log('[ai] ' . self::$lastError); return null; }
        return $j;
    }
}
