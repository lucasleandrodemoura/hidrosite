<?php
declare(strict_types=1);

/**
 * Treino do modelo MLR-Lag v3 — multi-horizonte com validação por evento
 *
 * Uso: php scripts/train_mlr.php [--lambda=0.1] [--horizontes=3,6,9,12,15,18,21,24]
 *
 * Saída: data/mlr_coefs.json — um conjunto de coeficientes por horizonte,
 *        mais as métricas de validação leave-one-event-out de cada um.
 *
 * ── O que mudou da v2 para a v3 ───────────────────────────────────────────────
 *
 * 1. MULTI-HORIZONTE. A v2 treinava um único modelo para +24h e desenhava a
 *    curva intermediária por interpolação linear — a trajetória era inventada.
 *    A v3 treina um modelo por horizonte (3h..24h), então cada ponto da curva
 *    é previsto, não interpolado.
 *
 * 2. VALIDAÇÃO HONESTA. A v2 reportava NSE=0,87 medido no próprio conjunto de
 *    treino, o que não diz nada sobre a previsão de uma cheia nova. A v3 valida
 *    deixando um evento de cheia inteiro fora do treino e medindo nele
 *    (leave-one-event-out), inclusive descartando as amostras cujo alvo cai
 *    dentro do evento — sem isso o alvo vaza para o treino.
 *    Medido assim, o modelo v2 errava o pico da cheia de set/2026 em -5,25m.
 *
 * 3. FILTRO DE LEITURA ESPÚRIA. Descarta cota acima do teto físico da régua
 *    (config 'cota_maxima_fisica'). 53 leituras de 39-50m em Encantado (onde
 *    inunda com 12m) envenenavam o treino.
 *
 * 4. CHUVA DA SUB-BACIA DE RESPOSTA RÁPIDA. Em vez da média das 7 estações de
 *    cabeceira, usa só os postos cuja chuva chega a Lajeado dentro do horizonte
 *    de previsão (config 'chuva_resposta_rapida'). Em 24h, o erro de pico caiu
 *    de 2,93m para 1,52m. Ver comentário no config para o porquê.
 *
 * 5. JANELAS DE CHUVA CURTAS. 6h/12h/24h/48h em vez de 24h/48h/72h: a janela de
 *    72h dilui o sinal do evento em curso.
 *
 * ── Limite de previsibilidade ─────────────────────────────────────────────────
 *
 * O tempo medido entre o centro de massa da chuva na cabeceira e o pico em
 * Lajeado é 11,2-12,8h nos 4 eventos observados (desvio baixo). Esse é o tempo
 * de resposta da bacia, e é o horizonte até onde a previsão é determinística:
 * a cota daqui a 12h já está "dentro" da bacia, medida pelas réguas de montante.
 * Além disso, a cota passa a depender de chuva que ainda não caiu, e o erro
 * cresce rápido — o que a validação confirma (MAE 0,48m em 12h, 1,21m em 24h,
 * e o erro de pico quadruplica). Por isso o horizonte é exposto na API com a
 * incerteza de cada passo, e não como número único.
 *
 * Referências: Chow (1988) tempo de concentração; Tallaksen (1995) recessão;
 * Collischonn & Tucci (2001) MGB-IPH; Gupta et al. (2009) KGE;
 * Todini (2017) incerteza em previsão de cheia.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

$cfg = ValeTaquari\Bootstrap::init();
$pdo = ValeTaquari\Database::get($cfg['db']);

// ── Parâmetros ────────────────────────────────────────────────────────────────

$lambda     = 0.1; // Ridge. Calibrado por busca: 0,1 minimizou o erro de pico em 24h.
$horizontes = [3, 6, 9, 12, 15, 18, 21, 24];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--lambda='))     $lambda = (float)substr($arg, 9);
    if (str_starts_with($arg, '--horizontes=')) {
        $horizontes = array_map('intval', explode(',', substr($arg, 13)));
    }
}

const STEP = 900; // 15 min em segundos

echo "=== MLR-Lag v3 — treino multi-horizonte ===" . PHP_EOL;
echo "Horizontes: " . implode('h, ', $horizontes) . "h | Lambda: {$lambda}" . PHP_EOL . PHP_EOL;

/*
 * Features de cota: para prever Lajeado(t+H) o valor mais informativo de uma
 * estação de montante é o dela em t — que chegará a Lajeado depois do seu lag.
 * Os valores defasados de cada uma entram para dar a tendência (subindo/descendo).
 *
 * Lags até Lajeado medidos por correlação cruzada da taxa horária, evento a
 * evento (ver README): Encantado ~4h, Muçum ~4,75h, Santa Tereza ~6h,
 * Linha José Júlio ~5,75h, Linha Colombo ~10,5h, Barra do Fão ~8,5h.
 *
 * As defasagens longas de Lajeado (12h, 24h) fazem o papel de condição
 * antecedente — o quanto a bacia já vinha carregada antes do evento.
 *
 * Formato: [estacao_id, lag_em_passos_de_15min, alias]
 */
