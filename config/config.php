<?php
declare(strict_types=1);

/**
 * Configuração central do sistema.
 * Valores padrão são sobrescritos pelas variáveis de ambiente (arquivo .env).
 */
return [

    'db' => [
        'driver'      => $_ENV['DB_DRIVER']      ?? 'sqlite',
        'sqlite_path' => $_ENV['DB_SQLITE_PATH'] ?? __DIR__ . '/../data/hidro.sqlite',
        'pgsql' => [
            'host'   => $_ENV['DB_HOST'] ?? 'localhost',
            'port'   => $_ENV['DB_PORT'] ?? '5432',
            'dbname' => $_ENV['DB_NAME'] ?? 'hidro',
            'user'   => $_ENV['DB_USER'] ?? 'postgres',
            'pass'   => $_ENV['DB_PASS'] ?? '',
        ],
    ],

    'sgb' => [
        'base_url' => $_ENV['SGB_BASE_URL'] ?? 'https://sace.sgb.gov.br/api/dados/',
        'timeout'  => (int)($_ENV['SGB_TIMEOUT'] ?? 30),
    ],

    /*
     * Estações monitoradas.
     * Chave  → código usado na URL da API do SGB ({codigo}.csv)
     * Valor  → nome legível
     */
    'estacoes' => [
        // Lista completa de postos de chuva coletados e exibidos no dashboard.
        'chuva' => [
            'taquari_9_chuva'  => 'Vacaria',
            'taquari_12_chuva' => 'Ibiraiaras',
            'taquari_31_chuva' => 'Guaporé',
            'taquari_54_chuva' => 'Passo Carreiro',
            'taquari_32_chuva' => 'Santa Tereza',
            'taquari_55_chuva' => 'Linha Colombo',
            'taquari_33_chuva' => 'Barra do Fão',
            'taquari_5_chuva'  => 'Bom Retiro do Sul', // rio abaixo de Lajeado
            'taquari_6_chuva'  => 'Mariante',           // rio abaixo de Bom Retiro do Sul
        ],
        // Subconjunto de estações de cabeceira usado no cálculo de chuva média
        // (mediaCabeceira) que decide abertura/fechamento de evento de cheia e
        // alimenta a razão histórica. Bom Retiro do Sul e Mariante ficam rio
        // abaixo de Lajeado — chuva lá não antecede a cheia em Lajeado, por
        // isso ficam fora desta lista mesmo estando em 'chuva' acima.
        'chuva_cabeceira' => [
            'taquari_9_chuva', 'taquari_12_chuva', 'taquari_31_chuva',
            'taquari_54_chuva', 'taquari_32_chuva', 'taquari_55_chuva', 'taquari_33_chuva',
        ],
        'cota' => [
            'taquari_33_cota' => 'Barra do Fão',
            'taquari_4_cota'  => 'Linha José Júlio',  // entre cabeceiras e Santa Tereza
            'taquari_32_cota' => 'Santa Tereza',
            'taquari_55_cota' => 'Linha Colombo',
            'taquari_3_cota'  => 'Muçum',
            'taquari_2_cota'  => 'Encantado',
            'taquari_1_cota'  => 'Estrela/Lajeado',
            'taquari_5_cota'  => 'Bom Retiro do Sul',
            'taquari_6_cota'  => 'Mariante',
        ],
    ],

    /*
     * Parâmetros de detecção de eventos de cheia.
     * Todos os limiares são ajustáveis via .env.
     *
     * Limiares de cota em METROS (SGB entrega em cm; armazenamos /100).
     * Lajeado usa documentos oficiais em mm → dividir por 1000.
     */
    'evento' => [
        // mm acumulados em janela_chuva_h horas para abrir um evento
        'limiar_abertura_mm'   => (float)($_ENV['LIMIAR_CHUVA_ABERTURA_MM'] ?? 15.0),
        // horas sem chuva relevante para fechar evento
        'limiar_fechamento_h'  => (int)($_ENV['LIMIAR_FECHAMENTO_H'] ?? 12),
        // duração mínima (h) para o evento entrar no cálculo de razão
        'min_horas_evento'     => (int)($_ENV['MIN_HORAS_EVENTO'] ?? 18),
        // janela de observação para chuva acumulada (horas)
        'janela_chuva_h'       => 6,
        // cotas de atenção (metros)
        'cota_atencao' => [
            'taquari_1_cota'  => (float)($_ENV['COTA_ATENCAO_LAJEADO']          ?? 15.00),
            'taquari_2_cota'  => (float)($_ENV['COTA_ATENCAO_ENCANTADO']        ??  9.00),
            'taquari_3_cota'  => (float)($_ENV['COTA_ATENCAO_MUCUM']            ??  9.00),
            'taquari_4_cota'  => (float)($_ENV['COTA_ATENCAO_LJ_JULIO']         ??  5.00),
            'taquari_32_cota' => (float)($_ENV['COTA_ATENCAO_STA_TEREZA']       ??  9.00),
            'taquari_33_cota' => (float)($_ENV['COTA_ATENCAO_BARRA_FAO']        ??  6.00),
            'taquari_55_cota' => (float)($_ENV['COTA_ATENCAO_LINHA_COLOMBO']    ??  7.00),
            'taquari_5_cota'  => (float)($_ENV['COTA_ATENCAO_BOM_RETIRO_SUL']   ?? 12.00),
            'taquari_6_cota'  => (float)($_ENV['COTA_ATENCAO_MARIANTE']         ?? 11.00),
        ],
        // cotas de inundação (metros)
        'cota_inundacao' => [
            'taquari_1_cota'  => (float)($_ENV['COTA_INUNDACAO_LAJEADO']          ?? 19.00),
            'taquari_2_cota'  => (float)($_ENV['COTA_INUNDACAO_ENCANTADO']        ?? 12.00),
            'taquari_3_cota'  => (float)($_ENV['COTA_INUNDACAO_MUCUM']            ?? 18.00),
            'taquari_4_cota'  => (float)($_ENV['COTA_INUNDACAO_LJ_JULIO']         ??  8.00),
            'taquari_32_cota' => (float)($_ENV['COTA_INUNDACAO_STA_TEREZA']       ?? 15.00),
            'taquari_33_cota' => (float)($_ENV['COTA_INUNDACAO_BARRA_FAO']        ?? 10.00),
            'taquari_55_cota' => (float)($_ENV['COTA_INUNDACAO_LINHA_COLOMBO']    ?? 12.50),
            'taquari_5_cota'  => (float)($_ENV['COTA_INUNDACAO_BOM_RETIRO_SUL']   ?? 16.50),
            'taquari_6_cota'  => (float)($_ENV['COTA_INUNDACAO_MARIANTE']         ?? 14.00),
        ],
        // nível mínimo do leito (piso físico para previsões — atualizar com zero hidrométrico oficial)
        'cota_minima_leito' => [
            'taquari_1_cota'  => (float)($_ENV['COTA_MIN_LAJEADO']          ?? 12.00),
            'taquari_2_cota'  => (float)($_ENV['COTA_MIN_ENCANTADO']        ??  0.50),
            'taquari_3_cota'  => (float)($_ENV['COTA_MIN_MUCUM']            ??  1.00),
            'taquari_4_cota'  => (float)($_ENV['COTA_MIN_LJ_JULIO']         ??  1.00),
            'taquari_32_cota' => (float)($_ENV['COTA_MIN_STA_TEREZA']       ??  1.50),
            'taquari_33_cota' => (float)($_ENV['COTA_MIN_BARRA_FAO']        ??  1.00),
            'taquari_55_cota' => (float)($_ENV['COTA_MIN_LINHA_COLOMBO']    ??  1.00),
        ],
    ],

    'log' => [
        'path'  => $_ENV['LOG_PATH']  ?? __DIR__ . '/../logs/hidro.log',
        'level' => $_ENV['LOG_LEVEL'] ?? 'info',
    ],
];
