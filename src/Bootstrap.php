<?php
declare(strict_types=1);

namespace ValeTaquari;

use Dotenv\Dotenv;

class Bootstrap
{
    private static bool $initialized = false;

    /**
     * Carrega .env e retorna o array de configuração.
     */
    public static function init(): array
    {
        if (!self::$initialized) {
            $root = dirname(__DIR__);

            if (file_exists("{$root}/.env")) {
                $dotenv = Dotenv::createImmutable($root);
                $dotenv->safeLoad();
            }

            // Garante diretórios necessários
            foreach (['data', 'logs'] as $dir) {
                $path = "{$root}/{$dir}";
                if (!is_dir($path)) {
                    mkdir($path, 0755, true);
                }
            }

            self::$initialized = true;
        }

        /** @var array $config */
        $config = require __DIR__ . '/../config/config.php';
        return $config;
    }
}
