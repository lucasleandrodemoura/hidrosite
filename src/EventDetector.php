<?php
declare(strict_types=1);

namespace ValeTaquari;

use PDO;

/**
 * Detecta episódios de cheia e calcula a razão histórica chuva/cota.
 *
 * Lógica de abertura:
 *   Chuva acumulada média nas estações de cabeceira >= LIMIAR_ABERTURA_MM
 *   na janela das últimas JANELA_CHUVA_H horas → abre evento.
 *
 * Lógica de fechamento:
 *   Evento aberto há pelo menos MIN_HORAS_EVENTO horas E não há leituras
 *   de chuva significativa nas últimas LIMIAR_FECHAMENTO_H horas → fecha evento.
 *
 * Ao fechar: calcula chuvas acumuladas, cotas máximas, defasagens e razão.
 */
class EventDetector
{
    private const CHUVA_INSIGNIFICANTE_MM = 0.2; // mm/leitura abaixo disso = sem chuva

    // Estações de cota em ordem rio abaixo (usadas para defasagem)
    private const ORDEM_COTA = ['taquari_3_cota', 'taquari_2_cota', 'taquari_1_cota'];

    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $log,
        private readonly array  $cfg,
    ) {}

    /**
     * Ponto de entrada: verifica estado atual e atua.
     * Retorna array com ação tomada e resumo.
     */
    public function verificar(): array
    {
        $eventoAberto = $this->eventoAberto();

        if ($eventoAberto === null) {
            return $this->tentarAbrirEvento();
        }

        return $this->verificarFechamento($eventoAberto);
    }

    // ── abertura ───────────────────────────────────────────────────────────────

    private function tentarAbrirEvento(): array
    {
        $janelaH   = $this->cfg['evento']['janela_chuva_h'];
        $limiar    = $this->cfg['evento']['limiar_abertura_mm'];
        $desde     = date('Y-m-d H:i:s', strtotime("-{$janelaH} hours"));

        $acumulados = $this->chuvaAcumuladaPorEstacao($desde, date('Y-m-d H:i:s'));
        $estacoesChuva = array_keys($this->cfg['estacoes']['chuva']);

        if (empty($acumulados)) {
            return ['acao' => 'nenhuma', 'motivo' => 'sem leituras de chuva'];
        }

        $media = $this->mediaCabeceira($acumulados, $estacoesChuva);

        if ($media < $limiar) {
            return [
                'acao'   => 'nenhuma',
                'motivo' => "chuva média {$media}mm abaixo do limiar {$limiar}mm em {$janelaH}h",
            ];
        }

        // Abre evento
        $inicioTs = $this->primeiraChuvaRecente($desde);
        $this->pdo->prepare(
            'INSERT INTO eventos (inicio_chuva, status) VALUES (:inicio, :status)'
        )->execute([':inicio' => $inicioTs, ':status' => 'aberto']);

        $eventoId = (int)$this->pdo->lastInsertId();
        $this->log->info("Evento #{$eventoId} ABERTO", [
            'chuva_media_6h' => $media,
            'limiar'         => $limiar,
            'inicio'         => $inicioTs,
        ]);

        return ['acao' => 'aberto', 'evento_id' => $eventoId, 'chuva_media_mm' => $media];
    }

    // ── fechamento ─────────────────────────────────────────────────────────────

    private function verificarFechamento(array $evento): array
    {
        $minHoras  = $this->cfg['evento']['min_horas_evento'];
        $fechaH    = $this->cfg['evento']['limiar_fechamento_h'];
        $inicio    = $evento['inicio_chuva'];
        $agoraTs   = date('Y-m-d H:i:s');

        $horasAberto = (time() - strtotime($inicio)) / 3600;

        if ($horasAberto < $minHoras) {
            return [
                'acao'   => 'aguardando',
                'motivo' => "evento aberto há {$horasAberto}h, mínimo {$minHoras}h",
                'evento' => $evento['id'],
            ];
        }

        // Verifica se houve chuva nas últimas LIMIAR_FECHAMENTO_H horas
        $desde = date('Y-m-d H:i:s', strtotime("-{$fechaH} hours"));
        $chuvaRecente = $this->chuvaAcumuladaPorEstacao($desde, $agoraTs);
        $mediaRecente = $this->mediaCabeceira($chuvaRecente, array_keys($this->cfg['estacoes']['chuva']));

        if ($mediaRecente >= self::CHUVA_INSIGNIFICANTE_MM * 4) {
            return [
                'acao'    => 'aguardando',
                'motivo'  => "ainda chove: {$mediaRecente}mm nas últimas {$fechaH}h",
                'evento'  => $evento['id'],
            ];
        }

        // Fecha evento e calcula métricas
        return $this->fecharEvento($evento, $agoraTs);
    }

    private function fecharEvento(array $evento, string $agoraTs): array
    {
        $inicio    = $evento['inicio_chuva'];
        $eventoId  = (int)$evento['id'];

        // 1. Chuva acumulada por estação durante o evento
        $acumulados = $this->chuvaAcumuladaPorEstacao($inicio, $agoraTs);
        $estacoesChuva = array_keys($this->cfg['estacoes']['chuva']);
        $mediaCabeceira = $this->mediaCabeceira($acumulados, $estacoesChuva);

        // 2. Cotas máximas e momento do pico por estação de cota
        $picos = $this->picosCotas($inicio, $agoraTs);

        // 3. Excesso de cota e razão
        $cotaMaxLajeado   = $picos['taquari_1_cota']['valor']    ?? null;
        $tsPicoLajeado    = $picos['taquari_1_cota']['timestamp'] ?? null;
        $cotaMaxMucum     = $picos['taquari_3_cota']['valor']    ?? null;
        $tsPicoMucum      = $picos['taquari_3_cota']['timestamp'] ?? null;
        $cotaMaxEncantado = $picos['taquari_2_cota']['valor']    ?? null;
        $tsPicoEncantado  = $picos['taquari_2_cota']['timestamp'] ?? null;

        $cotaInundacao = $this->cfg['evento']['cota_inundacao']['taquari_1_cota'];
        $excesso       = ($cotaMaxLajeado !== null) ? max(0, $cotaMaxLajeado - $cotaInundacao) : null;
        $razao         = ($excesso !== null && $excesso > 0) ? ($mediaCabeceira / $excesso) : null;

        // 4. Defasagens entre picos (horas)
        $defCabMucum      = $this->defasagemHoras($inicio, $tsPicoMucum);
        $defMucEnc        = $this->defasagemHoras($tsPicoMucum, $tsPicoEncantado);
        $defEncLaj        = $this->defasagemHoras($tsPicoEncantado, $tsPicoLajeado);

        // 5. Atualiza evento no banco
        $this->pdo->prepare(
            'UPDATE eventos SET
                fim_chuva                     = :fim,
                chuva_acumulada_por_estacao   = :acum_json,
                chuva_media_cabeceira         = :media,
                cota_maxima_lajeado           = :cml,
                cota_maxima_mucum             = :cmm,
                cota_maxima_encantado         = :cme,
                data_pico_lajeado             = :pico_laj,
                data_pico_mucum               = :pico_muc,
                data_pico_encantado           = :pico_enc,
                defasagem_cabeceira_mucum_h   = :def_cm,
                defasagem_mucum_encantado_h   = :def_me,
                defasagem_encantado_lajeado_h = :def_el,
                excesso_cota_lajeado          = :excesso,
                razao_calculada               = :razao,
                status                        = :status,
                fechado_em                    = :fechado_em
             WHERE id = :id'
        )->execute([
            ':fim'        => $agoraTs,
            ':acum_json'  => json_encode($acumulados, JSON_UNESCAPED_UNICODE),
            ':media'      => round($mediaCabeceira, 2),
            ':cml'        => $cotaMaxLajeado,
            ':cmm'        => $cotaMaxMucum,
            ':cme'        => $cotaMaxEncantado,
            ':pico_laj'   => $tsPicoLajeado,
            ':pico_muc'   => $tsPicoMucum,
            ':pico_enc'   => $tsPicoEncantado,
            ':def_cm'     => $defCabMucum,
            ':def_me'     => $defMucEnc,
            ':def_el'     => $defEncLaj,
            ':excesso'    => $excesso !== null ? round($excesso, 3) : null,
            ':razao'      => $razao   !== null ? round($razao, 4)   : null,
            ':status'     => 'fechado',
            ':fechado_em' => $agoraTs,
            ':id'         => $eventoId,
        ]);

        $this->log->info("Evento #{$eventoId} FECHADO", [
            'chuva_media'  => $mediaCabeceira,
            'cota_max_laj' => $cotaMaxLajeado,
            'excesso'      => $excesso,
            'razao'        => $razao,
        ]);

        return [
            'acao'            => 'fechado',
            'evento_id'       => $eventoId,
            'chuva_media_mm'  => $mediaCabeceira,
            'cota_max_lajeado'=> $cotaMaxLajeado,
            'excesso_cota'    => $excesso,
            'razao_calculada' => $razao,
        ];
    }

    // ── helpers de consulta ────────────────────────────────────────────────────

    /** Retorna evento aberto mais recente ou null. */
    private function eventoAberto(): ?array
    {
        $row = $this->pdo->query(
            "SELECT * FROM eventos WHERE status = 'aberto' ORDER BY inicio_chuva DESC LIMIT 1"
        )->fetch();
        return $row ?: null;
    }

    /**
     * Soma de leituras de chuva por estação no período [inicio, fim].
     * Retorna ['taquari_9_chuva' => 42.5, ...]
     */
    private function chuvaAcumuladaPorEstacao(string $inicio, string $fim): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT estacao_id, SUM(valor) AS total
             FROM leituras
             WHERE tipo = 'chuva'
               AND timestamp >= :inicio
               AND timestamp <= :fim
             GROUP BY estacao_id"
        );
        $stmt->execute([':inicio' => $inicio, ':fim' => $fim]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['estacao_id']] = round((float)$row['total'], 2);
        }
        return $result;
    }

    /**
     * Média simples das estações de cabeceira que têm dados.
     */
    private function mediaCabeceira(array $acumulados, array $estacoes): float
    {
        $vals = array_filter(
            array_map(fn($e) => $acumulados[$e] ?? null, $estacoes),
            fn($v) => $v !== null
        );
        return empty($vals) ? 0.0 : array_sum($vals) / count($vals);
    }

    /**
     * Para cada estação de cota: valor máximo e timestamp no período.
     * Retorna ['taquari_1_cota' => ['valor' => 24.5, 'timestamp' => '...'], ...]
     */
    private function picosCotas(string $inicio, string $fim): array
    {
        $result = [];
        $stmt = $this->pdo->prepare(
            "SELECT estacao_id, valor, timestamp
             FROM leituras
             WHERE tipo = 'cota'
               AND estacao_id = :id
               AND timestamp >= :inicio
               AND timestamp <= :fim
             ORDER BY valor DESC LIMIT 1"
        );

        foreach (self::ORDEM_COTA as $id) {
            $stmt->execute([':id' => $id, ':inicio' => $inicio, ':fim' => $fim]);
            $row = $stmt->fetch();
            if ($row) {
                $result[$id] = ['valor' => (float)$row['valor'], 'timestamp' => $row['timestamp']];
            }
        }
        return $result;
    }

    /**
     * Primeiro timestamp de chuva desde $desde (detecta início real do evento).
     */
    private function primeiraChuvaRecente(string $desde): string
    {
        $row = $this->pdo->prepare(
            "SELECT MIN(timestamp) AS ts
             FROM leituras
             WHERE tipo = 'chuva' AND timestamp >= :desde AND valor > :limiar"
        );
        $row->execute([':desde' => $desde, ':limiar' => self::CHUVA_INSIGNIFICANTE_MM]);
        $ts = $row->fetchColumn();
        return $ts ?: date('Y-m-d H:i:s');
    }

    /** Diferença em horas entre dois timestamps; null se algum for nulo. */
    private function defasagemHoras(?string $de, ?string $ate): ?float
    {
        if ($de === null || $ate === null) {
            return null;
        }
        $diff = (strtotime($ate) - strtotime($de)) / 3600;
        return round($diff, 2);
    }
}
