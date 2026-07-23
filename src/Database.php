<?php
declare(strict_types=1);

namespace ValeTaquari;

use PDO;

class Database
{
    private static ?PDO $instance = null;

    public static function get(array $cfg): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if ($cfg['driver'] === 'pgsql') {
            $c   = $cfg['pgsql'];
            $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']}";
            self::$instance = new PDO($dsn, $c['user'], $c['pass'], self::options());
        } else {
            $path = $cfg['sqlite_path'];
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
                $path = dirname(__DIR__) . '/' . $path;
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$instance = new PDO("sqlite:{$path}", options: self::options());
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
            self::$instance->exec('PRAGMA synchronous=NORMAL');
        }

        return self::$instance;
    }

    /** Reseta a instância (útil em testes). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }
}
