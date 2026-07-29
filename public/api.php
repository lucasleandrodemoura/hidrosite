<?php
declare(strict_types=1);

/**
 * API REST – Vale Taquari Tempo / Coletor Hidrológico SGB
 *
 * Endpoints:
 *   GET  /                    → status do sistema
 *   GET  /status-atual        → última leitura de cada estação (independente de quando)
 *   GET  /leituras            → leituras recentes  ?horas=24 &estacao=X &tipo=cota
 *   GET  /eventos             → lista de eventos   ?status=fechado &limite=20
 *   GET  /razao-historica     → razão histórica + defasagens médias
 *   POST /projetar            → projeção de cota  body: JSON {chuva_por_estacao:{...}}
 *   POST /coletar             → dispara coleta manual (requer X-Admin-Token)
 *
 * Configure um servidor web apontando o document root para /public,
 * com rewrite de todas as rotas para api.php, ou use o servidor embutido:
 *   php -S 0.0.0.0:8080 public/api.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

use ValeTaquari\Bootstrap;
use ValeTaquari\Database;
use ValeTaquari\Collector;
use ValeTaquari\CeranCollector;
use ValeTaquari\EventDetector;
use ValeTaquari\Logger;
use ValeTaquari\Projector;

// ── bootstrap ─────────────────────────────────────────────────────────────────

$cfg    = Bootstrap::init();
$pdo    = Database::get($cfg['db']);
$logger = new Logger($cfg['log']['path'], $cfg['log']['level']);

// Serve a landing page HTML quando for requisição de browser para /
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/') ?: '/';
if ($requestPath === '' || $requestPath === '/') {
    if (str_contains($accept, 'text/html')) {
        readfile(__DIR__ . '/index.html');
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
header('X-Powered-By: ValeTaquariTempo/1.0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── roteamento simples ─────────────────────────────────────────────────────────

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = rtrim($path ?? '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $response = match (true) {
        $method === 'GET'  && $path === '/'                 => routeStatus($cfg, $pdo),
        $method === 'GET'  && $path === '/status-atual'     => routeStatusAtual($pdo, $cfg),
        $method === 'GET'  && $path === '/leituras'         => routeLeituras($pdo),
        $method === 'GET'  && $path === '/eventos'          => routeEventos($pdo),
        $method === 'GET'  && $path === '/razao-historica'    => routeRazao($pdo, $cfg),
        $method === 'GET'  && $path === '/previsao-lajeado' => routePrevisaoLajeado($pdo, $cfg),
        $method === 'POST' && $path === '/projetar'         => routeProjetar($pdo, $cfg),
        $method === 'POST' && $path === '/coletar'          => routeColetar($pdo, $cfg, $logger),
        default => throw new \InvalidArgumentException('Rota não encontrada', 404),
    };

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\InvalidArgumentException $e) {
    http_response_code((int)($e->getCode() ?: 400));
    echo json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    $logger->error('Erro na API', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno do servidor'], JSON_UNESCAPED_UNICODE);
}

// ── handlers ──────────────────────────────────────────────────────────────────

function routeStatus(array $cfg, \PDO $pdo): array
{
    $nLeituras = $pdo->query('SELECT COUNT(*) FROM leituras')->fetchColumn();
    $nEventos  = $pdo->query("SELECT COUNT(*) FROM eventos WHERE status='fechado'")->fetchColumn();
    $ultima    = $pdo->query('SELECT MAX(coletado_em) FROM leituras')->fetchColumn();

    return [
        'sistema'        => 'Vale Taquari Tempo – Coletor Hidrológico SGB',
        'versao'         => '1.0.0',
        'licenca'        => 'ValeTaquariTempo Open License v1.0 (não-comercial, share-alike)',
        'status'         => 'online',
        'leituras_total' => (int)$nLeituras,
        'eventos_fechados' => (int)$nEventos,
        'ultima_coleta'  => $ultima,
        'timestamp'      => date('Y-m-d H:i:s'),
        'estacoes' => [
            'chuva' => $cfg['estacoes']['chuva'],
            'cota'  => $cfg['estacoes']['cota'],
        ],
    ];
}

function routeStatusAtual(\PDO $pdo, array $cfg): array
{
    // Última leitura de cada estação
    $stmt = $pdo->query(
        "SELECT DISTINCT ON (estacao_id)
                l.estacao_id, e.nome, e.tipo, l.timestamp, l.valor, l.coletado_em
         FROM leituras l
         JOIN estacoes e ON e.id = l.estacao_id
         ORDER BY l.estacao_id, l.timestamp DESC"
    );
    $rows = $stmt->fetchAll();

    // Tendência: compara última leitura com a de ~1h atrás (4 leituras de 15min)
    $stmtTend = $pdo->prepare(
        "SELECT valor, timestamp
         FROM leituras
         WHERE estacao_id = :id AND tipo = 'cota'
         ORDER BY timestamp DESC
         LIMIT 8"
    );

    // Acumulado de chuva nas últimas 24h por estação
    $desde24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $stmtAcum = $pdo->prepare(
        "SELECT estacao_id, SUM(valor) AS total
         FROM leituras
         WHERE tipo = 'chuva' AND timestamp >= :desde
         GROUP BY estacao_id"
    );
    $stmtAcum->execute([':desde' => $desde24h]);
    $acum24h = [];
    foreach ($stmtAcum->fetchAll() as $r) {
        $acum24h[$r['estacao_id']] = round((float)$r['total'], 1);
    }

    $cotas  = [];
    $chuvas = [];
    $vazoes = [];

    foreach ($rows as $r) {
        $id = $r['estacao_id'];
        $entry = [
            'nome'      => $r['nome'],
            'valor'     => (float)$r['valor'],
            'timestamp' => $r['timestamp'],
        ];

        if ($r['tipo'] === 'vazao') {
            $vazoes[$id] = $entry;
            continue;
        }

        if ($r['tipo'] === 'cota') {
            $cAtencao   = $cfg['evento']['cota_atencao'][$id]   ?? null;
            $cInundacao = $cfg['evento']['cota_inundacao'][$id] ?? null;
            $v = (float)$r['valor'];
            $entry['cota_atencao']   = $cAtencao;
            $entry['cota_inundacao'] = $cInundacao;
            $entry['em_atencao']     = $cAtencao   !== null && $v >= $cAtencao;
            $entry['em_cheia']       = $cInundacao !== null && $v >= $cInundacao;

            // Calcula tendência com as últimas 8 leituras (~2h)
            $stmtTend->execute([':id' => $id]);
            $leituras = $stmtTend->fetchAll();

            if (count($leituras) >= 2) {
                $mais_recente = (float)$leituras[0]['valor'];
                // Compara com a leitura de ~1h atrás (índice 4) ou a mais antiga disponível
                $ref_idx  = min(4, count($leituras) - 1);
                $referencia = (float)$leituras[$ref_idx]['valor'];
                $delta    = round($mais_recente - $referencia, 3); // metros
                $intervalo_h = (strtotime($leituras[0]['timestamp']) - strtotime($leituras[$ref_idx]['timestamp'])) / 3600;

                // Limiar: variação > 2cm no período para considerar tendência
                if (abs($delta) < 0.02) {
                    $tendencia = 'estavel';
                } elseif ($delta > 0) {
                    $tendencia = 'subindo';
                } else {
                    $tendencia = 'baixando';
                }

                $taxa_hora = $intervalo_h > 0 ? round($delta / $intervalo_h, 3) : 0;

                $entry['tendencia']      = $tendencia;
                $entry['delta_m']        = $delta;
                $entry['taxa_hora_m']    = $taxa_hora; // metros por hora (positivo = subindo)
                $entry['intervalo_h']    = round($intervalo_h, 1);
            } else {
                $entry['tendencia']   = 'sem_dados';
                $entry['delta_m']     = null;
                $entry['taxa_hora_m'] = null;
            }

            $cotas[$id] = $entry;
        } else {
            $entry['acumulado_24h'] = $acum24h[$id] ?? 0.0;
            $chuvas[$id] = $entry;
        }
    }

    return [
        'timestamp' => date('Y-m-d H:i:s'),
        'cotas'     => $cotas,
        'chuvas'    => $chuvas,
        'vazoes'    => $vazoes,
    ];
}

function routeLeituras(\PDO $pdo): array
{
    $horasRaw = (int)($_GET['horas'] ?? 24);
    $horas    = $horasRaw === 0 ? 0 : min($horasRaw, 87600); // 0 = todo histórico, senão máx 10 anos
    $estacao  = $_GET['estacao'] ?? null;
    $tipo     = $_GET['tipo']    ?? null;
    $limite   = min((int)($_GET['limite']   ?? 500), 5000);

    $where  = [];
    $params = [];

    if ($horas > 0) {
        $where[]          = 'l.timestamp >= :desde';
        $params[':desde'] = date('Y-m-d H:i:s', strtotime("-{$horas} hours"));
    }
    if ($estacao !== null) {
        $where[]            = 'l.estacao_id = :estacao';
        $params[':estacao'] = $estacao;
    }
    if ($tipo !== null && in_array($tipo, ['chuva', 'cota', 'vazao'], true)) {
        $where[]         = 'l.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Para todo histórico sem limite de tempo, aumenta o limite de linhas
    $limiteEfetivo = ($horas === 0) ? min($limite, 50000) : $limite;

    $sql  = "SELECT l.estacao_id, e.nome, l.tipo, l.timestamp, l.valor
             FROM leituras l
             JOIN estacoes e ON e.id = l.estacao_id
             {$whereClause}
             ORDER BY l.timestamp DESC
             LIMIT {$limiteEfetivo}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Agrupa por estação para resposta mais estruturada
    $agrupado = [];
    foreach ($rows as $r) {
        $agrupado[$r['estacao_id']] ??= ['nome' => $r['nome'], 'tipo' => $r['tipo'], 'series' => []];
        $agrupado[$r['estacao_id']]['series'][] = [
            'ts'    => $r['timestamp'],
            'valor' => (float)$r['valor'],
        ];
    }

    return [
        'periodo_horas' => $horas,
        'total'         => count($rows),
        'estacoes'      => $agrupado,
    ];
}

function routeEventos(\PDO $pdo): array
{
    $status = $_GET['status'] ?? 'todos';
    $limite = min((int)($_GET['limite'] ?? 20), 100);

    $where  = $status !== 'todos' ? "WHERE status = :status" : '';
    $params = $status !== 'todos' ? [':status' => $status] : [];

    $stmt = $pdo->prepare(
        "SELECT id, inicio_chuva, fim_chuva,
                chuva_media_cabeceira, cota_maxima_lajeado,
                cota_maxima_mucum, cota_maxima_encantado,
                data_pico_lajeado, excesso_cota_lajeado,
                razao_calculada, defasagem_cabeceira_mucum_h,
                defasagem_mucum_encantado_h, defasagem_encantado_lajeado_h,
                status, fechado_em
         FROM eventos {$where}
         ORDER BY COALESCE(inicio_chuva, criado_em) DESC
         LIMIT {$limite}"
    );
    $stmt->execute($params);
    $eventos = $stmt->fetchAll();

    return [
        'total'   => count($eventos),
        'eventos' => array_map(function ($e) {
            $e['chuva_acumulada_por_estacao'] = null; // omitido na listagem
            foreach (['chuva_media_cabeceira','cota_maxima_lajeado','cota_maxima_mucum',
                      'cota_maxima_encantado','excesso_cota_lajeado','razao_calculada',
                      'defasagem_cabeceira_mucum_h','defasagem_mucum_encantado_h',
                      'defasagem_encantado_lajeado_h'] as $f) {
                if ($e[$f] !== null) $e[$f] = (float)$e[$f];
            }
            return $e;
        }, $eventos),
    ];
}

function routeRazao(\PDO $pdo, array $cfg): array
{
    $proj     = new Projector($pdo, $cfg);
    $historico = $proj->razaoHistorica();

    $cotaInundacao = $cfg['evento']['cota_inundacao']['taquari_1_cota'];

    return [
        'razao_historica'     => $historico,
        'cota_inundacao_m'    => $cotaInundacao,
        'interpretacao'       => $historico['razao_media'] !== null
            ? sprintf(
                'Cada metro de excesso de cota em Lajeado requer %.1f mm de chuva média nas cabeceiras.',
                $historico['razao_media']
              )
            : 'Aguardando eventos fechados para calibrar a razão.',
    ];
}

function routePrevisaoLajeado(\PDO $pdo, array $cfg): array
{
    $pisoLeito = $cfg['evento']['cota_minima_leito']['taquari_1_cota'] ?? 12.00;
    // Defasagens medidas empiricamente no evento de 22-23/07/2026 (pico-a-pico vs Lajeado).
    // Encantado: cross-correlação 2.8h (pico suspeito no BD). Demais: pico-a-pico observado.
    // Pesos estimados pela importância hidrológica relativa de cada tributário.
    $upstream = [
        'taquari_2_cota'  => ['nome' => 'Encantado',         'lag_h' => 3,  'peso' => 0.45],
        'taquari_3_cota'  => ['nome' => 'Muçum',             'lag_h' => 11, 'peso' => 0.30],
        'taquari_32_cota' => ['nome' => 'Santa Tereza',      'lag_h' => 13, 'peso' => 0.10],
        'taquari_4_cota'  => ['nome' => 'Linha José Júlio',  'lag_h' => 16, 'peso' => 0.08],
        'taquari_55_cota' => ['nome' => 'Linha Colombo',     'lag_h' => 20, 'peso' => 0.04],
        'taquari_33_cota' => ['nome' => 'Barra do Fão',      'lag_h' => 22, 'peso' => 0.03],
    ];

    // Cota atual e taxa de Lajeado
    $stmtLaj = $pdo->query(
        "SELECT valor, timestamp FROM leituras
         WHERE estacao_id = 'taquari_1_cota' AND tipo = 'cota'
         ORDER BY timestamp DESC LIMIT 1"
    );
    $rowLaj = $stmtLaj->fetch();
    if (!$rowLaj) {
        return ['status' => 'sem_dados', 'mensagem' => 'Sem leituras de cota para Lajeado.'];
    }
    $cotaAtual = (float)$rowLaj['valor'];

    // Busca taxas de variação de cada estação upstream (últimas ~2h = 8 leituras de 15min)
    $stmtTaxa = $pdo->prepare(
        "SELECT valor, timestamp FROM leituras
         WHERE estacao_id = :id AND tipo = 'cota'
         ORDER BY timestamp DESC LIMIT 8"
    );

    $deltaPonderado = 0.0;
    $detalhes       = [];
    $nContrib       = 0;

    foreach ($upstream as $id => $info) {
        $stmtTaxa->execute([':id' => $id]);
        $leituras = $stmtTaxa->fetchAll();
        if (count($leituras) < 2) continue;

        $maisRecente = (float)$leituras[0]['valor'];
        $refIdx      = min(4, count($leituras) - 1);
        $referencia  = (float)$leituras[$refIdx]['valor'];
        $delta       = $maisRecente - $referencia;
        $intervaloH  = (strtotime($leituras[0]['timestamp']) - strtotime($leituras[$refIdx]['timestamp'])) / 3600;
        $taxaHora    = $intervaloH > 0.1 ? $delta / $intervaloH : 0.0;

        // Horas dentro da janela de 24h que ainda vão impactar Lajeado
        $horasUteis = max(0, 24 - $info['lag_h']);

        // Contribuição delta ponderada pelo peso da estação
        $contrib = $taxaHora * $horasUteis * $info['peso'];
        $deltaPonderado += $contrib;
        $nContrib++;

        $tendencia = abs($taxaHora) < 0.005 ? 'estavel'
                   : ($taxaHora > 0 ? 'subindo' : 'baixando');

        $detalhes[$id] = [
            'nome'           => $info['nome'],
            'cota_atual_m'   => round($maisRecente, 2),
            'taxa_hora_m'    => round($taxaHora, 3),
            'tendencia'      => $tendencia,
            'defasagem_h'    => $info['lag_h'],
            'horas_uteis'    => $horasUteis,
            'contribuicao_m' => round($contrib, 3),
        ];
    }

    // Chuva acumulada nas últimas 24h como indicador de risco adicional
    $desde24h  = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $stmtChuva = $pdo->prepare(
        "SELECT estacao_id, SUM(valor) AS total
         FROM leituras
         WHERE tipo = 'chuva' AND timestamp >= :desde
         GROUP BY estacao_id"
    );
    $stmtChuva->execute([':desde' => $desde24h]);
    $chuvasRows  = $stmtChuva->fetchAll();
    $chuvaMedia  = 0.0;
    $chuvaMaxima = 0.0;
    if (!empty($chuvasRows)) {
        $totais      = array_map('floatval', array_column($chuvasRows, 'total'));
        $chuvaMedia  = array_sum($totais) / count($totais);
        $chuvaMaxima = max($totais);
    }

    // Se há razão histórica calibrada, usa para adicionar contribuição da chuva recente
    $deltaChuvaBruto = 0.0;
    $razaoMedia = 0.0;
    $stmtR = $pdo->query(
        "SELECT AVG(razao_calculada) FROM eventos
         WHERE status = 'fechado' AND razao_calculada IS NOT NULL AND razao_calculada > 0"
    );
    $razaoMedia = (float)($stmtR->fetchColumn() ?: 0);

    // AMC (Antecedent Moisture Condition): mínimo de Lajeado nas últimas 72h como proxy
    // de encharcamento do solo. Solo encharcado = mais escoamento superficial = menor razão efetiva.
    // Evento jul/2026b: AMC extra de +0.51m sobre o leito reduziu a "capacidade de absorção" da bacia.
    $amcFator = 1.0;
    $cotaMinima72h = $cotaAtual;
    $stmtAmc = $pdo->query(
        "SELECT MIN(valor::float) FROM leituras
         WHERE estacao_id = 'taquari_1_cota'
           AND timestamp >= NOW() - INTERVAL '72 hours'"
    );
    $cotaMinima72h = (float)($stmtAmc->fetchColumn() ?: $cotaAtual);
    // Excesso acima de 0.5m sobre o piso do leito indica bacia pré-saturada
    $excessoAMC = max(0.0, $cotaMinima72h - ($pisoLeito + 0.5));
    $amcFator   = 1.0 + min($excessoAMC * 0.20, 0.40); // até +40% de amplificação

    if ($razaoMedia > 0 && $chuvaMedia > 5) {
        // Chuva das últimas 12h ainda vai chegar em Lajeado (lag médio ~16h desde cabeceira)
        $desde12h    = date('Y-m-d H:i:s', strtotime('-12 hours'));
        $stmtCh12    = $pdo->prepare(
            "SELECT SUM(valor) / NULLIF(COUNT(DISTINCT estacao_id), 0) AS media
             FROM leituras WHERE tipo = 'chuva' AND timestamp >= :desde"
        );
        $stmtCh12->execute([':desde' => $desde12h]);
        $chuva12h = (float)($stmtCh12->fetchColumn() ?: 0);

        // Estima impacto: chuva recente / razão × fator conservador × AMC
        $deltaChuvaBruto = ($chuva12h * 0.5) / $razaoMedia * $amcFator;
        $deltaPonderado += $deltaChuvaBruto;
    }

    $cfgAtencao   = $cfg['evento']['cota_atencao']['taquari_1_cota']   ?? 15.0;
    $cfgInundacao = $cfg['evento']['cota_inundacao']['taquari_1_cota'] ?? 19.0;

    // ── Tenta usar modelo MLR calibrado se disponível ─────────────────────────
    $mlrFile = __DIR__ . '/../data/mlr_coefs.json';
    if (file_exists($mlrFile)) {
        $mlrResult = aplicarMLR($mlrFile, $pdo, $cotaAtual);
        if ($mlrResult !== null) {
            $cotaProj = max($mlrResult['cota_projetada'], $pisoLeito);
            $situacao = 'normal';
            if ($cotaProj >= $cfgInundacao) $situacao = 'cheia';
            elseif ($cotaProj >= $cfgAtencao) $situacao = 'atencao';

            return [
                'metodo'               => 'mlr_calibrado',
                'cota_atual_m'         => $cotaAtual,
                'cota_projetada_24h_m' => round($cotaProj, 2),
                'delta_esperado_m'     => round($cotaProj - $cotaAtual, 2),
                'situacao_projetada'   => $situacao,
                'cota_atencao_m'       => $cfgAtencao,
                'cota_inundacao_m'     => $cfgInundacao,
                'chuva_media_24h_mm'   => round($chuvaMedia, 1),
                'chuva_maxima_24h_mm'  => round($chuvaMaxima, 1),
                'confianca'            => $mlrResult['confianca'],
                'metricas_modelo'      => $mlrResult['metricas'],
                'n_amostras_treino'    => $mlrResult['n_amostras'],
                'treinado_em'          => $mlrResult['treinado_em'],
                'estacoes_upstream'    => $detalhes,
                'aviso'                => 'Previsão por Regressão Linear Múltipla com defasagens '
                                       . '(MLR-Lag). NSE=' . $mlrResult['metricas']['nse'] . '.',
            ];
        }
    }

    // ── Fallback: heurístico de tendência ─────────────────────────────────────
    $cotaProjetada = round(max($cotaAtual + $deltaPonderado, $pisoLeito), 2);
    $situacao = 'normal';
    if ($cotaProjetada >= $cfgInundacao) $situacao = 'cheia';
    elseif ($cotaProjetada >= $cfgAtencao) $situacao = 'atencao';

    $confianca = match (true) {
        $nContrib >= 4 && $razaoMedia > 0 => 'baixa',
        $nContrib >= 3                     => 'baixa',
        $nContrib >= 2                     => 'muito_baixa',
        default                            => 'insuficiente',
    };

    return [
        'metodo'                => $razaoMedia > 0 ? 'heuristico_com_razao' : 'heuristico',
        'cota_atual_m'          => $cotaAtual,
        'cota_projetada_24h_m'  => $cotaProjetada,
        'delta_esperado_m'      => round($deltaPonderado, 2),
        'situacao_projetada'    => $situacao,
        'cota_atencao_m'        => $cfgAtencao,
        'cota_inundacao_m'      => $cfgInundacao,
        'chuva_media_24h_mm'    => round($chuvaMedia, 1),
        'chuva_maxima_24h_mm'   => round($chuvaMaxima, 1),
        'delta_chuva_m'         => round($deltaChuvaBruto, 3),
        'amc_cota_minima_72h_m' => round($cotaMinima72h, 2),
        'amc_fator'             => round($amcFator, 3),
        'confianca'             => $confianca,
        'n_estacoes_contrib'    => $nContrib,
        'estacoes_upstream'     => $detalhes,
        'aviso'                 => 'Estimativa heurística com fator AMC (solo encharcado). '
                                 . 'Execute scripts/train_mlr.php para ativar o modelo calibrado.',
    ];
}

/**
 * Aplica o modelo MLR salvo em JSON sobre as leituras atuais do banco.
 * Retorna null se os dados atuais forem insuficientes para aplicar o modelo.
 */