$features_def = [];
$grupos = [
    'taquari_2_cota'  => [0, 4, 12],           // Encantado — t0, -1h, -3h
    'taquari_3_cota'  => [0, 4, 16],           // Muçum
    'taquari_32_cota' => [0, 8, 24],           // Santa Tereza
    'taquari_4_cota'  => [0, 8],               // Linha José Júlio
    'taquari_55_cota' => [0, 12],              // Linha Colombo
    'taquari_33_cota' => [0, 12],              // Barra do Fão
    'taquari_1_cota'  => [0, 4, 8, 16, 48, 96],// Lajeado: nível, tendência e antecedente
];
$apelidos = [
    'taquari_2_cota' => 'enc', 'taquari_3_cota' => 'muc', 'taquari_32_cota' => 'sta',
    'taquari_4_cota' => 'jjulio', 'taquari_55_cota' => 'lc', 'taquari_33_cota' => 'bf',
    'taquari_1_cota' => 'laj',
];
foreach ($grupos as $id => $lags) {
    foreach ($lags as $lag) {
        $h = $lag / 4;
        $features_def[] = [$id, $lag, $apelidos[$id] . '_t' . ($lag === 0 ? '0' : $h . 'h')];
    }
}

// Chuva: só a sub-bacia de resposta rápida, em janelas curtas.
$estacoes_chuva  = $cfg['estacoes']['chuva_resposta_rapida']
                ?? $cfg['estacoes']['chuva_cabeceira'];
$janelas_chuva_h = [6, 12, 24, 48];

// ── Carrega séries, descartando leitura espúria ───────────────────────────────

echo "Carregando dados..." . PHP_EOL;

$tetos = $cfg['evento']['cota_maxima_fisica'] ?? [];
$stmt  = $pdo->query("SELECT estacao_id, tipo, timestamp, valor FROM leituras");

$buckets   = [];
$descartadas = 0;
foreach ($stmt as $r) {
    $valor = (float)$r['valor'];
    $teto  = $tetos[$r['estacao_id']] ?? null;
    if ($teto !== null && $valor > $teto) { $descartadas++; continue; }

    $ts15 = intdiv((int)strtotime($r['timestamp']), STEP) * STEP;
    $key  = $r['estacao_id'] . '|' . $ts15;
    if (!isset($buckets[$key])) {
        $buckets[$key] = ['estacao_id' => $r['estacao_id'], 'ts' => $ts15, 'soma' => 0.0, 'n' => 0];
    }
    $buckets[$key]['soma'] += $valor;
    $buckets[$key]['n']++;
}

// $series[estacao_id][timestamp_unix] = valor médio no bucket de 15min
$series = [];
foreach ($buckets as $b) {
    $series[$b['estacao_id']][$b['ts']] = $b['soma'] / $b['n'];
}
unset($buckets);

echo "Séries: " . count($series) . " estações";
if ($descartadas > 0) echo " | {$descartadas} leituras descartadas (acima do teto físico da régua)";
echo PHP_EOL;

if (empty($series['taquari_1_cota'])) {
    echo "ERRO: sem série de cota para Lajeado (taquari_1_cota)." . PHP_EOL;
    exit(1);
}

// ── Janelas dos eventos de cheia, para a validação ────────────────────────────

$eventos = $pdo->query(
    "SELECT inicio_chuva, fim_chuva, data_pico_lajeado FROM eventos ORDER BY inicio_chuva"
)->fetchAll();

