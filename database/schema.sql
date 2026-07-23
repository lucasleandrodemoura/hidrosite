--
-- PostgreSQL database dump
--

\restrict yDLqZvDARf0bPkKa1jzkKwzgAkQE9ifmYL31fxi90dVcGb1XBt9VgBOngElxhmB

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: estacoes; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.estacoes (
    id text NOT NULL,
    nome text NOT NULL,
    tipo text NOT NULL,
    CONSTRAINT estacoes_tipo_check CHECK ((tipo = ANY (ARRAY['chuva'::text, 'cota'::text])))
);


ALTER TABLE public.estacoes OWNER TO postgres;

--
-- Name: eventos; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.eventos (
    id integer NOT NULL,
    inicio_chuva timestamp with time zone,
    fim_chuva timestamp with time zone,
    chuva_acumulada_por_estacao jsonb,
    chuva_media_cabeceira double precision,
    cota_maxima_lajeado double precision,
    cota_maxima_mucum double precision,
    cota_maxima_encantado double precision,
    data_pico_lajeado timestamp with time zone,
    data_pico_mucum timestamp with time zone,
    data_pico_encantado timestamp with time zone,
    defasagem_cabeceira_mucum_h double precision,
    defasagem_mucum_encantado_h double precision,
    defasagem_encantado_lajeado_h double precision,
    excesso_cota_lajeado double precision,
    razao_calculada double precision,
    status text DEFAULT 'aberto'::text NOT NULL,
    criado_em timestamp with time zone DEFAULT now() NOT NULL,
    fechado_em timestamp with time zone,
    CONSTRAINT eventos_status_check CHECK ((status = ANY (ARRAY['aberto'::text, 'fechado'::text])))
);


ALTER TABLE public.eventos OWNER TO postgres;

--
-- Name: eventos_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.eventos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.eventos_id_seq OWNER TO postgres;

--
-- Name: eventos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.eventos_id_seq OWNED BY public.eventos.id;


--
-- Name: leituras; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.leituras (
    id integer NOT NULL,
    estacao_id text NOT NULL,
    tipo text NOT NULL,
    "timestamp" timestamp with time zone NOT NULL,
    valor double precision NOT NULL,
    coletado_em timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT leituras_tipo_check CHECK ((tipo = ANY (ARRAY['chuva'::text, 'cota'::text])))
);


ALTER TABLE public.leituras OWNER TO postgres;

--
-- Name: leituras_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.leituras_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.leituras_id_seq OWNER TO postgres;

--
-- Name: leituras_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.leituras_id_seq OWNED BY public.leituras.id;


--
-- Name: log_coleta; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.log_coleta (
    id integer NOT NULL,
    estacao_id text,
    status text,
    linhas_novas integer DEFAULT 0 NOT NULL,
    erro text,
    executado_em timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.log_coleta OWNER TO postgres;

--
-- Name: log_coleta_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.log_coleta_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.log_coleta_id_seq OWNER TO postgres;

--
-- Name: log_coleta_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.log_coleta_id_seq OWNED BY public.log_coleta.id;


--
-- Name: eventos id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.eventos ALTER COLUMN id SET DEFAULT nextval('public.eventos_id_seq'::regclass);


--
-- Name: leituras id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leituras ALTER COLUMN id SET DEFAULT nextval('public.leituras_id_seq'::regclass);


--
-- Name: log_coleta id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.log_coleta ALTER COLUMN id SET DEFAULT nextval('public.log_coleta_id_seq'::regclass);


--
-- Name: estacoes estacoes_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.estacoes
    ADD CONSTRAINT estacoes_pkey PRIMARY KEY (id);


--
-- Name: eventos eventos_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.eventos
    ADD CONSTRAINT eventos_pkey PRIMARY KEY (id);


--
-- Name: leituras leituras_estacao_id_timestamp_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leituras
    ADD CONSTRAINT leituras_estacao_id_timestamp_key UNIQUE (estacao_id, "timestamp");


--
-- Name: leituras leituras_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leituras
    ADD CONSTRAINT leituras_pkey PRIMARY KEY (id);


--
-- Name: log_coleta log_coleta_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.log_coleta
    ADD CONSTRAINT log_coleta_pkey PRIMARY KEY (id);


--
-- Name: idx_leituras_estacao_ts; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leituras_estacao_ts ON public.leituras USING btree (estacao_id, "timestamp");


--
-- Name: idx_leituras_tipo_ts; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leituras_tipo_ts ON public.leituras USING btree (tipo, "timestamp");


--
-- Name: idx_leituras_ts; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leituras_ts ON public.leituras USING btree ("timestamp");


--
-- Name: leituras leituras_estacao_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leituras
    ADD CONSTRAINT leituras_estacao_id_fkey FOREIGN KEY (estacao_id) REFERENCES public.estacoes(id);


--
-- PostgreSQL database dump complete
--

\unrestrict yDLqZvDARf0bPkKa1jzkKwzgAkQE9ifmYL31fxi90dVcGb1XBt9VgBOngElxhmB

