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
 *   GET  /previsao-lajeado    → projeção 24h em Lajeado + curva_horaria (1 ponto por hora futura)
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
        $method === 'GET'  && $path === '/status'           => routeStatus($cfg, $pdo),
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
    // Usa o último id inserido, não MAX(coletado_em): linhas antigas gravadas
    // antes da correção de timezone (UTC em vez de America/Sao_Paulo) têm
    // coletado_em maior como string e venceriam o MAX de forma incorreta.
    $ultima    = $pdo->query('SELECT coletado_em FROM leituras ORDER BY id DESC LIMIT 1')->fetchColumn();

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
        "SELECT l.estacao_id, e.nome, e.tipo, l.timestamp, l.valor, l.coletado_em
         FROM leituras l
         JOIN estacoes e ON e.id = l.estacao_id
         WHERE l.timestamp = (
             SELECT MAX(l2.timestamp) FROM leituras l2 WHERE l2.estacao_id = l.estacao_id
         )
         ORDER BY l.estacao_id"
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

    $stmtPico = $pdo->prepare(
        "SELECT valor, timestamp FROM leituras
         WHERE estacao_id = :id AND tipo = 'cota' AND timestamp >= :inicio
         ORDER BY valor DESC LIMIT 1"
    );

    return [
        'total'   => count($eventos),
        'eventos' => array_map(function ($e) use ($stmtPico) {
            $e['chuva_acumulada_por_estacao'] = null; // omitido na listagem

            // Evento ainda aberto: cota_maxima_* fica null no banco até o
            // fechamento, mas o rio pode continuar subindo por dias. Calcula
            // o pico ao vivo a partir das leituras, pra não exibir "—" ou um
            // valor congelado enquanto a cheia ainda está em curso.
            if ($e['status'] === 'aberto' && $e['inicio_chuva']) {
                $mapa = [
                    'cota_maxima_lajeado'   => ['taquari_1_cota', 'data_pico_lajeado'],
                    'cota_maxima_mucum'     => ['taquari_3_cota', null],
                    'cota_maxima_encantado' => ['taquari_2_cota', null],
                ];
                foreach ($mapa as $campo => [$estacaoId, $campoData]) {
                    $stmtPico->execute([':id' => $estacaoId, ':inicio' => $e['inicio_chuva']]);
                    $pico = $stmtPico->fetch();
                    if ($pico) {
                        $e[$campo] = (float)$pico['valor'];
                        if ($campoData) $e[$campoData] = $pico['timestamp'];
                    }
                }
                $e['cota_ao_vivo'] = true;
            }

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
    $pisos     = $cfg['evento']['cota_minima_leito'];
    // Defasagens recalibradas por correlação cruzada (Pearson) da TAXA HORÁRIA
    // de cada estação contra Lajeado, medida evento a evento (4 eventos de cheia
    // de jul e set/2026) em vez de uma única correlação sobre a série inteira.
    // Correlacionar a taxa, e não o nível, evita que o nível de base comum às
    // duas réguas domine e achate o pico da correlação.
    //
    // Lag adotado = mediana entre eventos, descartando evento em que a
    // correlação ficou abaixo de 0,5 (sinal fraco, lag não identificável):
    //   Encantado 4,25/3,5/4,25h → 4h (era 3h)   r≈0,87-0,91
    //   Muçum 5,0/4,75/4,0h      → 4,75h          r≈0,81-0,85
    //   Santa Tereza 5,75/6,25h  → 6h             r≈0,73-0,77
    //   Linha José Júlio ~5,75h  → 5,75h          r≈0,61-0,76
    //   Linha Colombo ~10,5h     → 10,5h (era 11,25h)
    //   Barra do Fão 7,25-9,75h  → 8,5h (era 13,25h — superestimado em 1,6x)
    //
    // Pesos: mantidos da estimativa original, ordenados pela força da
    // correlação com Lajeado (Encantado e Muçum dominam). Como as estações
    // estão em série no mesmo rio, somar contribuição de todas conta a mesma
    // água mais de uma vez — os pesos existem para amortecer isso, e o
    // heurístico é só a salvaguarda de quando o MLR não se aplica.
    $upstream = [
        'taquari_2_cota'  => ['nome' => 'Encantado',         'lag_h' => 4,    'peso' => 0.45],
        'taquari_3_cota'  => ['nome' => 'Muçum',             'lag_h' => 4.75, 'peso' => 0.30],
        'taquari_32_cota' => ['nome' => 'Santa Tereza',      'lag_h' => 6,    'peso' => 0.10],
        'taquari_4_cota'  => ['nome' => 'Linha José Júlio',  'lag_h' => 5.75, 'peso' => 0.08],
        'taquari_55_cota' => ['nome' => 'Linha Colombo',     'lag_h' => 10.5, 'peso' => 0.04],
        'taquari_33_cota' => ['nome' => 'Barra do Fão',      'lag_h' => 8.5,  'peso' => 0.03],
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
    $stationRates   = []; // para construir a curva hora-a-hora abaixo
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
        $piso        = $pisos[$id] ?? 0.0;
        $excesso0    = max(0.0, $maisRecente - $piso);

        // Horas dentro da janela de 24h que ainda vão impactar Lajeado
        $horasUteis = max(0, 24 - $info['lag_h']);

        // Contribuição delta ponderada pelo peso da estação
        $contrib = contribuicaoUpstream($taxaHora, $excesso0, $horasUteis, $info['peso']);
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

        $stationRates[$id] = [
            'taxa_hora' => $taxaHora,
            'peso'      => $info['peso'],
            'lag_h'     => $info['lag_h'],
            'excesso0'  => $excesso0,
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
    $desde72h = date('Y-m-d H:i:s', strtotime('-72 hours'));
    $stmtAmc = $pdo->prepare(
        "SELECT MIN(valor) FROM leituras
         WHERE estacao_id = 'taquari_1_cota'
           AND timestamp >= :desde"
    );
    $stmtAmc->execute([':desde' => $desde72h]);
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
        $mlrResult = aplicarMLR($mlrFile, $pdo, $cfg, $cotaAtual);

        if ($mlrResult !== null && !empty($mlrResult['pontos'])) {
            // A curva é feita dos horizontes efetivamente previstos pelo modelo
            // (3h, 6h, ... 24h) e interpolada só nos vãos entre eles.
            $curvaHoraria = construirCurvaHorariaMLR(
                $mlrResult['pontos'], $cotaAtual, $pisoLeito, 24
            );

            $ultimo   = end($mlrResult['pontos']);
            $cotaProj = max($ultimo['cota_projetada_m'], $pisoLeito);

            // Situação avaliada pelo pior caso da curva, não só pelo ponto final:
            // numa cheia o pico pode acontecer em 12h e já estar baixando em 24h,
            // e é o pico que importa para alerta.
            $picoCurva = max(array_column($curvaHoraria, 'cota_projetada_m'));
            $situacao  = 'normal';
            if ($picoCurva >= $cfgInundacao)     $situacao = 'cheia';
            elseif ($picoCurva >= $cfgAtencao)   $situacao = 'atencao';

            // Horizonte confiável: último passo cujo erro médio validado ainda
            // fica em meio metro — o limite útil para decisão de alerta. Na série
            // atual isso dá 12h, que é justamente o tempo de resposta da bacia
            // medido entre a chuva na cabeceira e o pico em Lajeado (11,2-12,8h).
            // A coincidência não é acaso: até aí a água que definirá a cota já
            // caiu e está medida nas réguas de montante; depois disso a previsão
            // passa a depender de chuva que ainda não caiu.
            $horizonteConfiavel = null;
            foreach ($mlrResult['pontos'] as $p) {
                if (($p['erro_medio_m'] ?? 99) <= 0.5) $horizonteConfiavel = $p['horizonte_h'];
            }

            return [
                'metodo'               => 'mlr_multi_horizonte',
                'cota_atual_m'         => $cotaAtual,
                'cota_projetada_24h_m' => round($cotaProj, 2),
                'delta_esperado_m'     => round($cotaProj - $cotaAtual, 2),
                'pico_projetado_m'     => round($picoCurva, 2),
                'situacao_projetada'   => $situacao,
                'cota_atencao_m'       => $cfgAtencao,
                'cota_inundacao_m'     => $cfgInundacao,
                'chuva_media_24h_mm'   => round($chuvaMedia, 1),
                'chuva_maxima_24h_mm'  => round($chuvaMaxima, 1),
                'confianca'            => $mlrResult['confianca'],
                'horizonte_confiavel_h' => $horizonteConfiavel,
                'previsoes_por_horizonte' => $mlrResult['pontos'],
                'validacao'            => $mlrResult['validacao'],
                'treinado_em'          => $mlrResult['treinado_em'],
                'versao_modelo'        => $mlrResult['versao'],
                'estacoes_upstream'    => $detalhes,
                'curva_horaria'        => $curvaHoraria,
                'metodo_curva'         => 'mlr_por_horizonte',
                'horizontes_rejeitados' => $mlrResult['rejeitados'],
                'aviso'                => sprintf(
                    'Previsão por Regressão Linear Múltipla com defasagens (MLR-Lag v%s), '
                    . 'um modelo por horizonte. Erro médio validado deixando cada cheia fora '
                    . 'do treino: %.2fm em 12h e %.2fm em 24h. O tempo de resposta da bacia é '
                    . '~12h, então além disso a cota passa a depender de chuva que ainda não caiu.',
                    $mlrResult['versao'],
                    $mlrResult['validacao']['mae_12h'] ?? 0,
                    $mlrResult['validacao']['mae_24h'] ?? 0
                ),
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

    // Curva hora-a-hora: distribui a contribuição de cada estação upstream ao
    // longo das próximas 24h conforme sua defasagem (lag) até Lajeado — ou seja,
    // extrapola a taxa de subida/descida observada em cada estação nas últimas
    // leituras, respeitando quando ela efetivamente chega em Lajeado.
    $curvaHoraria = construirCurvaHorariaHeuristica(
        $stationRates, $cotaAtual, $pisoLeito, $deltaChuvaBruto, 24
    );

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
        'curva_horaria'         => $curvaHoraria,
        'metodo_curva'          => 'extrapolacao_taxas_upstream',
        'mlr_rejeitado_extrapolacao' => isset($mlrResult) && $mlrResult !== null && !$mlrPlausivel,
        'aviso'                 => (isset($mlrResult) && $mlrResult !== null && !$mlrPlausivel)
            ? sprintf(
                'Modelo MLR previu delta de %.2fm/24h, fora da faixa plausível (>5x RMSE de treino) — '
                . 'provável extrapolação fora do que o treino viu. Usando heurístico como salvaguarda.',
                $mlrResult['cota_projetada'] - $cotaAtual
              )
            : 'Estimativa heurística com fator AMC (solo encharcado). '
              . 'Execute scripts/train_mlr.php para ativar o modelo calibrado.',
    ];
}

/**
 * Decaimento exponencial bifásico do excesso de cota acima do piso do leito,
 * calibrado por regressão log-linear na recessão dos 2 eventos fechados de
 * Lajeado (jul/2026): k_early nas primeiras 48h após o pico (escoamento
 * superficial/interflow, R²>0,94), k_late depois (baseflow, decaimento bem
 * mais lento). Referência: Tallaksen, L.M. (1995) "A review of baseflow
 * recession analysis", Journal of Hydrology v.165 — recessão de bacia
 * tipicamente multi-segmento, não uma exponencial única.
 */
function excessoDecaido(float $excesso0, float $horas): float
{
    $kEarly = 0.0407;
    $kLate  = 0.0059;
    $corteH = 48.0;

    if ($horas <= 0) return $excesso0;
    if ($horas <= $corteH) return $excesso0 * exp(-$kEarly * $horas);

    $noCorte = $excesso0 * exp(-$kEarly * $corteH);
    return $noCorte * exp(-$kLate * ($horas - $corteH));
}

/**
 * Contribuição de uma estação upstream pra cota de Lajeado depois de
 * $horasEfetivas (já descontada a defasagem/lag até chegar em Lajeado).
 *
 * Em recessão (taxa negativa, sem chuva nova chegando), usa o decaimento
 * exponencial de excessoDecaido() em vez de extrapolar a taxa instantânea
 * linearmente pra sempre — é o que causava projeções fisicamente implausíveis
 * (ex.: uma queda observada de -0,5m/h "esticada" por 20h viraria -10m).
 * Subindo/estável, mantém a extrapolação linear: a subida é dominada por
 * chuva recente ainda chegando, sem um modelo de recessão aplicável.
 */
function contribuicaoUpstream(float $taxaHora, float $excesso0, float $horasEfetivas, float $peso): float
{
    if ($horasEfetivas <= 0) return 0.0;

    if ($taxaHora < -0.005 && $excesso0 > 0.05) {
        $delta = excessoDecaido($excesso0, $horasEfetivas) - $excesso0;
        return $delta * $peso;
    }

    return $taxaHora * $horasEfetivas * $peso;
}

/**
 * Constrói a projeção hora a hora (1..$horas) a partir das taxas de subida/descida
 * observadas em cada estação upstream, respeitando a defasagem (lag) de cada uma
 * até chegar em Lajeado. A contribuição da chuva recente é distribuída
 * proporcionalmente ao longo do horizonte.
 *
 * @param array $stationRates ['estacao_id' => ['taxa_hora'=>float,'peso'=>float,'lag_h'=>float,'excesso0'=>float]]
 */
function construirCurvaHorariaHeuristica(
    array $stationRates,
    float $cotaAtual,
    float $pisoLeito,
    float $deltaChuvaBruto,
    int   $horas
): array {
    $agora  = time();
    $pontos = [];

    for ($h = 1; $h <= $horas; $h++) {
        $delta = 0.0;
        foreach ($stationRates as $r) {
            // Só conta a parcela de horas já "chegada" em Lajeado até o instante h
            $horasEfetivas = max(0, $h - $r['lag_h']);
            $delta += contribuicaoUpstream($r['taxa_hora'], $r['excesso0'], $horasEfetivas, $r['peso']);
        }
        // Contribuição da chuva recente cresce proporcionalmente até o horizonte total
        $delta += $deltaChuvaBruto * ($h / $horas);

        $cota = max($cotaAtual + $delta, $pisoLeito);

        $pontos[] = [
            'hora'          => $h,
            'timestamp'     => date('c', $agora + $h * 3600),
            'cota_projetada_m' => round($cota, 3),
        ];
    }

    return $pontos;
}

/**
 * Constrói a curva hora a hora a partir das previsões do MLR por horizonte.
 *
 * Os horizontes treinados (3h, 6h, ... 24h) são âncoras previstas pelo modelo;
 * as horas entre duas âncoras saem por interpolação linear num vão de 3h. É
 * bem diferente da v2, que traçava uma única reta da cota atual até +24h e por
 * isso não mostrava pico intermediário: numa cheia a cota pode subir até 12h e
 * já estar baixando em 24h, e a reta escondia exatamente isso.
 *
 * Cada ponto leva a banda de incerteza da âncora correspondente, para o gráfico
 * poder mostrar que o erro esperado cresce com o horizonte.
 *
 * @param array $pontosMlr saída de aplicarMLR()['pontos'], ordenada por horizonte
 */
function construirCurvaHorariaMLR(
    array $pontosMlr,
    float $cotaAtual,
    float $pisoLeito,
    int   $horas
): array {
    $agora = time();

    // Âncoras, começando pelo instante atual (hora 0, erro zero)
    $ancoras = [0 => ['cota' => $cotaAtual, 'p90' => 0.0]];
    foreach ($pontosMlr as $p) {
        $ancoras[(int)$p['horizonte_h']] = [
            'cota' => (float)$p['cota_projetada_m'],
            'p90'  => (float)($p['banda_p90_m'] ?? 0.0),
        ];
    }
    $hs = array_keys($ancoras);
    sort($hs);
    $maxH = max($hs);

    $curva = [];
    for ($h = 1; $h <= $horas; $h++) {
        if ($h > $maxH) break; // não extrapola além do maior horizonte treinado

        // Âncoras que cercam esta hora
        $antes = 0; $depois = $maxH;
        foreach ($hs as $a) {
            if ($a <= $h) $antes = $a;
            if ($a >= $h) { $depois = $a; break; }
        }

        if ($antes === $depois) {
            $cota = $ancoras[$h]['cota'];
            $p90  = $ancoras[$h]['p90'];
        } else {
            $frac = ($h - $antes) / ($depois - $antes);
            $cota = $ancoras[$antes]['cota'] + ($ancoras[$depois]['cota'] - $ancoras[$antes]['cota']) * $frac;
            $p90  = $ancoras[$antes]['p90']  + ($ancoras[$depois]['p90']  - $ancoras[$antes]['p90'])  * $frac;
        }

        $cota = max($cota, $pisoLeito);
        $curva[] = [
            'hora'             => $h,
            'timestamp'        => date('c', $agora + $h * 3600),
            'cota_projetada_m' => round($cota, 3),
            'banda_p90_m'      => round($p90, 3),
            'cota_minima_m'    => round(max($cota - $p90, $pisoLeito), 3),
            'cota_maxima_m'    => round($cota + $p90, 3),
            'previsto'         => isset($ancoras[$h]) && $h > 0, // âncora do modelo, não interpolação
        ];
    }

    return $curva;
}

/**
 * Aplica o modelo MLR v3 (um conjunto de coeficientes por horizonte) sobre as
 * leituras atuais, devolvendo uma previsão por horizonte com a banda de
 * incerteza medida na validação.
 *
 * Retorna null se os dados atuais não bastarem, ou se o arquivo for de uma
 * versão anterior (v2, com um único horizonte) — nesse caso a rota cai no
 * heurístico e o aviso pede para re-treinar.
 */
function aplicarMLR(string $mlrFile, \PDO $pdo, array $cfg, float $cotaAtual): ?array
{
    $model = json_decode(file_get_contents($mlrFile), true);
    if (!$model || empty($model['modelos'])) return null; // v2 ou arquivo inválido

    $featDef   = $model['features_def'];   // [estacao_id, lag_steps, alias]
    $estsChuva = $model['estacoes_chuva'];
    $janelas   = $model['janelas_chuva_h'] ?? [6, 12, 24, 48];
    $step15    = 900;

    // Janela de dados: o bastante para a maior defasagem e para a maior janela
    // de chuva acumulada, com folga.
    $maxLagHoras = (int)ceil(max(array_column($featDef, 1)) * 15 / 60);
    $janelaHoras = max($maxLagHoras, max($janelas)) + 4;

    $desde = date('Y-m-d H:i:s', time() - $janelaHoras * 3600);
    $stmt  = $pdo->prepare(
        "SELECT estacao_id, tipo, timestamp, valor FROM leituras WHERE timestamp >= :desde"
    );
    $stmt->execute([':desde' => $desde]);

    // Buckets de 15min em PHP (portável entre SQLite e PostgreSQL), descartando
    // leitura acima do teto físico da régua — mesmo filtro do treino, senão um
    // defeito de sensor entra direto na previsão.
    $tetos   = $cfg['evento']['cota_maxima_fisica'] ?? [];
    $buckets = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = (float)$r['valor'];
        $teto  = $tetos[$r['estacao_id']] ?? null;
        if ($teto !== null && $valor > $teto) continue;

        $ts15 = intdiv((int)strtotime($r['timestamp']), $step15) * $step15;
        $key  = $r['estacao_id'] . '|' . $ts15;
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['estacao_id' => $r['estacao_id'], 'ts' => $ts15, 'soma' => 0.0, 'n' => 0];
        }
        $buckets[$key]['soma'] += $valor;
        $buckets[$key]['n']++;
    }

    $series = [];
    foreach ($buckets as $b) {
        $series[$b['estacao_id']][$b['ts']] = $b['soma'] / $b['n'];
    }

    if (empty($series['taquari_1_cota'])) return null;
    $tBase = max(array_keys($series['taquari_1_cota']));

    // Vetor de features, na mesma ordem em que o treino as gerou
    $vec = [];
    foreach ($featDef as [$id, $lagSteps, $_alias]) {
        $tsFeat = $tBase - $lagSteps * $step15;
        $v = null;
        for ($off = 0; $off <= 8; $off++) { // tolera gap de até 2h
            $v = $series[$id][$tsFeat + $off * $step15]
              ?? $series[$id][$tsFeat - $off * $step15]
              ?? null;
            if ($v !== null) break;
        }
        if ($v === null) return null;
        $vec[] = $v;
    }
    foreach ($janelas as $h) {
        $n = $h * 4; $tot = 0.0; $k = 0;
        foreach ($estsChuva as $eid) {
            if (!isset($series[$eid])) continue;
            $s = 0.0;
            for ($i = 1; $i <= $n; $i++) $s += $series[$eid][$tBase - $i * $step15] ?? 0.0;
            $tot += $s; $k++;
        }
        $vec[] = $k > 0 ? $tot / $k : 0.0;
    }
    $vec[] = 1.0; // intercept

    // Aplica cada horizonte
    $pontos = []; $rejeitados = []; $piorMae = 0.0;
    $horizontes = array_map('intval', array_keys($model['modelos']));
    sort($horizontes);

    foreach ($horizontes as $H) {
        $m = $model['modelos'][(string)$H];
        $coefs = array_values($m['coeficientes']);
        if (count($coefs) !== count($vec)) continue; // modelo incompatível

        $pred = 0.0;
        foreach ($coefs as $i => $b) $pred += $b * $vec[$i];

        // Guarda de plausibilidade auto-calibrada: rejeita delta fora da faixa
        // de variação já observada neste horizonte em toda a série (com 30% de
        // folga). A regressão extrapola mal fora do que viu — a v2 chegou a
        // projetar -10m/24h durante uma cheia real.
        $delta = $pred - $cotaAtual;
        $obs   = $m['delta_observado'] ?? null;
        if ($obs !== null) {
            $limInf = $obs['min'] * 1.3;
            $limSup = $obs['max'] * 1.3;
            if ($delta < $limInf || $delta > $limSup) {
                $rejeitados[] = [
                    'horizonte_h' => $H,
                    'delta_previsto_m' => round($delta, 2),
                    'faixa_observada_m' => [$obs['min'], $obs['max']],
                ];
                continue;
            }
        }

        $loo = $m['validacao_loo'] ?? null;
        $mae = $loo['mae'] ?? null;
        if ($mae !== null) $piorMae = max($piorMae, (float)$mae);

        $pontos[] = [
            'horizonte_h'      => $H,
            'cota_projetada_m' => round($pred, 3),
            // Banda de incerteza = P90 do erro medido na validação por evento.
            // É o erro em cheias que o modelo não viu no treino, não o resíduo
            // do próprio ajuste.
            'erro_medio_m'     => $mae !== null ? (float)$mae : null,
            'banda_p90_m'      => isset($loo['p90']) ? (float)$loo['p90'] : null,
            'cota_minima_m'    => isset($loo['p90']) ? round($pred - $loo['p90'], 2) : null,
            'cota_maxima_m'    => isset($loo['p90']) ? round($pred + $loo['p90'], 2) : null,
        ];
    }

    if (empty($pontos)) return null;

    // Confiança pelo erro validado do horizonte mais longo aceito
    $confianca = match (true) {
        $piorMae > 0 && $piorMae <= 0.60 => 'moderada',
        $piorMae > 0 && $piorMae <= 1.50 => 'baixa',
        default                          => 'muito_baixa',
    };

    $mae = fn(int $h) => $model['modelos'][(string)$h]['validacao_loo']['mae'] ?? null;

    return [
        'pontos'      => $pontos,
        'confianca'   => $confianca,
        'rejeitados'  => $rejeitados,
        'versao'      => $model['versao'] ?? '3.0',
        'treinado_em' => $model['treinado_em'] ?? null,
        'validacao'   => [
            'metodo'    => $model['metodo_validacao'] ?? 'leave-one-event-out',
            'mae_12h'   => $mae(12),
            'mae_24h'   => $mae(24),
            'n_eventos' => $model['modelos'][(string)end($horizontes)]['validacao_loo']['n_eventos'] ?? null,
        ],
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
