<?php
declare(strict_types=1);

/**
 * Treino do modelo MLR-Lag (Regressão Linear Múltipla com Defasagens)
 *
 * Uso: php scripts/train_mlr.php [--horizonte=24] [--lambda=0.01]
 *
 * Saída: data/mlr_coefs.json  — coeficientes prontos para uso pela API
 *
 * Método: Ridge Regression (OLS + L2) com features defasadas de cada
 * estação upstream. Prediz h_Lajeado(t + horizonte_h).
 *
 * Referência: Box & Jenkins (1970); Collischonn & Tucci (2001, RBRH).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

$cfg = ValeTaquari\Bootstrap::init();
$pdo = ValeTaquari\Database::get($cfg['db']);

// ── Parâmetros ────────────────────────────────────────────────────────────────

$horizonte_h = 24;   // horas à frente para prever
$lambda      = 0.01; // regularização Ridge (evita overfitting)

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--horizonte=')) $horizonte_h = (int)substr($arg, 12);
    if (str_starts_with($arg, '--lambda='))    $lambda      = (float)substr($arg, 9);
}

$horizonte_steps = $horizonte_h * 4; // passos de 15min

echo "=== MLR-Lag — treino ===" . PHP_EOL;
echo "Horizonte: {$horizonte_h}h ({$horizonte_steps} leituras) | Lambda: {$lambda}" . PHP_EOL . PHP_EOL;

// ── Features: defasagens observadas empiricamente ─────────────────────────────
//
// Para prever Lajeado(t+24h), usamos valores de estações upstream no instante
// t, t-3h, t-6h etc. — a estação chegará a Lajeado após o lag correspondente.
// Também incluímos a cota atual de Lajeado (baseline) e sua tendência recente.
//
// Formato: ['estacao_id', lag_steps_atras, 'alias']
// lag_steps_atras = horas * 4 (leituras de 15min)

$features_def = [
    // Encantado — lag ~3h até Lajeado
    ['taquari_2_cota',  0,  'enc_t0'],
    ['taquari_2_cota',  4,  'enc_t1h'],
    ['taquari_2_cota',  8,  'enc_t2h'],
    ['taquari_2_cota', 12,  'enc_t3h'],
    // Muçum — lag ~11h até Lajeado
    ['taquari_3_cota',  0,  'muc_t0'],
    ['taquari_3_cota',  4,  'muc_t1h'],
    ['taquari_3_cota',  8,  'muc_t2h'],
    ['taquari_3_cota', 16,  'muc_t4h'],
    // Santa Tereza — lag ~13h até Lajeado
    ['taquari_32_cota', 0,  'sta_t0'],
    ['taquari_32_cota', 8,  'sta_t2h'],
    // Linha Colombo — lag ~20h até Lajeado
    ['taquari_55_cota', 0,  'lc_t0'],
    // Barra do Fão — lag ~22h até Lajeado
    ['taquari_33_cota', 0,  'bf_t0'],
    // Cota atual de Lajeado + tendência recente (baseline fortíssimo)
    ['taquari_1_cota',  0,  'laj_t0'],
    ['taquari_1_cota',  4,  'laj_t1h'],
    ['taquari_1_cota',  8,  'laj_t2h'],
    ['taquari_1_cota', 16,  'laj_t4h'],
];

// Chuva acumulada nas cabeceiras (6h e 12h) como feature
$estacoes_chuva = [
    'taquari_9_chuva', 'taquari_12_chuva', 'taquari_31_chuva',
    'taquari_54_chuva','taquari_32_chuva', 'taquari_55_chuva', 'taquari_33_chuva',
];

// ── Carrega séries do banco ───────────────────────────────────────────────────

echo "Carregando dados..." . PHP_EOL;

// Todas as estações de cota + chuva, por timestamp, em série contínua
$stmt = $pdo->query(
    "SELECT estacao_id, tipo,
            date_trunc('minute', timestamp) -
              (EXTRACT(MINUTE FROM timestamp)::int % 15) * INTERVAL '1 minute' AS ts_15,
            AVG(valor::float) AS valor
     FROM leituras
     GROUP BY estacao_id, tipo, ts_15
     ORDER BY ts_15"
);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Organiza em: $series[estacao_id][timestamp_unix] = valor
$series = [];
foreach ($rows as $r) {
    $ts = strtotime($r['ts_15']);
    $series[$r['estacao_id']][$ts] = (float)$r['valor'];
}

$nSeries = count($series);
echo "Séries carregadas: {$nSeries} estações" . PHP_EOL;

// ── Grade temporal: todos os timestamps com Lajeado ──────────────────────────

$tsLaj = array_keys($series['taquari_1_cota'] ?? []);
sort($tsLaj);
$step15 = 15 * 60;

echo "Leituras de Lajeado: " . count($tsLaj) . PHP_EOL;

// ── Monta matriz X e vetor y ──────────────────────────────────────────────────

$X = [];
$y = [];
$feature_names = array_column($features_def, 2);
$feature_names[] = 'chuva_6h';
$feature_names[] = 'chuva_12h';
$feature_names[] = 'intercept';

$skipped = 0;
$used    = 0;

foreach ($tsLaj as $ts) {
    $ts_target = $ts + $horizonte_steps * $step15;

    // Precisa ter a leitura alvo
    if (!isset($series['taquari_1_cota'][$ts_target])) { $skipped++; continue; }

    $row = [];
    $ok  = true;

    // Features de cota defasadas
    foreach ($features_def as [$id, $lag_steps, $alias]) {
        $ts_feat = $ts - $lag_steps * $step15;
        if (!isset($series[$id][$ts_feat])) {
            // Tolera gap de até 1 leitura (busca mais próxima ±15min)
            $v = $series[$id][$ts_feat - $step15] ?? $series[$id][$ts_feat + $step15] ?? null;
            if ($v === null) { $ok = false; break; }
            $row[] = $v;
        } else {
            $row[] = $series[$id][$ts_feat];
        }
    }
    if (!$ok) { $skipped++; continue; }

    // Chuva acumulada 6h e 12h nas cabeceiras
    $chuva6h  = 0.0; $chuva12h = 0.0; $nch = 0;
    foreach ($estacoes_chuva as $eid) {
        if (!isset($series[$eid])) continue;
        $soma6 = 0; $soma12 = 0;
        for ($i = 1; $i <= 24; $i++) { // 24 passos = 6h
            $soma6  += $series[$eid][$ts - $i * $step15] ?? 0;
        }
        for ($i = 1; $i <= 48; $i++) { // 48 passos = 12h
            $soma12 += $series[$eid][$ts - $i * $step15] ?? 0;
        }
        $chuva6h  += $soma6;
        $chuva12h += $soma12;
        $nch++;
    }
    $row[] = $nch > 0 ? $chuva6h / $nch  : 0.0;
    $row[] = $nch > 0 ? $chuva12h / $nch : 0.0;
    $row[] = 1.0; // intercept (bias)

    $X[]   = $row;
    $y[]   = $series['taquari_1_cota'][$ts_target];
    $used++;
}

echo "Amostras usadas: {$used} | Descartadas (dados faltando): {$skipped}" . PHP_EOL;

if ($used < 50) {
    echo PHP_EOL . "ERRO: amostras insuficientes para treino (mínimo 50, temos {$used})." . PHP_EOL;
    echo "Continue coletando dados e re-execute o script." . PHP_EOL;
    exit(1);
}

$n = count($X);
$p = count($X[0]);

echo "Matriz X: {$n} × {$p} | Features: " . implode(', ', $feature_names) . PHP_EOL . PHP_EOL;

// ── Ridge Regression: β = (X'X + λI)^(-1) X'y ───────────────────────────────

echo "Treinando Ridge Regression (λ={$lambda})..." . PHP_EOL;

// X'X
$XtX = array_fill(0, $p, array_fill(0, $p, 0.0));
for ($i = 0; $i < $n; $i++) {
    for ($j = 0; $j < $p; $j++) {
        for ($k = 0; $k < $p; $k++) {
            $XtX[$j][$k] += $X[$i][$j] * $X[$i][$k];
        }
    }
}

// X'y
$Xty = array_fill(0, $p, 0.0);
for ($i = 0; $i < $n; $i++) {
    for ($j = 0; $j < $p; $j++) {
        $Xty[$j] += $X[$i][$j] * $y[$i];
    }
}

// Adiciona regularização Ridge na diagonal (exceto intercept)
for ($j = 0; $j < $p - 1; $j++) {
    $XtX[$j][$j] += $lambda * $n;
}

// Resolve (X'X + λI)β = X'y por eliminação de Gauss com pivotamento parcial
$beta = gaussianElimination($XtX, $Xty, $p);

// ── Avalia no conjunto de treino ──────────────────────────────────────────────

$yMean  = array_sum($y) / $n;
$ssTot  = 0.0; $ssRes = 0.0; $mse = 0.0;
$erros  = [];

for ($i = 0; $i < $n; $i++) {
    $pred  = dot($beta, $X[$i], $p);
    $res   = $y[$i] - $pred;
    $ssRes += $res ** 2;
    $ssTot += ($y[$i] - $yMean) ** 2;
    $mse   += $res ** 2;
    $erros[] = abs($res);
}

$nse  = 1 - $ssRes / $ssTot;
$rmse = sqrt($mse / $n);
$mae  = array_sum($erros) / $n;
sort($erros);
$p90  = $erros[(int)(0.9 * count($erros))];

echo PHP_EOL . "=== Métricas (treino) ===" . PHP_EOL;
echo "NSE:  " . round($nse, 4)  . " (≥0.70 aceitável, ≥0.85 bom)" . PHP_EOL;
echo "RMSE: " . round($rmse, 3) . " m" . PHP_EOL;
echo "MAE:  " . round($mae, 3)  . " m" . PHP_EOL;
echo "P90:  " . round($p90, 3)  . " m (90% dos erros abaixo disso)" . PHP_EOL;

// ── Importância das features (|β| normalizado) ────────────────────────────────

$absB = array_map('abs', $beta);
$maxB = max($absB) ?: 1;

echo PHP_EOL . "=== Importância das features ===" . PHP_EOL;
$imp = [];
for ($j = 0; $j < $p; $j++) {
    $imp[$feature_names[$j]] = round($absB[$j] / $maxB * 100, 1);
}
arsort($imp);
foreach ($imp as $name => $pct) {
    $bar = str_repeat('█', (int)($pct / 5));
    echo sprintf("  %-12s %5.1f%%  %s%s", $name, $pct, $bar, PHP_EOL);
}

// ── Salva modelo em JSON ──────────────────────────────────────────────────────

$model = [
    'versao'        => '1.0',
    'treinado_em'   => date('Y-m-d H:i:s'),
    'horizonte_h'   => $horizonte_h,
    'n_amostras'    => $n,
    'n_features'    => $p,
    'lambda'        => $lambda,
    'metricas'      => [
        'nse'  => round($nse, 4),
        'rmse' => round($rmse, 4),
        'mae'  => round($mae, 4),
        'p90'  => round($p90, 4),
    ],
    'features'      => $feature_names,
    'features_def'  => $features_def,
    'estacoes_chuva'=> $estacoes_chuva,
    'coeficientes'  => array_combine($feature_names, array_map(fn($v) => round($v, 6), $beta)),
    'y_mean'        => round($yMean, 4),
];

$outFile = __DIR__ . '/../data/mlr_coefs.json';
file_put_contents($outFile, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo PHP_EOL . "Modelo salvo em: {$outFile}" . PHP_EOL;

// ── Funções ───────────────────────────────────────────────────────────────────

function gaussianElimination(array $A, array $b, int $n): array
{
    // Monta matriz aumentada [A|b]
    $M = [];
    for ($i = 0; $i < $n; $i++) {
        $M[$i] = $A[$i];
        $M[$i][$n] = $b[$i];
    }

    // Eliminação com pivotamento parcial
    for ($col = 0; $col < $n; $col++) {
        // Pivotamento: encontra maior elemento na coluna
        $maxVal = abs($M[$col][$col]);
        $maxRow = $col;
        for ($row = $col + 1; $row < $n; $row++) {
            if (abs($M[$row][$col]) > $maxVal) {
                $maxVal = abs($M[$row][$col]);
                $maxRow = $row;
            }
        }
        [$M[$col], $M[$maxRow]] = [$M[$maxRow], $M[$col]];

        if (abs($M[$col][$col]) < 1e-12) continue; // coluna singular

        $pivot = $M[$col][$col];
        for ($row = $col + 1; $row < $n; $row++) {
            $factor = $M[$row][$col] / $pivot;
            for ($k = $col; $k <= $n; $k++) {
                $M[$row][$k] -= $factor * $M[$col][$k];
            }
        }
    }

    // Substituição retroativa
    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        if (abs($M[$i][$i]) < 1e-12) continue;
        $x[$i] = $M[$i][$n];
        for ($j = $i + 1; $j < $n; $j++) {
            $x[$i] -= $M[$i][$j] * $x[$j];
        }
        $x[$i] /= $M[$i][$i];
    }
    return $x;
}

function dot(array $a, array $b, int $n): float
{
    $s = 0.0;
    for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
    return $s;
}