$janelas = [];
foreach ($eventos as $i => $e) {
    if (!$e['inicio_chuva']) continue;
    // Margem: 12h antes do início da chuva e 72h depois do fim, para a janela
    // pegar a recessão inteira, não só a subida.
    $ini = strtotime($e['inicio_chuva']) - 12 * 3600;
    $fim = strtotime($e['fim_chuva'] ?: $e['inicio_chuva']) + 72 * 3600;
    $janelas['E' . ($i + 1)] = [$ini, $fim];
}
echo "Eventos para validação: " . (count($janelas) ?: 0) . PHP_EOL . PHP_EOL;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Valor da estação no instante, tolerando gap de até 2 passos (±30min). */
function valorEm(array $series, string $id, int $t): ?float
{
    for ($o = 0; $o <= 2; $o++) {
        if (isset($series[$id][$t - $o * STEP])) return $series[$id][$t - $o * STEP];
        if (isset($series[$id][$t + $o * STEP])) return $series[$id][$t + $o * STEP];
    }
    return null;
}

/** Chuva média das estações informadas, acumulada nas últimas $horas. */
function chuvaAcumulada(array $series, array $ests, int $t, int $horas): float
{
    $n = $horas * 4; $tot = 0.0; $k = 0;
    foreach ($ests as $e) {
        if (!isset($series[$e])) continue;
        $s = 0.0;
        for ($i = 1; $i <= $n; $i++) $s += $series[$e][$t - $i * STEP] ?? 0.0;
        $tot += $s; $k++;
    }
    return $k > 0 ? $tot / $k : 0.0;
}

/** Monta o vetor de features no instante $t. Null se faltar alguma. */
function montarFeatures(
    array $series, int $t, array $featuresDef, array $estsChuva, array $janelasChuva
): ?array {
    $row = [];
    foreach ($featuresDef as [$id, $lag, $_alias]) {
        $v = valorEm($series, $id, $t - $lag * STEP);
        if ($v === null) return null;
        $row[] = $v;
    }
    foreach ($janelasChuva as $h) $row[] = chuvaAcumulada($series, $estsChuva, $t, $h);
    $row[] = 1.0; // intercept
    return $row;
}

function ridge(array $X, array $y, float $lambda): array
{
    $n = count($X); $p = count($X[0]);
    $A = array_fill(0, $p, array_fill(0, $p, 0.0));
    $b = array_fill(0, $p, 0.0);
    for ($i = 0; $i < $n; $i++) {
        $xi = $X[$i];
        for ($j = 0; $j < $p; $j++) {
            $xij = $xi[$j];
            $b[$j] += $xij * $y[$i];
            for ($k = 0; $k < $p; $k++) $A[$j][$k] += $xij * $xi[$k];
        }
    }
    // Regularização na diagonal, menos o intercept (última coluna)
    for ($j = 0; $j < $p - 1; $j++) $A[$j][$j] += $lambda * $n;
    return gaussianElimination($A, $b, $p);
}

function gaussianElimination(array $A, array $b, int $n): array
{
    $M = [];
    for ($i = 0; $i < $n; $i++) { $M[$i] = $A[$i]; $M[$i][$n] = $b[$i]; }

    for ($col = 0; $col < $n; $col++) {
        $maxRow = $col; $maxVal = abs($M[$col][$col]);
        for ($row = $col + 1; $row < $n; $row++) {
            if (abs($M[$row][$col]) > $maxVal) { $maxVal = abs($M[$row][$col]); $maxRow = $row; }
        }
        [$M[$col], $M[$maxRow]] = [$M[$maxRow], $M[$col]];
        if (abs($M[$col][$col]) < 1e-12) continue;

        $pivot = $M[$col][$col];
        for ($row = $col + 1; $row < $n; $row++) {
            $factor = $M[$row][$col] / $pivot;
            if ($factor === 0.0) continue;
            for ($k = $col; $k <= $n; $k++) $M[$row][$k] -= $factor * $M[$col][$k];
        }
    }

    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        if (abs($M[$i][$i]) < 1e-12) continue;
        $x[$i] = $M[$i][$n];
        for ($j = $i + 1; $j < $n; $j++) $x[$i] -= $M[$i][$j] * $x[$j];
        $x[$i] /= $M[$i][$i];
    }
    return $x;
}

