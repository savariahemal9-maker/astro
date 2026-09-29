<?php
namespace App\Api;

use App\Core\{ApiException, Db};

/**
 * Saved chat conversations (both the basic chat and the AI Astrologer), so a user can continue later.
 * Tables are created on first use, so no manual migration step is needed (also in database/migrations/009_chats.sql).
 */
final class ChatStore {
    private static bool $ready = false;

    private static function ensure(): void {
        if (self::$ready) return;
        Db::exec("CREATE TABLE IF NOT EXISTS chat_threads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, profile_id INT UNSIGNED NOT NULL,
            mode VARCHAR(10) NOT NULL, title VARCHAR(160) NOT NULL, lang VARCHAR(5) NOT NULL DEFAULT 'auto', ctx TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id, mode, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        Db::exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, thread_id INT UNSIGNED NOT NULL, role VARCHAR(4) NOT NULL, body MEDIUMTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (thread_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    /** Existing thread of this user/mode/kundali, or a new one titled after the first question. */
    public static function open(int $uid, ?int $tid, int $pid, string $mode, string $lang, string $firstMsg): int {
        self::ensure();
        if ($tid && Db::one('SELECT id FROM chat_threads WHERE id=? AND user_id=? AND mode=? AND profile_id=?', [$tid, $uid, $mode, $pid])) {
            Db::exec('UPDATE chat_threads SET updated_at=UTC_TIMESTAMP(), lang=? WHERE id=?', [$lang, $tid]); return $tid;
        }
        $title = mb_substr(preg_replace('/\s+/u', ' ', trim($firstMsg)), 0, 80);
        return Db::insert('INSERT INTO chat_threads (user_id, profile_id, mode, title, lang, created_at, updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$uid, $pid, $mode, $title, $lang]);
    }

    public static function add(int $tid, bool $me, array $lines, ?array $ctx = null): void {
        self::ensure();
        Db::insert('INSERT INTO chat_messages (thread_id, role, body, created_at) VALUES (?,?,?,UTC_TIMESTAMP())', [$tid, $me ? 'user' : 'bot', json_encode(array_values($lines), JSON_UNESCAPED_UNICODE)]);
        if ($ctx !== null) Db::exec('UPDATE chat_threads SET ctx=? WHERE id=?', [json_encode($ctx), $tid]);
    }

    /** Last $n turns as [['me' => bool, 'text' => string]], oldest first. */
    public static function history(int $tid, int $n = 10): array {
        self::ensure();
        $rows = array_reverse(Db::all('SELECT role, body FROM chat_messages WHERE thread_id=? ORDER BY id DESC LIMIT ' . (int) $n, [$tid]));
        return array_map(fn($r) => ['me' => $r['role'] === 'user', 'text' => implode("\n\n", (array) json_decode($r['body'], true))], $rows);
    }

    public static function list(int $uid, string $mode): array {
        self::ensure();
        return array_map(fn($r) => ['id' => (int) $r['id'], 'profile_id' => (int) $r['profile_id'], 'label' => $r['label'] ?? '', 'title' => $r['title'], 'updated_at' => $r['updated_at'] . 'Z', 'count' => (int) $r['n']],
            Db::all('SELECT t.id, t.profile_id, t.title, t.updated_at, p.label, (SELECT COUNT(*) FROM chat_messages m WHERE m.thread_id=t.id) n
                     FROM chat_threads t LEFT JOIN birth_profiles p ON p.id=t.profile_id WHERE t.user_id=? AND t.mode=? ORDER BY t.updated_at DESC LIMIT 100', [$uid, $mode]));
    }

    public static function get(int $uid, int $tid): array {
        self::ensure();
        $t = Db::one('SELECT * FROM chat_threads WHERE id=? AND user_id=?', [$tid, $uid]);
        if (!$t) throw new ApiException('not_found', 'Chat not found', 404);
        $msgs = array_map(fn($r) => ['me' => $r['role'] === 'user', 'lines' => (array) json_decode($r['body'], true), 'ts' => strtotime($r['created_at'] . ' UTC') * 1000],
            Db::all('SELECT role, body, created_at FROM chat_messages WHERE thread_id=? ORDER BY id', [$tid]));
        return ['id' => (int) $t['id'], 'profile_id' => (int) $t['profile_id'], 'mode' => $t['mode'], 'title' => $t['title'], 'lang' => $t['lang'],
                'ctx' => $t['ctx'] ? json_decode($t['ctx'], true) : new \stdClass(), 'msgs' => $msgs];
    }

    public static function delete(int $uid, int $tid): void {
        self::ensure();
        if (!Db::one('SELECT id FROM chat_threads WHERE id=? AND user_id=?', [$tid, $uid])) throw new ApiException('not_found', 'Chat not found', 404);
        Db::exec('DELETE FROM chat_messages WHERE thread_id=?', [$tid]); Db::exec('DELETE FROM chat_threads WHERE id=?', [$tid]);
    }
}
