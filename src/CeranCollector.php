<?php
declare(strict_types=1);

namespace ValeTaquari;

use PDO;
use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Coleta dados operacionais da UHE Castro Alves (CERAN).
 *
 * Fonte: https://ceran.com.br/dados_hidrologicos/dados_hidrologicos_UHCA.php
 * Dados horários: Vazão Afluente, Defluência, Nível Montante/Jusante.
 *
 * A Defluência (turbinada + vertida + remanescente) é o volume de água que
 * efetivamente desce em direção a Santa Tereza → Muçum → Encantado → Lajeado.
 * É o dado mais direto para prever a cota em Lajeado com antecedência.
 */
class CeranCollector
{
    private const URL_UHCA = 'https://ceran.com.br/dados_hidrologicos/dados_hidrologicos_UHCA.php';

    // Índice da coluna na tabela HTML → estacao_id, tipo, nome legível
    // Colunas: 0=Data/Hora | 1=Nív.Montante | 2=Nív.Jusante | 3=Vaz.Afluente
    //          4=Vaz.Turbinada | 5=Vaz.Vertida | 6=Vaz.Remanescente | 7=Defluência
    private const COLUNAS = [
        1 => ['id' => 'ceran_ca_nivel_montante', 'tipo' => 'cota',  'nome' => 'UHCA Castro Alves — Nível Montante'],
        2 => ['id' => 'ceran_ca_nivel_jusante',  'tipo' => 'cota',  'nome' => 'UHCA Castro Alves — Nível Jusante'],
        3 => ['id' => 'ceran_ca_afluente',        'tipo' => 'vazao', 'nome' => 'UHCA Castro Alves — Vazão Afluente'],
        7 => ['id' => 'ceran_ca_defluencia',      'tipo' => 'vazao', 'nome' => 'UHCA Castro Alves — Defluência'],
    ];

    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $log,
        private readonly array  $cfg,
    ) {}

    /**
     * Executa a coleta completa: busca, parseia e persiste os dados.
     */
    public function coletar(): array
    {
        $this->garantirEstacoes();

        try {
            $html   = $this->fetchHtml();
            $linhas = $this->parseTabela($html);
        } catch (\Throwable $e) {
            $this->log->error('CeranCollector: erro ao buscar/parsear', ['erro' => $e->getMessage()]);
            return ['novas' => 0, 'linhas_parsadas' => 0, 'erro' => $e->getMessage()];
        }

        if (empty($linhas)) {
            $this->log->warning('CeranCollector: tabela vazia ou formato inesperado');
            return ['novas' => 0, 'linhas_parsadas' => 0, 'erro' => 'tabela vazia'];
        }

        $novas = $this->salvar($linhas);
        $this->log->info('CeranCollector: coleta OK', [
            'linhas_parsadas' => count($linhas),
            'novas'           => $novas,
        ]);

        return ['novas' => $novas, 'linhas_parsadas' => count($linhas), 'erro' => null];
    }

    // ── privados ───────────────────────────────────────────────────────────────

    private function fetchHtml(): string
    {
        $timeout = $this->cfg['sgb']['timeout'] ?? 30;
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => (int)$timeout,
                'user_agent'      => 'ValeTaquariTempo/1.0 (https://github.com/lucasleandrodemoura/hidrosite)',
                'follow_location' => true,
                'max_redirects'   => 3,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents(self::URL_UHCA, false, $ctx);
        if ($html === false) {
            $err = error_get_last()['message'] ?? 'erro desconhecido';
            throw new RuntimeException("Falha ao buscar UHCA: {$err}");
        }
        if (strlen($html) < 100) {
            throw new RuntimeException('Resposta da UHCA muito curta (' . strlen($html) . ' bytes)');
        }
        return $html;
    }

    /**
     * Parseia a tabela HTML e retorna linhas de dados.
     * Cada linha: ['ts' => 'YYYY-MM-DD HH:MM:SS', colIdx => valor_float, ...]
     */
    private function parseTabela(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Procura todas as linhas de tabela que tenham células <td>
        $rows = $xpath->query('//table//tr[td]');
        if ($rows === false || $rows->length === 0) {
            $rows = $xpath->query('//tr[td]');
        }
        if ($rows === false || $rows->length === 0) {
            throw new RuntimeException('Nenhuma linha <tr><td> encontrada no HTML');
        }

        $result = [];
        foreach ($rows as $row) {
            $tds = $row->getElementsByTagName('td');
            if ($tds->length < 8) continue;

            // Coluna 0: Data/Hora — "DD/MM/YYYY HH:MM" ou "DD/MM/YYYY às HH:MM"
            $tsRaw = trim($tds->item(0)->textContent);
            $ts    = $this->normalizarTimestamp($tsRaw);
            if ($ts === null) continue;

            $linha = ['ts' => $ts];
            foreach (self::COLUNAS as $idx => $info) {
                $raw    = trim($tds->item($idx)->textContent ?? '');
                $valStr = str_replace([',', ' ', "\xc2\xa0"], ['.', '', ''], $raw); // remove nbsp
                if (!is_numeric($valStr)) continue;
                $linha[$idx] = (float)$valStr;
            }

            // Exige pelo menos a defluência (coluna 7)
            if (isset($linha[7])) {
                $result[] = $linha;
            }
        }

        return $result;
    }

    private function normalizarTimestamp(string $raw): ?string
    {
        // Remove " às " e espaços extras
        $raw = trim(preg_replace('/\s+[àa]s\s+/iu', ' ', $raw));
        $raw = preg_replace('/\s+/', ' ', $raw);

        // DD/MM/YYYY HH:MM ou DD/MM/YYYY HH:MM:SS
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})\s+(\d{2}):(\d{2})(?::(\d{2}))?$#', $raw, $m)) {
            $ss = $m[6] ?? '00';
            return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$ss}";
        }
        // YYYY-MM-DD HH:MM:SS já normalizado
        if (preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$#', $raw)) {
            return $raw;
        }
        return null;
    }

    private function salvar(array $linhas): int
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? 'INSERT INTO leituras (estacao_id, tipo, timestamp, valor)
               VALUES (:est, :tipo, :ts, :val) ON CONFLICT (estacao_id, timestamp) DO NOTHING'
            : 'INSERT OR IGNORE INTO leituras (estacao_id, tipo, timestamp, valor)
               VALUES (:est, :tipo, :ts, :val)';

        $stmt  = $this->pdo->prepare($sql);
        $novas = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($linhas as $linha) {
                foreach (self::COLUNAS as $idx => $info) {
                    if (!isset($linha[$idx])) continue;
                    $stmt->execute([
                        ':est'  => $info['id'],
                        ':tipo' => $info['tipo'],
                        ':ts'   => $linha['ts'],
                        ':val'  => $linha[$idx],
                    ]);
                    $novas += $stmt->rowCount();
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $novas;
    }

    /** Garante que as estações da Ceran existem na tabela estacoes. */
    private function garantirEstacoes(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'pgsql'
            ? 'INSERT INTO estacoes (id, nome, tipo) VALUES (:id, :nome, :tipo) ON CONFLICT (id) DO NOTHING'
            : 'INSERT OR IGNORE INTO estacoes (id, nome, tipo) VALUES (:id, :nome, :tipo)';

        $stmt = $this->pdo->prepare($sql);
        foreach (self::COLUNAS as $info) {
            $stmt->execute([':id' => $info['id'], ':nome' => $info['nome'], ':tipo' => $info['tipo']]);
        }
    }
}
