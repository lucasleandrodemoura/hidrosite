<?php
declare(strict_types=1);

/**
 * Ponto de entrada do job de coleta.
 * Execute a cada 15 minutos via cron:
 *
 *   * /15 * * * *  /usr/bin/php /caminho/para/cron/collect.php >> /dev/null 2>&1
 *
 * Ou via Windows Task Scheduler apontando para este arquivo.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

use ValeTaquari\Bootstrap;
use ValeTaquari\Database;
use ValeTaquari\Collector;
use ValeTaquari\EventDetector;
use ValeTaquari\Logger;

$cfg    = Bootstrap::init();
$pdo    = Database::get($cfg['db']);
$logger = new Logger($cfg['log']['path'], $cfg['log']['level']);

$logger->info('=== Início da coleta ===');

// 1. Coleta todos os CSVs do SGB
$coletor   = new Collector($pdo, $logger, $cfg);
$resultado = $coletor->coletarTodas();

$totalNovas = array_sum(array_column($resultado, 'novas'));
$totalErros = count(array_filter($resultado, fn($r) => $r['erro'] !== null));

$logger->info("Coleta concluída", [
    'linhas_novas' => $totalNovas,
    'erros'        => $totalErros,
]);

// 2. Verifica/atualiza estado de eventos de cheia
$detector = new EventDetector($pdo, $logger, $cfg);
$acao     = $detector->verificar();

$logger->info("Detector de eventos", $acao);
$logger->info('=== Fim da coleta ===');

// Saída para console (útil em execuções manuais)
if (php_sapi_name() === 'cli') {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Coleta: {$totalNovas} leituras novas, {$totalErros} erro(s). ";
    echo "Evento: {$acao['acao']}\n";
}
