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

    foreach ($rows as $r) {
        $id = $r['estacao_id'];
        $entry = [
            'nome'      => $r['nome'],
            'valor'     => (float)$r['valor'],
            'timestamp' => $r['timestamp'],
        ];

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
    if ($tipo !== null && in_array($tipo, ['chuva', 'cota'], true)) {
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
    // Defasagens estimadas (horas) e pesos de contribuição para Lajeado
    // Defasagens baseadas na distância e velocidade típica de propagação de cheias no Taquari
    $upstream = [
        'taquari_2_cota'  => ['nome' => 'Encantado',     'lag_h' => 9,  'peso' => 0.55],
        'taquari_33_cota' => ['nome' => 'Barra do Fão',  'lag_h' => 11, 'peso' => 0.12],
        'taquari_3_cota'  => ['nome' => 'Muçum',         'lag_h' => 17, 'peso' => 0.28],
        'taquari_32_cota' => ['nome' => 'Santa Tereza',  'lag_h' => 21, 'peso' => 0.03],
        'taquari_55_cota' => ['nome' => 'Linha Colombo', 'lag_h' => 23, 'peso' => 0.02],
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

    if ($razaoMedia > 0 && $chuvaMedia > 5) {
        // Chuva das últimas 12h ainda vai chegar em Lajeado (lag médio ~16h desde cabeceira)
        $desde12h    = date('Y-m-d H:i:s', strtotime('-12 hours'));
        $stmtCh12    = $pdo->prepare(
            "SELECT SUM(valor) / NULLIF(COUNT(DISTINCT estacao_id), 0) AS media
             FROM leituras WHERE tipo = 'chuva' AND timestamp >= :desde"
        );
        $stmtCh12->execute([':desde' => $desde12h]);
        $chuva12h = (float)($stmtCh12->fetchColumn() ?: 0);

        // Estima impacto: chuva recente / razão * fator conservador (50% já chegou)
        $deltaChuvaBruto = ($chuva12h * 0.5) / $razaoMedia;
        $deltaPonderado += $deltaChuvaBruto;
    }

    $cotaProjetada = round($cotaAtual + $deltaPonderado, 2);
    $cfgAtencao    = $cfg['evento']['cota_atencao']['taquari_1_cota']   ?? 15.0;
    $cfgInundacao  = $cfg['evento']['cota_inundacao']['taquari_1_cota'] ?? 19.0;

    // Situação projetada
    $situacao = 'normal';
    if ($cotaProjetada >= $cfgInundacao) $situacao = 'cheia';
    elseif ($cotaProjetada >= $cfgAtencao) $situacao = 'atencao';

    // Confiança aumenta com mais estações e com razão calibrada
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
        'confianca'             => $confianca,
        'n_estacoes_contrib'    => $nContrib,
        'estacoes_upstream'     => $detalhes,
        'aviso'                 => 'Estimativa heurística baseada em tendências atuais e defasagens fixas. '
                                 . 'Assume que as condições se mantêm nas próximas horas. '
                                 . 'Precisão melhora após acúmulo de episódios históricos calibrados.',
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
    $detector  = new EventDetector($pdo, $logger, $cfg);
    $acao      = $detector->verificar();

    $totalNovas = array_sum(array_column($resultado, 'novas'));
    $totalErros = count(array_filter($resultado, fn($r) => $r['erro'] !== null));

    return [
        'leituras_novas' => $totalNovas,
        'erros'          => $totalErros,
        'detector'       => $acao,
        'detalhes'       => $resultado,
    ];
}
