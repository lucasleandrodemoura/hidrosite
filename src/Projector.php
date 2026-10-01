<?php
declare(strict_types=1);

namespace ValeTaquari;

use PDO;

/**
 * Motor de projeção de cheia.
 *
 * Usa a razão histórica chuva/excesso-de-cota construída a partir dos eventos
 * fechados para estimar a cota máxima em Lajeado e o tempo até o pico,
 * dado um cenário hipotético de chuva nas estações de cabeceira.
 *
 * razão = chuva_media_cabeceira_mm / excesso_cota_lajeado_m
 * → excesso_projetado = chuva_hipotetica / razão
 * → cota_projetada    = cota_inundacao + excesso_projetado
 *
 * ── Correção pela concentração espacial da chuva ──────────────────────────────
 *
 * A razão média simples é um preditor ruim: nos 4 eventos observados ela varia
 * de 9,1 a 23,5 mm/m (desvio 6,5), o que torna o intervalo de confiança quase
 * inútil. A variação não é ruído — ela acompanha a DISTRIBUIÇÃO ESPACIAL da
 * chuva, medida pelo coeficiente de variação (CV = desvio/média) entre os
 * postos de cabeceira:
 *
 *   evento    CV     razão
 *   jul/20   0,22    21,0      chuva frontal, espalhada por toda a bacia
 *   jul/28   0,27    23,5      idem
 *   set/28   0,90    14,4      chuva concentrada na cabeceira média/baixa
 *   set/21   1,09     9,1      idem, mais concentrada ainda
 *
 * Quanto mais concentrada a chuva, MENOS milímetros médios são necessários por
 * metro de cheia. O motivo é de medição, não de física da bacia: a média
 * aritmética entre os 7 postos divide a chuva por toda a cabeceira, inclusive
 * onde não choveu, e portanto subestima a lâmina que de fato caiu sobre a área
 * que gerou o escoamento. Em set/2026 choveu ~0mm em Vacaria e Ibiraiaras e
 * 140-180mm em Santa Tereza, Linha Colombo e Barra do Fão.
 *
 * Ajustando razão = A·exp(B·CV) sobre os eventos fechados, o desvio residual
 * cai de 6,5 para 2,1 mm/m (R²≈0,90) — o intervalo de confiança encolhe ~68%.
 * A calibração é refeita a cada evento novo, em razaoHistorica(), e só é usada
 * com pelo menos 3 eventos e ajuste válido; abaixo disso vale a razão média.
 *
 * Ressalva: 4 eventos é pouco para uma lei empírica. O efeito é consistente e
 * tem explicação clara, mas os coeficientes vão mudar conforme a série cresce.
 * A alternativa correta a médio prazo é ponderar a chuva por área de
 * contribuição (polígonos de Thiessen), que dispensa esta correção.
 */
class Projector
{
    public function __construct(
        private readonly PDO   $pdo,
        private readonly array $cfg,
    ) {}

