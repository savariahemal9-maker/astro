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
        return $row;
    }

    public static function logout(string $token): void { Db::exec('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]); }
    public static function find(int $id): array { return Db::one('SELECT * FROM users WHERE id = ?', [$id]) ?? []; }
    public static function publicUser(array $u): array { return ['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'lang' => $u['lang']]; }
}