function produto(array $beta, array $x): float
{
    $s = 0.0;
    foreach ($beta as $i => $b) $s += $b * $x[$i];
    return $s;
}

// ── Treino por horizonte ──────────────────────────────────────────────────────

$feature_names = array_column($features_def, 2);
foreach ($janelas_chuva_h as $h) $feature_names[] = "chuva_{$h}h";
$feature_names[] = 'intercept';

$tsLaj = array_keys($series['taquari_1_cota']);
sort($tsLaj);

$modelos = [];

foreach ($horizontes as $H) {
    $passos = $H * 4;
    $X = []; $y = []; $ts = [];

    foreach ($tsLaj as $t) {
        $tAlvo = $t + $passos * STEP;
        if (!isset($series['taquari_1_cota'][$tAlvo])) continue;
        $row = montarFeatures($series, $t, $features_def, $estacoes_chuva, $janelas_chuva_h);
        if ($row === null) continue;
        $X[] = $row; $y[] = $series['taquari_1_cota'][$tAlvo]; $ts[] = $t;
    }

    $n = count($X);
    if ($n < 50) {
        echo "H={$H}h: apenas {$n} amostras — horizonte ignorado." . PHP_EOL;
        continue;
    }

    // Modelo final: treinado com tudo
    $beta = ridge($X, $y, $lambda);

    // ── Validação leave-one-event-out ────────────────────────────────────────
    // Para cada evento: treina sem ele (e sem as amostras cujo ALVO cai dentro
    // dele, senão o alvo vaza) e mede o erro dentro do evento.
    $porEvento = []; $todosErros = [];
    foreach ($janelas as $nome => [$ini, $fim]) {
        $Xtr = []; $ytr = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $ts[$i]; $tAlvo = $t + $passos * STEP;
            $dentro = ($t >= $ini && $t < $fim) || ($tAlvo >= $ini && $tAlvo < $fim);
            if ($dentro) continue;
            $Xtr[] = $X[$i]; $ytr[] = $y[$i];
        }
        if (count($Xtr) < 50) continue;

        $betaCV = ridge($Xtr, $ytr, $lambda);

        $erros = []; $picoReal = -INF; $picoPrev = null;
        for ($i = 0; $i < $n; $i++) {
            if ($ts[$i] < $ini || $ts[$i] >= $fim) continue;
            $p = produto($betaCV, $X[$i]);
            $erros[] = $p - $y[$i];
            if ($y[$i] > $picoReal) { $picoReal = $y[$i]; $picoPrev = $p; }
        }
        if (empty($erros)) continue;

        $m = count($erros);
        $abs = array_map('abs', $erros); sort($abs);
        $porEvento[$nome] = [
            'n'         => $m,
            'mae'       => round(array_sum($abs) / $m, 4),
            'rmse'      => round(sqrt(array_sum(array_map(fn($e) => $e * $e, $erros)) / $m), 4),
            'p90'       => round($abs[max(0, (int)(0.9 * $m) - 1)], 4),
            'bias'      => round(array_sum($erros) / $m, 4),
            'erro_pico' => $picoPrev !== null ? round($picoPrev - $picoReal, 4) : null,
        ];
        $todosErros = array_merge($todosErros, $erros);
    }

    // Métricas agregadas da validação — é o que a API deve mostrar ao usuário
    $loo = null;
    if (!empty($todosErros)) {
        $m = count($todosErros);
        $abs = array_map('abs', $todosErros); sort($abs);
        $picos = array_values(array_filter(
            array_column($porEvento, 'erro_pico'), fn($v) => $v !== null
        ));
        $loo = [
            'n'             => $m,
            'mae'           => round(array_sum($abs) / $m, 4),
            'rmse'          => round(sqrt(array_sum(array_map(fn($e) => $e * $e, $todosErros)) / $m), 4),
            'p90'           => round($abs[max(0, (int)(0.9 * $m) - 1)], 4),
            'bias'          => round(array_sum($todosErros) / $m, 4),
            'erro_pico_abs' => !empty($picos)
                ? round(array_sum(array_map('abs', $picos)) / count($picos), 4) : null,
            'n_eventos'     => count($porEvento),
        ];
    }

    // Faixa de variação REAL da cota neste horizonte, em toda a série. Serve de
    // guarda de plausibilidade na API: uma regressão linear extrapola mal fora
    // do que viu no treino (a v2 chegou a projetar -10m/24h durante uma cheia),
    // e um delta além do já observado historicamente é sinal de extrapolação.
    $deltas = [];
    for ($i = 0; $i < $n; $i++) {
        // laj_t0 é a primeira feature do grupo de Lajeado; localiza pelo alias
        $idxLaj = array_search('laj_t0', $feature_names, true);
        if ($idxLaj === false) break;
        $deltas[] = $y[$i] - $X[$i][$idxLaj];
    }
    $deltaObs = null;
    if (!empty($deltas)) {
        sort($deltas);
        $q = count($deltas);
        $deltaObs = [
            'min' => round($deltas[0], 3),
            'max' => round($deltas[$q - 1], 3),
            'p1'  => round($deltas[(int)(0.01 * $q)], 3),
            'p99' => round($deltas[min($q - 1, (int)(0.99 * $q))], 3),
        ];
    }

    // Métrica no próprio treino — só para diagnóstico de ajuste, não de previsão
    $yMean = array_sum($y) / $n;
    $ssRes = 0.0; $ssTot = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $ssRes += ($y[$i] - produto($beta, $X[$i])) ** 2;
        $ssTot += ($y[$i] - $yMean) ** 2;
    }
    $nseTreino = $ssTot > 0 ? 1 - $ssRes / $ssTot : null;

    $modelos[(string)$H] = [
        'horizonte_h'    => $H,
        'n_amostras'     => $n,
        'coeficientes'   => array_combine($feature_names, array_map(fn($v) => round($v, 6), $beta)),
        'nse_treino'     => $nseTreino !== null ? round($nseTreino, 4) : null,
        'validacao_loo'  => $loo,
        'loo_por_evento' => $porEvento,
        'delta_observado' => $deltaObs,
    ];

    printf(
        "H=%2dh n=%5d | treino NSE=%.3f | LOO: MAE=%.3f RMSE=%.3f P90=%.3f pico=%.2f bias=%+.2f%s",
        $H, $n, $nseTreino ?? 0,
        $loo['mae'] ?? 0, $loo['rmse'] ?? 0, $loo['p90'] ?? 0,
        $loo['erro_pico_abs'] ?? 0, $loo['bias'] ?? 0, PHP_EOL
    );
}