    /**
     * Projeta a cota em Lajeado a partir de chuva hipotética nas cabeceiras.
     *
     * @param array $chuvaPorEstacao mm esperados por estação de cabeceira,
     *                                ex.: ['taquari_9_chuva' => 50.0, ...]
     *                                Estações ausentes recebem a média das presentes.
     * @return array Resultado da projeção com campos explicativos.
     */
    public function projetar(array $chuvaPorEstacao): array
    {
        $historico = $this->razaoHistorica();

        if ($historico['n_eventos'] === 0) {
            return [
                'status'    => 'sem_dados',
                'mensagem'  => 'Nenhum evento de cheia fechado disponível para calibrar a projeção.',
                'historico' => $historico,
            ];
        }

        $estacoesCfg   = $this->cfg['estacoes']['chuva_cabeceira'];
        $mediaHipotetica = $this->calcularMediaEntrada($chuvaPorEstacao, $estacoesCfg);
        $cotaInundacao   = $this->cfg['evento']['cota_inundacao']['taquari_1_cota'];

        // Concentração espacial do cenário informado e razão corrigida por ela
        $cv    = $this->coefVariacao($chuvaPorEstacao, $estacoesCfg);
        $ajuste = $historico['ajuste_cv'];

        $razao      = $historico['razao_media'];
        $razaoFonte = 'media_historica';
        $dp         = $historico['razao_desvio'];

        if ($ajuste !== null && $cv !== null) {
            // Limita o CV à faixa em que a curva foi calibrada — uma exponencial
            // extrapolada fora dela dispara sem qualquer respaldo nos dados.
            $cvUsado = max($ajuste['cv_min'], min($ajuste['cv_max'], $cv));
            $razaoAjustada = $ajuste['a'] * exp($ajuste['b'] * $cvUsado);
            $razaoAjustada = max($ajuste['razao_min'], min($ajuste['razao_max'], $razaoAjustada));

            if ($razaoAjustada > 0.1) {
                $razao      = $razaoAjustada;
                $razaoFonte = abs($cvUsado - $cv) > 1e-6
                    ? 'ajustada_por_concentracao_espacial (CV fora da faixa calibrada, limitado)'
                    : 'ajustada_por_concentracao_espacial';
                $dp         = $ajuste['erro_padrao'];
            }
        }

        $excessoProjetado = $mediaHipotetica / $razao;
        $cotaProjetada    = $cotaInundacao + $excessoProjetado;

        // Intervalo de confiança (1 desvio padrão do erro da razão empregada)
        $ic = null;
        if ($dp !== null && $dp > 0) {
            $ic = [
                'cota_minima' => round($cotaInundacao + $mediaHipotetica / ($razao + $dp), 2),
                'cota_maxima' => round($cotaInundacao + $mediaHipotetica / max(0.001, $razao - $dp), 2),
            ];
        }

        // Defasagem estimada até o pico em Lajeado (horas desde o início das chuvas)
        $defasagem = $historico['defasagem_total_media_h'];

        return [
            'status'             => 'ok',
            'entrada' => [
                'chuva_por_estacao'    => $chuvaPorEstacao,
                'chuva_media_mm'       => round($mediaHipotetica, 2),
                'concentracao_cv'      => $cv !== null ? round($cv, 3) : null,
                'padrao_chuva'         => $cv === null ? null : match (true) {
                    $cv < 0.35 => 'espalhada (frontal) — toda a cabeceira contribui',
                    $cv < 0.70 => 'parcialmente concentrada',
                    default    => 'concentrada — chuva localizada gera cheia com menos mm médios',
                },
            ],
            'projecao' => [
                'cota_projetada_m'        => round($cotaProjetada, 2),
                'excesso_sobre_inundacao' => round($excessoProjetado, 2),
                'cota_inundacao_m'        => $cotaInundacao,
                'horas_ate_pico'          => $defasagem !== null ? round($defasagem, 1) : null,
                'intervalo_confianca'     => $ic,
            ],
            'calibracao' => [
                'razao_utilizada'        => round($razao, 4),
                'razao_origem'           => $razaoFonte,
                'razao_media_historica'  => $historico['razao_media'],
                'ajuste_concentracao'    => $ajuste,
                'n_eventos_historicos'   => $historico['n_eventos'],
                'razao_desvio_padrao'    => $dp !== null ? round($dp, 4) : null,
                'confiabilidade'         => $this->nivelConfiabilidade($historico['n_eventos']),
                'defasagem_media_h'      => $defasagem,
                'defasagem_detalhada'    => $historico['defasagem_detalhada'],
            ],
        ];
    }

