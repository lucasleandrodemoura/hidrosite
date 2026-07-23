# Banco de Dados — Vale Taquari Tempo

## Arquivos disponíveis

| Arquivo | Descrição |
|---|---|
| `schema.sql` | Apenas a estrutura (tabelas, índices, sequences) — sem dados |
| `hidro_backup.sql` | Schema + todos os dados históricos coletados |

## Como restaurar

### Pré-requisitos
- PostgreSQL 14+ instalado
- Banco de dados criado: `createdb -U postgres hidro`

### Restaurar schema + dados (recomendado)

```bash
psql -U postgres -h localhost -d hidro -f hidro_backup.sql
```

### Restaurar apenas a estrutura (banco vazio)

```bash
psql -U postgres -h localhost -d hidro -f schema.sql
```

### Windows (PowerShell)

```powershell
$env:PGPASSWORD = "sua_senha"
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h localhost -d hidro -f hidro_backup.sql
```

## Gerar novo backup

```bash
# Linux/macOS
PGPASSWORD=senha pg_dump -U postgres -h localhost -d hidro -f database/hidro_backup.sql

# Windows (PowerShell)
$env:PGPASSWORD = "senha"
& "C:\Program Files\PostgreSQL\18\bin\pg_dump.exe" -U postgres -h localhost -d hidro -f database\hidro_backup.sql
```

## Estrutura das tabelas

- **leituras** — série temporal de cota (m) e chuva (mm) por estação, a cada ~15 min
- **eventos** — episódios de cheia detectados automaticamente (abertura/fechamento + razão calibrada)

O campo `valor` em `leituras` de tipo `cota` é armazenado em **metros** (SGB entrega em cm; a conversão é feita na ingestão).
