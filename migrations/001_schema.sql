-- =============================================================================
-- Schema do sistema Vale Taquari Tempo – Coletor Hidrológico SGB
-- Compatível com SQLite (padrão) e PostgreSQL.
-- Em PostgreSQL: troque INTEGER PRIMARY KEY AUTOINCREMENT por SERIAL PRIMARY KEY
--               e DATETIME por TIMESTAMPTZ.
-- =============================================================================

-- Estações monitoradas --------------------------------------------------------
CREATE TABLE IF NOT EXISTS estacoes (
    id   TEXT PRIMARY KEY,
    nome TEXT NOT NULL,
    tipo TEXT NOT NULL CHECK (tipo IN ('chuva', 'cota'))
);

-- Leituras brutas (uma linha por leitura de 15 min) ---------------------------
CREATE TABLE IF NOT EXISTS leituras (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    estacao_id  TEXT     NOT NULL REFERENCES estacoes(id),
    tipo        TEXT     NOT NULL CHECK (tipo IN ('chuva', 'cota')),
    timestamp   DATETIME NOT NULL,
    valor       REAL     NOT NULL,
    coletado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (estacao_id, timestamp)   -- deduplicação automática
);

CREATE INDEX IF NOT EXISTS idx_leituras_estacao_ts ON leituras (estacao_id, timestamp);
CREATE INDEX IF NOT EXISTS idx_leituras_ts         ON leituras (timestamp);
CREATE INDEX IF NOT EXISTS idx_leituras_tipo_ts    ON leituras (tipo, timestamp);

-- Episódios de cheia ----------------------------------------------------------
-- Uma linha por evento; preenchida automaticamente pelo EventDetector.
CREATE TABLE IF NOT EXISTS eventos (
    id                            INTEGER PRIMARY KEY AUTOINCREMENT,

    -- delimitação temporal do episódio de chuva
    inicio_chuva                  DATETIME,
    fim_chuva                     DATETIME,

    -- chuva acumulada por estação: JSON { "taquari_9_chuva": 42.5, ... }
    chuva_acumulada_por_estacao   TEXT,
    -- média simples entre as estações de cabeceira
    chuva_media_cabeceira         REAL,

    -- cotas máximas atingidas durante o evento
    cota_maxima_lajeado           REAL,
    cota_maxima_mucum             REAL,
    cota_maxima_encantado         REAL,

    -- momentos em que cada estação atingiu o pico
    data_pico_lajeado             DATETIME,
    data_pico_mucum               DATETIME,
    data_pico_encantado           DATETIME,

    -- defasagens entre picos (horas)
    -- positivo = pico posterior ao pico de chuva de cabeceira
    defasagem_cabeceira_mucum_h   REAL,
    defasagem_mucum_encantado_h   REAL,
    defasagem_encantado_lajeado_h REAL,

    -- excesso = cota_maxima - cota_inundacao (base da razão)
    excesso_cota_lajeado          REAL,

    -- RAZÃO CENTRAL: chuva_media_cabeceira / excesso_cota_lajeado
    -- Quanto de chuva (mm) foi necessário para cada metro de excesso de cota
    razao_calculada               REAL,

    -- 'aberto' enquanto o episódio está em andamento; 'fechado' ao encerrar
    status                        TEXT NOT NULL DEFAULT 'aberto'
                                       CHECK (status IN ('aberto', 'fechado')),

    criado_em                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fechado_em                    DATETIME
);

-- Log de cada execução do coletor ---------------------------------------------
CREATE TABLE IF NOT EXISTS log_coleta (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    estacao_id   TEXT,
    status       TEXT,    -- 'ok' | 'erro'
    linhas_novas INTEGER  NOT NULL DEFAULT 0,
    erro         TEXT,
    executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