function aplicarMLR(string $mlrFile, \PDO $pdo, float $cotaAtual): ?array
{
    $model = json_decode(file_get_contents($mlrFile), true);
    if (!$model || empty($model['coeficientes'])) return null;

    $coefs       = $model['coeficientes'];
    $featDef     = $model['features_def'];  // [estacao_id, lag_steps, alias]
    $estsChuva   = $model['estacoes_chuva'];
    $step15      = 900; // 15min em segundos

    // Calcula janela de dados necessária: max(lag máx das features, 72h de chuva) + buffer.
    // Bug fix v2: originalmente carregava apenas 12h, mas chuva_48h e chuva_72h
    // precisam de até 72h de histórico. Sem isso, os acumulados de chuva ficavam truncados.
    $maxLagSteps  = max(array_column($featDef, 1));
    $maxLagHoras  = (int)ceil($maxLagSteps * 15 / 60); // steps × 15min → horas
    $janelaHoras  = max($maxLagHoras, 72) + 4;         // 72h de chuva + 4h de folga
    $desde = date('Y-m-d H:i:s', time() - $janelaHoras * 3600);
    $stmt  = $pdo->prepare(
        "SELECT estacao_id, tipo,
                date_trunc('minute', timestamp) -
                  (EXTRACT(MINUTE FROM timestamp)::int % 15) * INTERVAL '1 minute' AS ts_15,
                AVG(valor::float) AS valor
         FROM leituras
         WHERE timestamp >= :desde
         GROUP BY estacao_id, tipo, ts_15"
    );
    $stmt->execute([':desde' => $desde]);

    $series = [];
    foreach ($stmt->fetchAll() as $r) {
        $ts = strtotime($r['ts_15']);
        $series[$r['estacao_id']][$ts] = (float)$r['valor'];
    }

    // Timestamp base: última leitura de Lajeado
    if (empty($series['taquari_1_cota'])) return null;
    $tBase = max(array_keys($series['taquari_1_cota']));

    // Monta vetor de features — busca leitura mais próxima em janela de ±2h
    $vec = [];
    foreach ($featDef as [$id, $lagSteps, $alias]) {
        $tsFeat = $tBase - $lagSteps * $step15;
        $v = null;
        for ($off = 0; $off <= 8; $off++) { // 8 passos × 15min = 2h
            $v = $series[$id][$tsFeat + $off * $step15]
              ?? $series[$id][$tsFeat - $off * $step15]
              ?? null;
            if ($v !== null) break;
        }
        if ($v === null) return null;
        $vec[] = $v;
    }

    // Chuva 24h, 48h e 72h (janelas calibradas nos eventos jul/2026)
    $chuva24h = 0.0; $chuva48h = 0.0; $chuva72h = 0.0; $nch = 0;
    foreach ($estsChuva as $eid) {
        if (!isset($series[$eid])) continue;
        $s24 = 0.0; $s48 = 0.0; $s72 = 0.0;
        for ($i = 1; $i <= 96;  $i++) $s24 += $series[$eid][$tBase - $i * $step15] ?? 0;
        for ($i = 1; $i <= 192; $i++) $s48 += $series[$eid][$tBase - $i * $step15] ?? 0;
        for ($i = 1; $i <= 288; $i++) $s72 += $series[$eid][$tBase - $i * $step15] ?? 0;
        $chuva24h += $s24; $chuva48h += $s48; $chuva72h += $s72; $nch++;
    }
    $vec[] = $nch > 0 ? $chuva24h / $nch : 0.0;
    $vec[] = $nch > 0 ? $chuva48h / $nch : 0.0;
    // chuva_72h: incluída apenas se o modelo foi treinado com ela (v2+)
    if (in_array('chuva_72h', $model['features'], true)) {
        $vec[] = $nch > 0 ? $chuva72h / $nch : 0.0;
    }
    $vec[] = 1.0; // intercept

    // Aplica β · x
    $coefVals = array_values($coefs);
    $pred = 0.0;
    foreach ($coefVals as $i => $b) {
        $pred += $b * ($vec[$i] ?? 0.0);
    }

    // Confiança baseada em NSE e número de amostras de treino
    $nse = $model['metricas']['nse'] ?? 0;
    $confianca = match (true) {
        $nse >= 0.85 && $model['n_amostras'] >= 500 => 'moderada',
        $nse >= 0.70 && $model['n_amostras'] >= 100 => 'baixa',
        default                                       => 'muito_baixa',
    };

    return [
        'cota_projetada' => $pred,
        'confianca'      => $confianca,
        'metricas'       => $model['metricas'],
        'n_amostras'     => $model['n_amostras'],
        'treinado_em'    => $model['treinado_em'],
    ];
}