    /**
     * Retorna a razão histórica calculada a partir de todos os eventos fechados.
     * Também retorna defasagens médias entre picos.
     */
    public function razaoHistorica(): array
    {
        $eventos = $this->pdo->query(
            "SELECT razao_calculada,
                    chuva_media_cabeceira,
                    chuva_acumulada_por_estacao,
                    excesso_cota_lajeado,
                    defasagem_cabeceira_mucum_h,
                    defasagem_mucum_encantado_h,
                    defasagem_encantado_lajeado_h,
                    inicio_chuva,
                    data_pico_lajeado,
                    cota_maxima_lajeado
             FROM eventos
             WHERE status = 'fechado'
               AND razao_calculada IS NOT NULL
               AND razao_calculada > 0
             ORDER BY fechado_em DESC"
        )->fetchAll();

        if (empty($eventos)) {
            return [
                'n_eventos'               => 0,
                'razao_media'             => null,
                'razao_desvio'            => null,
                'ajuste_cv'               => null,
                'defasagem_total_media_h' => null,
                'defasagem_detalhada'     => null,
                'eventos'                 => [],
            ];
        }

        $razoes    = array_column($eventos, 'razao_calculada');
        $mediRazao = array_sum($razoes) / count($razoes);
        $desvio    = $this->desvioPadrao($razoes);

        // Defasagem total: início da chuva → pico em Lajeado
        $defTotais = [];
        $defCM     = [];
        $defME     = [];
        $defEL     = [];

        foreach ($eventos as $e) {
            if ($e['inicio_chuva'] && $e['data_pico_lajeado']) {
                $defTotais[] = (strtotime($e['data_pico_lajeado']) - strtotime($e['inicio_chuva'])) / 3600;
            }
            if ($e['defasagem_cabeceira_mucum_h'] !== null)   $defCM[] = (float)$e['defasagem_cabeceira_mucum_h'];
            if ($e['defasagem_mucum_encantado_h'] !== null)   $defME[] = (float)$e['defasagem_mucum_encantado_h'];
            if ($e['defasagem_encantado_lajeado_h'] !== null) $defEL[] = (float)$e['defasagem_encantado_lajeado_h'];
        }

        $mediaTotal = !empty($defTotais) ? array_sum($defTotais) / count($defTotais) : null;

        return [
            'n_eventos'   => count($eventos),
            'razao_media' => round($mediRazao, 4),
            'razao_desvio'=> count($razoes) > 1 ? round($desvio, 4) : null,
            'ajuste_cv'   => $this->calibrarAjusteCV($eventos),
            'defasagem_total_media_h' => $mediaTotal !== null ? round($mediaTotal, 1) : null,
            'defasagem_detalhada' => [
                'cabeceira_mucum_h'      => !empty($defCM) ? round(array_sum($defCM)/count($defCM), 1) : null,
                'mucum_encantado_h'      => !empty($defME) ? round(array_sum($defME)/count($defME), 1) : null,
                'encantado_lajeado_h'    => !empty($defEL) ? round(array_sum($defEL)/count($defEL), 1) : null,
            ],
            'eventos' => array_map(fn($e) => [
                'inicio_chuva'      => $e['inicio_chuva'],
                'chuva_media_mm'    => (float)$e['chuva_media_cabeceira'],
                'cota_max_lajeado'  => (float)$e['cota_maxima_lajeado'],
                'excesso_m'         => (float)$e['excesso_cota_lajeado'],
                'razao'             => (float)$e['razao_calculada'],
            ], $eventos),
        ];
    }

    /**
     * Retorna leituras recentes para dashboard (últimas N horas).
     */
    public function leituraRecentes(int $horasAtras = 24): array
    {
        $desde = date('Y-m-d H:i:s', strtotime("-{$horasAtras} hours"));
        $rows  = $this->pdo->prepare(
            "SELECT l.estacao_id, e.nome, l.tipo, l.timestamp, l.valor
             FROM leituras l
             JOIN estacoes e ON e.id = l.estacao_id
             WHERE l.timestamp >= :desde
             ORDER BY l.estacao_id, l.timestamp"
        );
        $rows->execute([':desde' => $desde]);
        return $rows->fetchAll();
    }

    // ── privados ───────────────────────────────────────────────────────────────

    private function calcularMediaEntrada(array $entrada, array $estacoesCfg): float
    {
        $vals = [];
        foreach ($estacoesCfg as $e) {
            if (isset($entrada[$e])) {
                $vals[] = (float)$entrada[$e];
            }
        }

        if (empty($vals)) {
            // Fallback: usa qualquer valor fornecido
            $vals = array_values($entrada);
        }

        return empty($vals) ? 0.0 : array_sum($vals) / count($vals);
    }

    /**
     * Coeficiente de variação (desvio/média) da chuva entre os postos de
     * cabeceira — mede o quanto a chuva foi concentrada em vez de espalhada.
     * Null se não houver ao menos 3 postos com valor, ou se a média for ~0.
     */
    private function coefVariacao(array $chuvaPorEstacao, array $estacoesCfg): ?float
    {
        $vals = [];
        foreach ($estacoesCfg as $e) {
            if (isset($chuvaPorEstacao[$e]) && is_numeric($chuvaPorEstacao[$e])) {
                $vals[] = (float)$chuvaPorEstacao[$e];
            }
        }
        if (count($vals) < 3) return null;

        $media = array_sum($vals) / count($vals);
        if ($media < 0.1) return null;

        // Desvio populacional: descreve a dispersão do campo de chuva observado,
        // não estima a de uma população maior.
        $var = array_sum(array_map(fn($v) => ($v - $media) ** 2, $vals)) / count($vals);
        return sqrt($var) / $media;
    }

