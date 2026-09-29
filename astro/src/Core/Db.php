<?php
namespace App\Core;

final class Db {
    private static ?\PDO $pdo = null;
    public static function pdo(): \PDO {
        if (!self::$pdo) {
            $c = app_config()['db'];
            self::$pdo = new \PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }
    public static function setPdo(\PDO $p): void { self::$pdo = $p; }
    public static function one(string $sql, array $p = []): ?array {
        $s = self::pdo()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r ?: null;
    }
    public static function all(string $sql, array $p = []): array {
        $s = self::pdo()->prepare($sql); $s->execute($p); return $s->fetchAll();
    }
    public static function exec(string $sql, array $p = []): int {
        $s = self::pdo()->prepare($sql); $s->execute($p); return $s->rowCount();
    }
    public static function insert(string $sql, array $p = []): int {
        self::exec($sql, $p); return (int) self::pdo()->lastInsertId();
    }
}
