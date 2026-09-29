<?php
namespace App\Auth;

use App\Core\{ApiException, Db};

final class AuthService {
    public static function register(string $name, string $email, string $password, string $lang): array {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException('validation', 'Enter a valid email address', 422, ['field' => 'email']);
        if (mb_strlen($password) < 8) throw new ApiException('validation', 'Password must be at least 8 characters', 422, ['field' => 'password']);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) throw new ApiException('validation', 'Enter your name', 422, ['field' => 'name']);
        if (Db::one('SELECT id FROM users WHERE email = ?', [$email])) throw new ApiException('email_taken', 'An account with this email already exists', 409);
        $id = Db::insert('INSERT INTO users (name, email, password_hash, lang) VALUES (?,?,?,?)',
            [$name, $email, password_hash($password, PASSWORD_DEFAULT), in_array($lang, ['en','hi','gu'], true) ? $lang : 'en']);
        return self::issue($id, 'web');
    }

    public static function login(string $email, string $password, string $client): array {
        $u = Db::one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$u || !password_verify($password, $u['password_hash'])) throw new ApiException('invalid_credentials', 'Email or password is incorrect', 401);
        if (!empty($u['disabled'])) throw new ApiException('account_disabled', 'This account has been disabled.', 403);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT))
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        return self::issue((int) $u['id'], $client);
    }

    private static function issue(int $userId, string $client): array {
        $token = bin2hex(random_bytes(32));
        $days = (int) app_config()['auth']['token_ttl_days'];
        $exp = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        Db::exec('INSERT INTO api_tokens (user_id, token_hash, client, expires_at) VALUES (?,?,?,?)',
            [$userId, hash('sha256', $token), substr(preg_replace('/[^a-z]/', '', strtolower($client)) ?: 'web', 0, 40), $exp]);
        return ['token' => $token, 'expires_at' => $exp . 'Z', 'user' => self::publicUser(self::find($userId))];
    }

    public static function authenticate(?string $token): array {
        if (!$token) throw new ApiException('unauthorized', 'Sign in required', 401);
        $row = Db::one('SELECT u.* FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > UTC_TIMESTAMP()',
            [hash('sha256', $token)]);
        if (!$row) throw new ApiException('unauthorized', 'Session expired. Sign in again.', 401);
        if (!empty($row['disabled'])) throw new ApiException('unauthorized', 'This account has been disabled.', 401);
        return $row;
    }

    private static function checkPassword(string $password): void {
        if (mb_strlen($password) < 8) throw new ApiException('validation', 'Password must be at least 8 characters', 422, ['field' => 'password']);
    }

    /** Emails a one-time reset link. Always succeeds so the response does not reveal which emails are registered. */
    public static function forgotPassword(string $email, string $baseUrl): void {
        $u = Db::one('SELECT id, name, email FROM users WHERE email = ?', [strtolower(trim($email))]);
        if (!$u) return;
        $token = bin2hex(random_bytes(32));
        Db::exec('DELETE FROM password_resets WHERE user_id = ?', [$u['id']]);
        Db::exec('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)', [$u['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600)]);
        $link = rtrim($baseUrl, '/') . '/#/reset/' . $token;
        $from = app_config()['mail']['from'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $body = "Hello {$u['name']},\r\n\r\nUse this link to set a new password (valid for 1 hour):\r\n$link\r\n\r\nIf you did not ask for this, ignore this email.";
        if (!@mail($u['email'], 'Reset your password', $body, "From: $from\r\nContent-Type: text/plain; charset=utf-8"))
            error_log('[auth] reset mail failed for user ' . $u['id']);
    }

    public static function resetPassword(string $token, string $password): void {
        self::checkPassword($password);
        $r = Db::one('SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()', [hash('sha256', $token)]);
        if (!$r) throw new ApiException('invalid_reset', 'This reset link is invalid or has expired. Request a new one.', 400);
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $r['user_id']]);
        Db::exec('DELETE FROM password_resets WHERE user_id = ?', [$r['user_id']]);
        Db::exec('DELETE FROM api_tokens WHERE user_id = ?', [$r['user_id']]); // sign out everywhere
    }

    /** Changes the password and signs out every other session. */
    public static function changePassword(array $u, string $current, string $new, string $keepToken): void {
        if (!password_verify($current, $u['password_hash'])) throw new ApiException('invalid_credentials', 'Current password is incorrect', 401, ['field' => 'current_password']);
        self::checkPassword($new);
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        Db::exec('DELETE FROM api_tokens WHERE user_id = ? AND token_hash <> ?', [$u['id'], hash('sha256', $keepToken)]);
    }

    /** Updates name / email / language. Changing the email needs the current password. */
    public static function updateProfile(array $u, array $in): array {
        if (isset($in['name'])) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 120) throw new ApiException('validation', 'Enter your name', 422, ['field' => 'name']);
            Db::exec('UPDATE users SET name=? WHERE id=?', [$name, $u['id']]);
        }
        if (isset($in['lang'])) Db::exec('UPDATE users SET lang=? WHERE id=?', [in_array($in['lang'], ['en','hi','gu'], true) ? $in['lang'] : 'en', $u['id']]);
        if (isset($in['email']) && ($email = strtolower(trim((string) $in['email']))) !== $u['email']) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException('validation', 'Enter a valid email address', 422, ['field' => 'email']);
            if (!password_verify((string) ($in['password'] ?? ''), $u['password_hash'])) throw new ApiException('invalid_credentials', 'Enter your current password to change the email', 401, ['field' => 'password']);
            if (Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) throw new ApiException('email_taken', 'An account with this email already exists', 409);
            Db::exec('UPDATE users SET email=? WHERE id=?', [$email, $u['id']]);
        }
        return self::publicUser(self::find((int) $u['id']));
    }

    public static function logout(string $token): void { Db::exec('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]); }
    public static function find(int $id): array { return Db::one('SELECT * FROM users WHERE id = ?', [$id]) ?? []; }
    public static function publicUser(array $u): array { return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'lang' => $u['lang'], 'plan' => $u['plan'] ?? 'free']; }
}
