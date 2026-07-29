<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

$cfg = ValeTaquari\Bootstrap::init();
$pdo = ValeTaquari\Database::get($cfg['db']);

$estacoes = [
    'taquari_1_cota'  => 'Lajeado',
    'taquari_2_cota'  => 'Encantado',
    'taquari_3_cota'  => 'Muçum',
    'taquari_4_cota'  => 'Linha José Júlio',
    'taquari_32_cota' => 'Santa Tereza',
    'taquari_33_cota' => 'Barra do Fão',
    'taquari_55_cota' => 'Linha Colombo',
];

echo "=== Nível mínimo por estação ===" . PHP_EOL;
echo str_pad("Estação", 16) . str_pad("Mínimo", 10) . str_pad("P01", 10) . str_pad("P05", 10) . str_pad("Média", 10) . "Máximo" . PHP_EOL;
echo str_repeat("-", 65) . PHP_EOL;

foreach ($estacoes as $id => $nome) {
    $row = $pdo->query(
        "SELECT
           MIN(valor::float)                                          AS minimo,
           PERCENTILE_CONT(0.01) WITHIN GROUP (ORDER BY valor::float) AS p01,
           PERCENTILE_CONT(0.05) WITHIN GROUP (ORDER BY valor::float) AS p05,
           AVG(valor::float)                                          AS media,
           MAX(valor::float)                                          AS maximo,
           COUNT(*)                                                   AS n
         FROM leituras
         WHERE estacao_id = '$id'
           AND valor::float BETWEEN 0.5 AND 50"
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row || $row['n'] == 0) {
        echo str_pad($nome, 16) . "  (sem dados)" . PHP_EOL;
        continue;
    }

    printf("%-16s %6.2f m   %6.2f m   %6.2f m   %6.2f m   %6.2f m\n",
        $nome,
        (float)$row['minimo'],
        (float)$row['p01'],
        (float)$row['p05'],
        (float)$row['media'],
        (float)$row['maximo']
    );
}

// Série temporal do mínimo de Lajeado — últimas 30 leituras mais baixas
echo PHP_EOL . "=== 20 leituras mais baixas de Lajeado ===" . PHP_EOL;
$stmt = $pdo->query(
    "SELECT timestamp, valor::float AS v
     FROM leituras
     WHERE estacao_id = 'taquari_1_cota'
       AND valor::float BETWEEN 0.5 AND 50
     ORDER BY valor::float ASC
     LIMIT 20"
);
foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    printf("  %s  →  %.2f m\n", $r['timestamp'], (float)$r['v']);
}
