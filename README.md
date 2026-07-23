# Vale Taquari Tempo — Coletor Hidrológico SGB

Coleta automática de dados do [SGB/CPRM](https://www.sgb.gov.br/) para a bacia do Rio Taquari-Antas.  
Constrói uma **razão histórica chuva×cota** e gera **projeções de cheia em Lajeado/Estrela**.

> **Licença:** Vale Taquari Tempo Open License v1.0 — uso não-comercial, compartilhamento obrigatório.  
> Créditos devem ser mantidos. Melhorias devem ser devolvidas à comunidade. Ver [`LICENSE`](LICENSE).

---

## O que faz

| Componente | Função |
|---|---|
| `Collector` | Busca CSVs do SGB a cada 15 min, salva só linhas novas |
| `EventDetector` | Detecta episódios de cheia, fecha-os quando a chuva cessa e calcula métricas |
| `Projector` | Usa a razão histórica para projetar cota máxima + tempo até pico |
| API REST | Expõe leituras, eventos e projeções via HTTP/JSON |

### Estações monitoradas

**Chuva (cabeceira):** Vacaria · Ibiraiaras · Guaporé · Passo Carreiro · Santa Tereza · Linha Colombo · Barra do Fão  
**Cota (calibração):** Muçum → Encantado → Estrela/Lajeado

### Razão histórica

```
razão = chuva_média_cabeceira_mm / excesso_cota_lajeado_m
      onde excesso = cota_máxima − cota_de_inundação (padrão: 23,00 m)
```

A cada evento de cheia fechado, uma nova observação é adicionada. Com 5–10 eventos, a razão média torna-se confiável para projeções.

---

## Instalação

### Pré-requisitos

- PHP ≥ 8.1 com extensões: `pdo`, `pdo_sqlite` (ou `pdo_pgsql`), `openssl`
- Composer
- Acesso à internet para buscar CSVs do SGB

### Passos

```bash
git clone https://github.com/vale-taquari/hidro-coletor.git
cd hidro-coletor

composer install

cp .env.example .env
# Edite .env conforme necessário

php migrations/run.php   # Cria banco e popula estações
```

### Iniciar servidor de desenvolvimento

```bash
php -S 0.0.0.0:8080 public/api.php
```

### Configurar coleta automática (Linux/cron)

```cron
*/15 * * * *  /usr/bin/php /caminho/para/cron/collect.php >> /var/log/hidro.log 2>&1
```

### Configurar coleta automática (Windows Task Scheduler)

Programa: `php.exe`  
Argumentos: `C:\caminho\para\cron\collect.php`  
Gatilho: repetir a cada 15 minutos, indefinidamente.

---

## API

### `GET /` — Status

```json
{
  "sistema": "Vale Taquari Tempo – Coletor Hidrológico SGB",
  "status": "online",
  "leituras_total": 18240,
  "eventos_fechados": 3,
  "ultima_coleta": "2025-05-01 14:30:00"
}
```

### `GET /leituras?horas=24&tipo=cota`

Parâmetros opcionais: `horas` (padrão 24, máx 168), `estacao`, `tipo` (chuva|cota), `limite`.

### `GET /eventos?status=fechado`

Lista episódios de cheia com razão calculada e defasagens.

### `GET /razao-historica`

```json
{
  "razao_historica": {
    "n_eventos": 3,
    "razao_media": 12.4,
    "defasagem_total_media_h": 38.5,
    "defasagem_detalhada": {
      "cabeceira_mucum_h": 12.0,
      "mucum_encantado_h": 8.5,
      "encantado_lajeado_h": 18.0
    }
  }
}
```

### `POST /projetar`

**Corpo (JSON):**
```json
{
  "chuva_por_estacao": {
    "taquari_9_chuva":  80.0,
    "taquari_12_chuva": 65.0,
    "taquari_31_chuva": 90.0
  }
}
```

Ou simplificado (mesmo valor para todas as estações):
```json
{ "chuva_mm": 80 }
```

**Resposta:**
```json
{
  "status": "ok",
  "entrada": { "chuva_media_mm": 78.3 },
  "projecao": {
    "cota_projetada_m": 29.3,
    "excesso_sobre_inundacao": 6.3,
    "horas_ate_pico": 38.5,
    "intervalo_confianca": {
      "cota_minima": 27.1,
      "cota_maxima": 32.0
    }
  },
  "calibracao": {
    "razao_utilizada": 12.4,
    "n_eventos_historicos": 3,
    "confiabilidade": "baixa"
  }
}
```

### `POST /coletar` — Coleta manual

Requer header `X-Admin-Token: <valor>` se `ADMIN_TOKEN` estiver configurado no `.env`.

---

## Configuração (`.env`)

| Variável | Padrão | Descrição |
|---|---|---|
| `DB_DRIVER` | `sqlite` | `sqlite` ou `pgsql` |
| `COTA_INUNDACAO_LAJEADO` | `23.00` | Cota de inundação em metros |
| `COTA_INUNDACAO_MUCUM` | `15.00` | Idem para Muçum |
| `COTA_INUNDACAO_ENCANTADO` | `18.00` | Idem para Encantado |
| `LIMIAR_CHUVA_ABERTURA_MM` | `15.0` | mm em 6h para abrir evento |
| `LIMIAR_FECHAMENTO_H` | `12` | Horas sem chuva para fechar evento |
| `MIN_HORAS_EVENTO` | `18` | Duração mínima para evento contar na razão |
| `ADMIN_TOKEN` | *(vazio)* | Token para endpoint `/coletar` |

---

## Aviso importante

As projeções são **estimativas** baseadas em dados históricos limitados.  
**Não substituem alertas oficiais** da Defesa Civil do RS ou da SACE/SGB.  
Sempre consulte os órgãos competentes em situações de risco.

---

## Créditos

Dados hidrológicos: **SGB/CPRM** — [sgb.gov.br](https://www.sgb.gov.br/)  
Desenvolvido com ♥ para as comunidades do Vale do Taquari.

**Para contribuir:** abra um Pull Request no repositório oficial.  
Toda melhoria deve ser compartilhada de volta à comunidade (ver `LICENSE`).
