# Vale Taquari Tempo — Monitor Hidrológico do Rio Taquari-Antas

> **Sistema open source de monitoramento de cheias** para o Vale do Taquari (RS, Brasil).  
> Coleta dados em tempo real do SGB/CPRM, detecta eventos de cheia automaticamente e projeta a cota máxima esperada em Lajeado/Estrela com base no histórico de chuvas na cabeceira.

[![Licença: VTT Open v1.0](https://img.shields.io/badge/licen%C3%A7a-VTT%20Open%20v1.0-blue)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)](https://php.net)
[![PostgreSQL](https://img.shields.io/badge/banco-PostgreSQL%20%7C%20SQLite-336791)](https://postgresql.org)
[![Dados: SGB/CPRM](https://img.shields.io/badge/dados-SGB%2FCPRM-009688)](https://www.sgb.gov.br/)

---

## O que é

O Rio Taquari-Antas tem histórico de cheias severas que afetam municípios como Estrela, Lajeado, Encantado e Muçum. Este sistema:

- **Coleta automaticamente** leituras de nível (cota) e chuva das estações do SGB a cada 15 minutos
- **Detecta episódios de cheia** em tempo real — abrindo e fechando eventos conforme a situação
- **Calibra uma razão histórica** entre chuva acumulada na cabeceira e cota atingida em Lajeado
- **Projeta a cota máxima** esperada a partir de um cenário hipotético de chuva
- **Exibe tudo em uma interface web** com mapa animado do rio, gráficos históricos e indicadores de tendência

---

## Estações monitoradas

### Cota (nível do rio)

| ID SGB | Localidade | Atenção | Inundação |
|---|---|---|---|
| `taquari_32_cota` | Santa Tereza | 9,00 m | 15,00 m |
| `taquari_3_cota` | Muçum | 9,00 m | 18,00 m |
| `taquari_2_cota` | Encantado | 9,00 m | 12,00 m |
| `taquari_33_cota` | Barra do Fão | 6,00 m | 10,00 m |
| `taquari_1_cota` | Estrela / Lajeado | 15,00 m | 19,00 m |

### Chuva (cabeceira)

Vacaria · Ibiraiaras · Guaporé · Passo Carreiro · Santa Tereza · Linha Colombo · Barra do Fão

---

## Funcionalidades

### Interface web
- Mapa animado do rio com fluxo colorido por situação (normal / atenção / cheia)
- Cards de cota com valor atual, tendência (↑↓→) e taxa em cm/h
- Cards de chuva acumulada nas últimas horas
- Gráficos históricos interativos (cota: linha com referências; chuva: barras por intensidade)
- Períodos selecionáveis: 6h · 24h · 3 dias · 7 dias · 30 dias · **todo o histórico**
- Botão de coleta manual com notificação de resultado

### API REST
- `GET /status-atual` — última leitura + tendência de todas as estações
- `GET /leituras` — série histórica filtrada por estação, tipo e período
- `GET /eventos` — episódios de cheia com métricas calibradas
- `GET /razao-historica` — razão média e defasagens entre estações
- `POST /projetar` — projeção de cota máxima em Lajeado dado cenário de chuva
- `POST /coletar` — dispara coleta manual

### Detecção de eventos
- Abre evento quando chuva média nas cabeceiras ≥ 15 mm em 6 horas
- Fecha quando ≥ 12 horas sem chuva relevante e evento tem ao menos 18 horas
- Ao fechar, calcula: `razão = chuva_média_mm / excesso_sobre_cota_inundação_m`
- Também registra defasagens entre estações (cabeceira→Muçum, Muçum→Encantado, Encantado→Lajeado)

---

## Instalação

### Pré-requisitos

- PHP ≥ 8.1 com extensões `pdo`, `pdo_pgsql` (ou `pdo_sqlite`), `openssl`
- Composer
- PostgreSQL 14+ (recomendado) ou SQLite

### Passos

```bash
git clone https://github.com/lucasleandrodemoura/hidrosite.git
cd hidrosite

composer install

cp .env.example .env
# Edite .env com suas credenciais de banco de dados

# Criar o banco e as tabelas
psql -U postgres -c "CREATE DATABASE hidro;"
psql -U postgres -d hidro -f migrations/001_schema_pgsql.sql

# (Opcional) restaurar dados históricos do backup incluído
psql -U postgres -d hidro -f database/hidro_backup.sql
```

### Servidor de desenvolvimento

```bash
php -S 0.0.0.0:8080 public/api.php
# Acesse http://localhost:8080
```

### Coleta automática — Linux/cron

```cron
*/15 * * * *  /usr/bin/php /caminho/para/cron/collect.php >> /var/log/hidro.log 2>&1
```

### Coleta automática — Windows (Agendador de Tarefas)

- **Programa:** `php.exe`
- **Argumentos:** `C:\caminho\para\cron\collect.php`
- **Gatilho:** repetir a cada 15 minutos, indefinidamente

---

## Configuração (`.env`)

Copie `.env.example` para `.env` e ajuste:

| Variável | Padrão | Descrição |
|---|---|---|
| `DB_DRIVER` | `pgsql` | `pgsql` ou `sqlite` |
| `DB_HOST` | `localhost` | Host do PostgreSQL |
| `DB_NAME` | `hidro` | Nome do banco |
| `COTA_INUNDACAO_LAJEADO` | `19.00` | Cota de inundação em metros |
| `COTA_ATENCAO_LAJEADO` | `15.00` | Cota de atenção em metros |
| `LIMIAR_CHUVA_ABERTURA_MM` | `15.0` | mm em 6 h para abrir evento |
| `LIMIAR_FECHAMENTO_H` | `12` | Horas sem chuva para fechar evento |
| `ADMIN_TOKEN` | *(vazio)* | Token opcional para `/coletar` |

---

## Banco de dados

A pasta `database/` contém:

- `schema.sql` — estrutura das tabelas (sem dados)
- `hidro_backup.sql` — schema + dados históricos coletados

Consulte [`database/README.md`](database/README.md) para instruções de restauração.

---

## Estrutura do projeto

```
hidrosite/
├── config/          # config.php — parâmetros centrais
├── cron/            # collect.php — ponto de entrada do cron
├── database/        # schema.sql + backup com dados históricos
├── migrations/      # SQL e scripts de migração
├── public/          # api.php (roteador) + index.html (interface)
├── src/             # Classes PHP (Collector, EventDetector, Projector…)
├── .env.example     # Modelo de configuração
└── LICENSE          # Vale Taquari Tempo Open License v1.0
```

---

## API — exemplos

### Projeção de cheia

```bash
curl -X POST http://localhost:8080/projetar \
  -H "Content-Type: application/json" \
  -d '{"chuva_mm": 90}'
```

```json
{
  "projecao": {
    "cota_projetada_m": 26.3,
    "excesso_sobre_inundacao": 7.3,
    "horas_ate_pico": 38.5,
    "intervalo_confianca": { "cota_minima": 24.1, "cota_maxima": 28.5 }
  },
  "calibracao": {
    "n_eventos_historicos": 4,
    "confiabilidade": "media"
  }
}
```

---

## Aviso

As projeções são **estimativas baseadas em dados históricos** e têm margem de incerteza.  
**Não substituem os alertas oficiais** da Defesa Civil do RS, do SGB/CPRM ou da ANA.  
Em situação de risco, siga sempre as orientações dos órgãos competentes.

---

## Licença

[Vale Taquari Tempo Open License v1.0](LICENSE) — uso não-comercial, compartilhamento obrigatório.

- Créditos e atribuição devem ser mantidos em qualquer derivação
- Melhorias devem ser publicadas com a mesma licença
- Permitido: publicidade em interfaces públicas gratuitas; publicação científica (paga ou gratuita)
- Proibido: vender o software, cobrar assinaturas, SaaS comercial

---

## Créditos

Dados hidrológicos: **SGB/CPRM** — [sgb.gov.br](https://www.sgb.gov.br/)  
Desenvolvido com dedicação para as comunidades atingidas pelas cheias do Vale do Taquari.

**Repositório:** [github.com/lucasleandrodemoura/hidrosite](https://github.com/lucasleandrodemoura/hidrosite)  
**Contribuições são bem-vindas** — abra um Pull Request e compartilhe de volta com a comunidade.