if (empty($modelos)) {
    echo PHP_EOL . "ERRO: nenhum horizonte treinado." . PHP_EOL;
    exit(1);
}

// ── Salva ─────────────────────────────────────────────────────────────────────

$saida = [
    'versao'          => '3.0',
    'treinado_em'     => date('Y-m-d H:i:s'),
    'lambda'          => $lambda,
    'horizontes'      => array_keys($modelos),
    'features'        => $feature_names,
    'features_def'    => $features_def,
    'estacoes_chuva'  => $estacoes_chuva,
    'janelas_chuva_h' => $janelas_chuva_h,
    'modelos'         => $modelos,
    'metodo_validacao' => 'leave-one-event-out sobre os eventos de cheia da tabela eventos, '
                        . 'descartando amostras cujo alvo cai dentro do evento avaliado',
];

$outFile = __DIR__ . '/../data/mlr_coefs.json';
file_put_contents($outFile, json_encode($saida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo PHP_EOL . "Modelo salvo em: {$outFile}" . PHP_EOL;

// Aviso honesto sobre o alcance útil
$h24 = $modelos['24']['validacao_loo']['mae'] ?? null;
$h12 = $modelos['12']['validacao_loo']['mae'] ?? null;
if ($h12 !== null && $h24 !== null) {
    printf(
        PHP_EOL . "Alcance: erro médio %.2fm em 12h contra %.2fm em 24h. O tempo de resposta da"
        . PHP_EOL . "bacia é ~12h, então além disso a previsão depende de chuva ainda não caída."
        . PHP_EOL, $h12, $h24
    );
}
