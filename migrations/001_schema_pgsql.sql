-- =============================================================================
-- Schema para PostgreSQL — Vale Taquari Tempo / Coletor Hidrológico SGB
-- Execute: psql -U postgres -d hidro -f migrations/001_schema_pgsql.sql
-- =============================================================================

-- Estações monitoradas --------------------------------------------------------
CREATE TABLE IF NOT EXISTS estacoes (
    id   TEXT PRIMARY KEY,
    nome TEXT NOT NULL,
    tipo TEXT NOT NULL CHECK (tipo IN ('chuva', 'cota'))
);

-- Leituras brutas -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leituras (
    id          SERIAL PRIMARY KEY,
    estacao_id  TEXT        NOT NULL REFERENCES estacoes(id),
    tipo        TEXT        NOT NULL CHECK (tipo IN ('chuva', 'cota')),
    timestamp   TIMESTAMPTZ NOT NULL,
    valor       DOUBLE PRECISION NOT NULL,
    coletado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (estacao_id, timestamp)
);

CREATE INDEX IF NOT EXISTS idx_leituras_estacao_ts ON leituras (estacao_id, timestamp);
CREATE INDEX IF NOT EXISTS idx_leituras_ts         ON leituras (timestamp);
CREATE INDEX IF NOT EXISTS idx_leituras_tipo_ts    ON leituras (tipo, timestamp);

-- Episódios de cheia ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS eventos (
    id                            SERIAL PRIMARY KEY,

    inicio_chuva                  TIMESTAMPTZ,
    fim_chuva                     TIMESTAMPTZ,

    chuva_acumulada_por_estacao   JSONB,
    chuva_media_cabeceira         DOUBLE PRECISION,

    cota_maxima_lajeado           DOUBLE PRECISION,
    cota_maxima_mucum             DOUBLE PRECISION,
    cota_maxima_encantado         DOUBLE PRECISION,

    data_pico_lajeado             TIMESTAMPTZ,
    data_pico_mucum               TIMESTAMPTZ,
    data_pico_encantado           TIMESTAMPTZ,

    defasagem_cabeceira_mucum_h   DOUBLE PRECISION,
    defasagem_mucum_encantado_h   DOUBLE PRECISION,
    defasagem_encantado_lajeado_h DOUBLE PRECISION,

    excesso_cota_lajeado          DOUBLE PRECISION,
    razao_calculada               DOUBLE PRECISION,

    status                        TEXT NOT NULL DEFAULT 'aberto'
                                       CHECK (status IN ('aberto', 'fechado')),

    criado_em                     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    fechado_em                    TIMESTAMPTZ
);

-- Log de coleta ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS log_coleta (
    id           SERIAL PRIMARY KEY,
    estacao_id   TEXT,
    status       TEXT,
    linhas_novas INTEGER  NOT NULL DEFAULT 0,
    erro         TEXT,
    executado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
