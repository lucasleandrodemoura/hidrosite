<?php
declare(strict_types=1);
/**
 * Registra manualmente o evento de cheia de julho/2026 no banco.
 *
 * Dados medidos:
 *   - Mínimo pré-evento:  12.40 m em 17/07 09:00
 *   - Pico Lajeado:       24.77 m em 23/07 00:45
 *   - Pico Muçum:         19.29 m em 22/07 13:30
 *   - Cota inundação Laj: 19.00 m
 *   - Excesso:             5.77 m
 *   - Chuva média 48h:   121.4 mm  (janela mais preditiva)
 *   - Chuva média 72h:   161.8 mm
 *   - Razão (48h):        21.0 mm/m  (= 121.4 / 5.77)
 *   - Def. cab→Muçum:     60.8 h (de 20/07 00:45 a 22/07 13:30)
 *   - Def. Muçum→Laj:     11.3 h (pico-a-pico medido)
 *   - Def. Enc→Laj:        2.8 h (cross-correlação; pico Enc com dado ruim)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Bootstrap.php';

$cfg = ValeTaquari\Bootstrap::init();
$pdo = ValeTaquari\Database::get($cfg['db']);

// Verifica se evento deste período já existe
$existe = $pdo->query(
    "SELECT COUNT(*) FROM eventos
     WHERE inicio_chuva BETWEEN '2026-07-18' AND '2026-07-22'
       AND status = 'fechado'"
)->fetchColumn();

if ($existe > 0) {
    echo "Evento de jul/2026 já registrado. Nada a fazer." . PHP_EOL;
    exit(0);
}

// Chuva acumulada por estação durante o evento (desde inicio_chuva até pico+6h)
$stmt = $pdo->prepare(
    "SELECT estacao_id, COALESCE(SUM(valor::float), 0) AS total
     FROM leituras
     WHERE tipo = 'chuva'
       AND timestamp BETWEEN '2026-07-20 00:45:00' AND '2026-07-23 06:45:00'
     GROUP BY estacao_id"
);
$stmt->execute();
$acumJson = json_encode(
    array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'total', 'estacao_id'),
    JSON_UNESCAPED_UNICODE
);

$pdo->prepare(
    "INSERT INTO eventos (
        inicio_chuva, fim_chuva,
        chuva_acumulada_por_estacao, chuva_media_cabeceira,
        cota_maxima_lajeado,  cota_maxima_mucum,   cota_maxima_encantado,
        data_pico_lajeado,    data_pico_mucum,      data_pico_encantado,
        defasagem_cabeceira_mucum_h, defasagem_mucum_encantado_h, defasagem_encantado_lajeado_h,
        excesso_cota_lajeado, razao_calculada,
        status, fechado_em
    ) VALUES (
        '2026-07-20 00:45:00', '2026-07-23 06:45:00',
        :acum_json, 121.4,
        24.77,  19.29,  NULL,
        '2026-07-23 00:45:00', '2026-07-22 13:30:00', NULL,
        60.75,  11.30,  2.80,
        5.77,   21.0,
        'fechado', NOW()
    )"
)->execute([':acum_json' => $acumJson]);

echo "Evento jul/2026 inserido com sucesso." . PHP_EOL;
echo "  Pico Lajeado: 24.77m | Excesso: 5.77m | Razão: 21.0 mm/m" . PHP_EOL;
echo "  Defasagens: cab→Muçum 60.8h | Muçum→Lajeado 11.3h | Enc→Lajeado 2.8h" . PHP_EOL;