    /**
     * Calibra razão = A·exp(B·CV) por regressão linear de ln(razão) contra o CV
     * da chuva de cada evento fechado.
     *
     * Retorna null se houver menos de 3 eventos com CV calculável, ou se o
     * ajuste não explicar mais que a razão média (R² ≤ 0,5) — nesse caso não há
     * ganho em trocar a média por uma curva.
     */
    private function calibrarAjusteCV(array $eventos): ?array
    {
        $estacoesCfg = $this->cfg['estacoes']['chuva_cabeceira'];

        $pts = [];
        foreach ($eventos as $e) {
            $json = $e['chuva_acumulada_por_estacao'] ?? null;
            if (!$json) continue;
            $porEstacao = json_decode((string)$json, true);
            if (!is_array($porEstacao)) continue;

            $cv = $this->coefVariacao($porEstacao, $estacoesCfg);
            $rz = (float)$e['razao_calculada'];
            if ($cv === null || $rz <= 0) continue;

            $pts[] = [$cv, log($rz), $rz];
        }

        $n = count($pts);
        if ($n < 3) return null;

        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        $sxy = 0.0; $sxx = 0.0;
        foreach ($pts as [$x, $yl, $_r]) { $sxy += ($x - $mx) * ($yl - $my); $sxx += ($x - $mx) ** 2; }
        if ($sxx < 1e-9) return null;

        $b = $sxy / $sxx;
        $a = $my - $b * $mx;

        // R² no espaço log e desvio residual no espaço da razão (mm/m), que é o
        // que alimenta o intervalo de confiança
        $ssRes = 0.0; $ssTot = 0.0; $resid = [];
        foreach ($pts as [$x, $yl, $rz]) {
            $ssRes += ($yl - ($a + $b * $x)) ** 2;
            $ssTot += ($yl - $my) ** 2;
            $resid[] = $rz - exp($a + $b * $x);
        }
        if ($ssTot < 1e-12) return null;
        $r2 = 1 - $ssRes / $ssTot;
        if ($r2 <= 0.5) return null;

        // Erro padrão da regressão: divide por n-2 (dois parâmetros estimados),
        // não por n-1. Com n=4 isso é 41% maior que o desvio simples dos
        // resíduos — é o valor honesto para montar o intervalo de confiança,
        // já que a própria curva foi estimada de pouquíssimos eventos.
        $ssResRazao = array_sum(array_map(fn($v) => $v * $v, $resid));
        $erroPadrao = sqrt($ssResRazao / max(1, $n - 2));
        $razoes     = array_column($pts, 2);

        return [
            'a'               => round(exp($a), 4),
            'b'               => round($b, 4),
            'r2'              => round($r2, 4),
            'n_eventos'       => $n,
            'erro_padrao'     => round($erroPadrao, 4),
            'desvio_residual' => round(sqrt($ssResRazao / max(1, $n - 1)), 4),
            'cv_min'          => round(min(array_column($pts, 0)), 3),
            'cv_max'          => round(max(array_column($pts, 0)), 3),
            'razao_min'       => round(min($razoes) * 0.7, 4),
            'razao_max'       => round(max($razoes) * 1.3, 4),
            'formula'         => sprintf('razao = %.2f * exp(%.4f * CV)', exp($a), $b),
            'ressalva'        => sprintf(
                'Lei empírica ajustada em %d eventos — indicativa, não definitiva. '
                . 'Os coeficientes mudam conforme a série cresce.', $n
            ),
        ];
    }

    private function nivelConfiabilidade(int $nEventos): string
    {
        return match (true) {
            $nEventos >= 10 => 'alta',
            $nEventos >= 5  => 'moderada',
            $nEventos >= 2  => 'baixa',
            default         => 'muito_baixa (apenas 1 evento de referência)',
        };
    }

    private function desvioPadrao(array $vals): float
    {
        if (count($vals) < 2) {
            return 0.0;
        }
        $media  = array_sum($vals) / count($vals);
        $soma   = array_sum(array_map(fn($v) => ($v - $media) ** 2, $vals));
        return sqrt($soma / (count($vals) - 1));
    }
}
