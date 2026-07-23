<?php
declare(strict_types=1);

namespace ValeTaquari;

use PDO;
use RuntimeException;

/**
 * Busca os CSVs do SGB e persiste apenas linhas novas.
 *
 * Formato esperado do CSV: timestamp;valor
 * Pode conter (ou não) linha de cabeçalho — detectado automaticamente.
 */
class Collector
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $log,
        private readonly array  $cfg,
    ) {}

    /**
     * Coleta todas as estações configuradas (chuva + cota).
     * Retorna um resumo: ['taquari_9_chuva' => ['novas' => 4, 'erro' => null], ...]
     */
    public function coletarTodas(): array
    {
        $resultado = [];

        foreach (['chuva', 'cota'] as $tipo) {
            foreach ($this->cfg['estacoes'][$tipo] as $id => $nome) {
                $resultado[$id] = $this->coletarEstacao($id, $tipo, $nome);
            }
        }

        return $resultado;
    }

    /**
     * Coleta uma estação específica.
     */
    public function coletarEstacao(string $estacaoId, string $tipo, string $nome): array
    {
        $url  = rtrim($this->cfg['sgb']['base_url'], '/') . "/{$estacaoId}.csv";
        $info = ['novas' => 0, 'erro' => null];

        try {
            $csv = $this->fetchCsv($url);
            $linhas = $this->parseCsv($csv, $tipo);

            if (empty($linhas)) {
                $this->log->warning("CSV vazio ou sem dados válidos", ['estacao' => $estacaoId]);
            } else {
                $info['novas'] = $this->salvarLeituras($estacaoId, $tipo, $linhas);
                $this->log->info("Coletado", [
                    'estacao' => $nome,
                    'novas'   => $info['novas'],
                    'total'   => count($linhas),
                ]);
            }
        } catch (\Throwable $e) {
            $info['erro'] = $e->getMessage();
            $this->log->error("Erro ao coletar {$nome}", ['erro' => $e->getMessage()]);
        }

        $this->registrarLog($estacaoId, $info);
        return $info;
    }

    // ── privados ───────────────────────────────────────────────────────────────

    private function fetchCsv(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'          => $this->cfg['sgb']['timeout'],
                'user_agent'       => 'ValeTaquariTempo/1.0 (https://github.com/vale-taquari/hidro-coletor)',
                'follow_location'  => true,
                'max_redirects'    => 3,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $ctx);

        if ($content === false) {
            $err = error_get_last();
            throw new RuntimeException("Falha ao buscar CSV: " . ($err['message'] ?? 'erro desconhecido'));
        }

        return $content;
    }

    /**
     * Analisa o CSV e retorna array de ['timestamp' => string, 'valor' => float].
     * Aceita separador ; ou ,  e detecta automaticamente linha de cabeçalho.
     */
    private function parseCsv(string $content, string $tipo = 'chuva'): array
    {
        $linhas  = preg_split('/\r?\n/', trim($content));
        $dados   = [];
        $primeiraValida = false;

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }

            // Detecta separador
            $sep = str_contains($linha, ';') ? ';' : ',';
            $partes = explode($sep, $linha, 2);

            if (count($partes) < 2) {
                continue;
            }

            [$ts, $val] = $partes;
            $ts  = trim($ts);
            $val = trim($val);

            // Pula cabeçalho (primeira linha não numérica)
            if (!$primeiraValida && !is_numeric(str_replace(['.', ',', '-', ':'], '', $val))) {
                $primeiraValida = true;
                continue;
            }
            $primeiraValida = true;

            // Valida timestamp
            if (!$this->isValidTimestamp($ts)) {
                continue;
            }

            // Normaliza vírgula decimal para ponto
            $val = str_replace(',', '.', $val);

            if (!is_numeric($val)) {
                continue;
            }

            // SGB entrega cota em centímetros; armazena em metros para consistência
            $valorFinal = ($tipo === 'cota') ? round((float)$val / 100, 3) : (float)$val;

            $dados[] = [
                'timestamp' => $this->normalizeTimestamp($ts),
                'valor'     => $valorFinal,
            ];
        }

        return $dados;
    }

    /**
     * Insere leituras novas, ignorando duplicatas (UNIQUE estacao_id + timestamp).
     * Retorna quantas linhas foram efetivamente inseridas.
     */
    private function salvarLeituras(string $estacaoId, string $tipo, array $linhas): int
    {
        $novas = 0;

        // Detecta driver pelo DSN da conexão
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? 'INSERT INTO leituras (estacao_id, tipo, timestamp, valor)
               VALUES (:estacao, :tipo, :ts, :val) ON CONFLICT (estacao_id, timestamp) DO NOTHING'
            : 'INSERT OR IGNORE INTO leituras (estacao_id, tipo, timestamp, valor)
               VALUES (:estacao, :tipo, :ts, :val)';

        $stmt = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            foreach ($linhas as $l) {
                $stmt->execute([
                    ':estacao' => $estacaoId,
                    ':tipo'    => $tipo,
                    ':ts'      => $l['timestamp'],
                    ':val'     => $l['valor'],
                ]);
                $novas += $stmt->rowCount();
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $novas;
    }

    private function registrarLog(string $estacaoId, array $info): void
    {
        $this->pdo->prepare(
            'INSERT INTO log_coleta (estacao_id, status, linhas_novas, erro)
             VALUES (:est, :status, :novas, :erro)'
        )->execute([
            ':est'    => $estacaoId,
            ':status' => $info['erro'] === null ? 'ok' : 'erro',
            ':novas'  => $info['novas'],
            ':erro'   => $info['erro'],
        ]);
    }

    private function isValidTimestamp(string $ts): bool
    {
        // Aceita: "2024-05-01 00:00:00" ou "2024-05-01T00:00:00"
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}[\sT]\d{2}:\d{2}(:\d{2})?$/', $ts);
    }

    private function normalizeTimestamp(string $ts): string
    {
        // Garante formato "YYYY-MM-DD HH:MM:SS"
        return str_replace('T', ' ', $ts);
    }
}
