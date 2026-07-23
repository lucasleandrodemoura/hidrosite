<?php
declare(strict_types=1);

/**
 * Converte valores de cota já armazenados de centímetros para metros.
 * Execute uma única vez: php migrations/002_fix_cota_cm_para_metros.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

use ValeTaquari\Bootstrap;
use ValeTaquari\Database;

$cfg = Bootstrap::init();
$pdo = Database::get($cfg['db']);

$antes = $pdo->query("SELECT COUNT(*) FROM leituras WHERE tipo='cota'")->fetchColumn();
echo "Registros de cota encontrados: {$antes}\n";

// Divide apenas valores que parecem estar em centímetros (> 100, pois cotas em metros nunca passam de ~50m)
$sql = "UPDATE leituras SET valor = ROUND((valor / 100.0)::numeric, 3) WHERE tipo = 'cota' AND valor > 50";
$afetados = $pdo->exec($sql);

echo "Registros corrigidos (cm→m): {$afetados}\n";
echo "Concluído.\n";
