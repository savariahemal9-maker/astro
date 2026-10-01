<?php
namespace App\Auth;

use App\Core\{ApiException, Db};

/** Admin accounts for the separate admin panel; independent of site users. */
final class AdminAuth {
    public static function register(string $name, string $email, string $password): array {
        $email = strtolower(trim($email)); $name = trim($name);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiException('validation', 'Enter a valid email address', 422, ['field' => 'email']);
        if ($name === '' || mb_strlen($name) > 120) throw new ApiException('validation', 'Enter your name', 422, ['field' => 'name']);
        if (mb_strlen($password) < 10) throw new ApiException('validation', 'Admin password must be at least 10 characters', 422, ['field' => 'password']);
        if (Db::one('SELECT id FROM admins WHERE email = ?', [$email])) throw new ApiException('email_taken', 'An admin with this email already exists', 409);
        // the very first admin becomes active (owner); everyone after waits for approval
        $status = Db::one("SELECT id FROM admins WHERE status = 'active'") ? 'pending' : 'active';
        $id = Db::insert('INSERT INTO admins (name, email, password_hash, status) VALUES (?,?,?,?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT), $status]);
        return $status === 'active' ? self::issue($id) : ['pending' => true];
    }

    public static function login(string $email, string $password): array {
        $a = Db::one('SELECT * FROM admins WHERE email = ?', [strtolower(trim($email))]);
        if (!$a || !password_verify($password, $a['password_hash'])) throw new ApiException('invalid_credentials', 'Email or password is incorrect', 401);
        if ($a['status'] === 'pending') throw new ApiException('admin_pending', 'Your admin account is waiting for approval by an existing admin.', 403);
        if ($a['status'] !== 'active') throw new ApiException('account_disabled', 'This admin account has been disabled.', 403);
        return self::issue((int) $a['id']);
    }

    private static function issue(int $id): array {
        $token = bin2hex(random_bytes(32)); $exp = gmdate('Y-m-d H:i:s', time() + 7 * 86400);   // admin sessions last 7 days
        Db::exec('INSERT INTO admin_tokens (admin_id, token_hash, expires_at) VALUES (?,?,?)', [$id, hash('sha256', $token), $exp]);
        return ['token' => $token, 'expires_at' => $exp . 'Z', 'admin' => self::present(Db::one('SELECT * FROM admins WHERE id = ?', [$id]))];
    }

    public static function authenticate(?string $token): array {
        if (!$token) throw new ApiException('unauthorized', 'Admin sign in required', 401);
        $a = Db::one("SELECT a.* FROM admin_tokens t JOIN admins a ON a.id = t.admin_id WHERE t.token_hash = ? AND t.expires_at > UTC_TIMESTAMP() AND a.status = 'active'", [hash('sha256', $token)]);
        if (!$a) throw new ApiException('unauthorized', 'Admin session expired. Sign in again.', 401);
        return $a;
    }

    public static function logout(?string $token): void { if ($token) Db::exec('DELETE FROM admin_tokens WHERE token_hash = ?', [hash('sha256', $token)]); }
    public static function present(array $a): array { return ['id' => (int) $a['id'], 'name' => $a['name'], 'email' => $a['email'], 'status' => $a['status'], 'created_at' => $a['created_at']]; }
}
