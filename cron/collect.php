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

// 3. Re-treina o modelo de previsão quando há informação nova de calibração.
//
// Gatilhos: um evento de cheia acabou de fechar (é quando entra um caso novo na
// validação e na razão chuva/cota), ou o modelo não existe, ou passou de 7 dias.
// Roda em processo separado para que uma falha no treino não interrompa a coleta,
// que é a função crítica deste job.
$modeloFile = __DIR__ . '/../data/mlr_coefs.json';
$fechouEvento = ($acao['acao'] ?? '') === 'fechado';
$modeloVelho  = !file_exists($modeloFile)
              || (time() - filemtime($modeloFile)) > 7 * 86400;

if ($fechouEvento || $modeloVelho) {
    $motivo = $fechouEvento ? 'evento fechado' : 'modelo ausente ou com mais de 7 dias';
    $logger->info("Re-treinando modelo de previsão ({$motivo})");

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/train_mlr.php') . ' 2>&1';
    exec($cmd, $saida, $codigo);

    if ($codigo === 0) {
        $logger->info('Modelo re-treinado', ['linhas_saida' => count($saida)]);
    } else {
        $logger->error('Falha ao re-treinar modelo', [
            'codigo' => $codigo,
            'saida'  => implode(' | ', array_slice($saida, -3)),
        ]);
    }
}

$logger->info('=== Fim da coleta ===');

// Saída para console (útil em execuções manuais)
if (php_sapi_name() === 'cli') {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] Coleta: {$totalNovas} leituras novas, {$totalErros} erro(s). ";
    echo "Evento: {$acao['acao']}\n";
}