function routeProjetar(\PDO $pdo, array $cfg): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body ?: '{}', true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException('JSON inválido no corpo da requisição', 400);
    }

    $chuvaPorEstacao = $data['chuva_por_estacao'] ?? null;

    // Aceita também formato simples: {"chuva_mm": 80} → aplica para todas as estações
    if ($chuvaPorEstacao === null && isset($data['chuva_mm'])) {
        $mm = (float)$data['chuva_mm'];
        $chuvaPorEstacao = array_fill_keys(array_keys($cfg['estacoes']['chuva']), $mm);
    }

    if (empty($chuvaPorEstacao)) {
        throw new \InvalidArgumentException(
            'Forneça "chuva_por_estacao" (objeto estacao→mm) ou "chuva_mm" (valor único).', 400
        );
    }

    // Valida que os valores são numéricos
    foreach ($chuvaPorEstacao as $k => $v) {
        if (!is_numeric($v)) {
            throw new \InvalidArgumentException("Valor inválido para estação '{$k}'", 400);
        }
    }

    $proj = new Projector($pdo, $cfg);
    return $proj->projetar($chuvaPorEstacao);
}

function routeColetar(\PDO $pdo, array $cfg, Logger $logger): array
{
    // Proteção mínima: token de admin (opcional mas recomendado)
    $adminToken = $_ENV['ADMIN_TOKEN'] ?? null;
    if ($adminToken !== null) {
        $tokenFornecido = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
        if (!hash_equals($adminToken, $tokenFornecido)) {
            throw new \InvalidArgumentException('Token de administrador inválido', 403);
        }
    }

    $coletor   = new Collector($pdo, $logger, $cfg);
    $resultado = $coletor->coletarTodas();

    // Coleta dados da UHE Castro Alves (CERAN) — vazão afluente e defluência
    $ceran          = new CeranCollector($pdo, $logger, $cfg);
    $resultadoCeran = $ceran->coletar();

    $detector  = new EventDetector($pdo, $logger, $cfg);
    $acao      = $detector->verificar();

    $totalNovas = array_sum(array_column($resultado, 'novas')) + $resultadoCeran['novas'];
    $totalErros = count(array_filter($resultado, fn($r) => $r['erro'] !== null))
                + ($resultadoCeran['erro'] !== null ? 1 : 0);

    return [
        'leituras_novas'   => $totalNovas,
        'erros'            => $totalErros,
        'detector'         => $acao,
        'detalhes'         => $resultado,
        'ceran_castro_alves' => $resultadoCeran,
    ];
}
