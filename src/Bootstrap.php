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
            // Estações informam timestamps em horário de Brasília, sem conversão.
            // PHP precisa da mesma referência pra date()/time()/strtotime() não
            // ficarem 3h à frente do que está gravado em leituras.timestamp.
            date_default_timezone_set('America/Sao_Paulo');

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
