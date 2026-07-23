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

        $estacoesCfg   = array_keys($this->cfg['estacoes']['chuva']);
        $mediaHipotetica = $this->calcularMediaEntrada($chuvaPorEstacao, $estacoesCfg);
        $cotaInundacao   = $this->cfg['evento']['cota_inundacao']['taquari_1_cota'];

        $razao            = $historico['razao_media'];
        $excessoProjetado = $mediaHipotetica / $razao;
        $cotaProjetada    = $cotaInundacao + $excessoProjetado;

        // Intervalo de confiança (1 desvio padrão)
        $ic = null;
        if ($historico['razao_desvio'] !== null) {
            $dp = $historico['razao_desvio'];
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
                'n_eventos_historicos'   => $historico['n_eventos'],
                'razao_desvio_padrao'    => $historico['razao_desvio'] !== null
                    ? round($historico['razao_desvio'], 4) : null,
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
