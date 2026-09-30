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

## Previsão de cota em Lajeado — como funciona e até onde vale

### Modelo MLR-Lag v3 (multi-horizonte)

`scripts/train_mlr.php` treina uma **regressão linear múltipla com defasagens**
(Ridge, λ=0,1) que prevê a cota em Lajeado, com **um modelo por horizonte**
(3 h, 6 h, … 24 h). As entradas são as cotas das réguas de montante e suas
tendências, mais a chuva acumulada em 6 h/12 h/24 h/48 h.

```bash
php scripts/train_mlr.php                      # todos os horizontes
php scripts/train_mlr.php --horizontes=6,12    # só alguns
```

O treino leva ~5 s e o `cron/collect.php` o dispara sozinho quando um evento de
cheia fecha (é quando entra um caso novo na validação e na razão chuva/cota) ou
quando o modelo passa de 7 dias. Roda em processo separado, então uma falha no
treino não interrompe a coleta. O `data/mlr_coefs.json` não é versionado: cada
instalação gera o seu.

### O limite de previsibilidade é ~12 h — e o sistema é explícito sobre isso

O tempo medido entre o centro de massa da chuva na cabeceira e o pico em Lajeado
é **11,2 h a 12,8 h** nos quatro eventos observados (desvio baixo). Esse é o
tempo de resposta da bacia, e portanto o alcance em que a previsão é
determinística: a cota daqui a 12 h **já está dentro da bacia**, medida pelas
réguas de montante. Além disso, ela passa a depender de chuva que ainda não caiu.

A validação confirma o degrau com precisão — erro médio por horizonte, medido
deixando cada cheia inteira fora do treino:

| Horizonte | Erro médio (MAE) | P90 | Erro no pico | Ganho sobre persistência |
|---|---|---|---|---|
| 3 h  | 0,18 m | 0,39 m | 0,13 m | +60% |
| 6 h  | 0,26 m | 0,60 m | 0,24 m | +72% |
| 12 h | **0,48 m** | 1,00 m | 0,52 m | +73% |
| 18 h | 0,80 m | 2,15 m | 1,00 m | +69% |
| 24 h | 1,15 m | 3,42 m | 1,52 m | +64% |

Por isso a interface destaca a previsão de **12 h** e mostra a de 24 h sempre
acompanhada da sua banda de erro, nunca como número isolado. O gráfico desenha a
faixa de incerteza, que cresce com o horizonte.

### Validação: leave-one-event-out, não erro de treino

A v2 reportava NSE = 0,87 medido **no próprio conjunto de treino**, o que não diz
nada sobre prever uma cheia nova. A v3 valida deixando cada evento de cheia
inteiro fora do treino e medindo dentro dele — descartando também as amostras
cujo alvo cai no evento, senão o alvo vaza para o treino.

Medido assim, o modelo v2 **errava o pico da cheia de set/2026 em −5,25 m**, ou
seja, subestimava a cheia em mais de cinco metros. Esse erro era invisível na
métrica que ele publicava.

### O que mudou da v2 para a v3, e por quê

1. **Filtro de leitura espúria.** O SGB entregou 53 leituras de Encantado entre
   39 m e 50 m em 18-19/07/2026 — onde a cota de inundação é 12 m. Elas
   envenenavam o treino e a calibração de defasagens (o lag Muçum→Encantado saía
   em 16,25 h, contra ~1 h nos outros eventos). Filtrá-las derrubou o erro no
   evento de jul/2026 de 2,52 m para 1,03 m. Tetos em `cota_maxima_fisica`.

2. **Chuva da sub-bacia de resposta rápida.** Em vez da média das 7 estações de
   cabeceira, o modelo usa só os postos cuja chuva chega a Lajeado dentro do
   horizonte de previsão (Santa Tereza, Linha Colombo, Barra do Fão). O erro no
   pico em 24 h caiu de 2,93 m para 1,52 m. Motivo: Vacaria e Ibiraiaras ficam na
   cabeceira alta, com lag maior que o horizonte útil, e em set/2026 choveu ~0 mm
   lá enquanto choveu 140-180 mm nos postos de baixo — a média entre sete
   estações dilui justamente a chuva que gerou a cheia.

3. **Janelas de chuva curtas** (6 h/12 h) em lugar de 72 h, que diluía o evento
   em curso.

4. **Curva horária prevista, não interpolada.** A v2 traçava uma reta da cota
   atual até +24 h, então não mostrava pico intermediário — numa cheia a cota
   pode subir até 12 h e já estar baixando em 24 h, e a reta escondia isso.

5. **Guarda de plausibilidade auto-calibrada.** Cada horizonte guarda a faixa de
   variação já observada na série; previsão fora dela (com 30% de folga) é
   descartada como extrapolação. Substitui o múltiplo fixo de RMSE.

### Defasagens entre estações (recalibradas)

Medidas por correlação cruzada da **taxa horária** de cada estação contra
Lajeado, evento a evento — correlacionar a taxa, e não o nível, evita que o
nível de base comum às duas réguas domine e achate o pico da correlação. Adotado
o valor mediano entre eventos, descartando evento cuja correlação ficou abaixo
de 0,5:

| Estação | Lag até Lajeado | Antes | r |
|---|---|---|---|
| Encantado | 4 h | 3 h | 0,87-0,91 |
| Muçum | 4,75 h | 4,5 h | 0,81-0,85 |
| Santa Tereza | 6 h | 5,5 h | 0,73-0,77 |
| Linha José Júlio | 5,75 h | 6 h | 0,61-0,76 |
| Linha Colombo | 10,5 h | 11,25 h | 0,50-0,79 |
| Barra do Fão | 8,5 h | 13,25 h | 0,45-0,75 |

Barra do Fão estava superestimada em 1,6×.

### Razão chuva/cota corrigida pela concentração espacial

A razão média simples é um preditor ruim: nos quatro eventos ela varia de 9,1 a
23,5 mm/m (desvio 6,5), o que torna o intervalo de confiança quase inútil. A
variação **não é ruído** — ela acompanha a distribuição espacial da chuva, medida
pelo coeficiente de variação (CV = desvio/média) entre os postos de cabeceira:

| Evento | CV | Razão | Padrão |
|---|---|---|---|
| jul/20 | 0,22 | 21,0 | frontal, espalhada por toda a bacia |
| jul/28 | 0,27 | 23,5 | idem |
| set/28 | 0,90 | 14,4 | concentrada na cabeceira média/baixa |
| set/21 | 1,09 | 9,1 | idem, mais concentrada |

Quanto mais concentrada a chuva, **menos milímetros médios** bastam por metro de
cheia. O motivo é de medição, não de física: a média aritmética divide a chuva
por toda a cabeceira, inclusive onde não choveu, e subestima a lâmina que caiu
sobre a área que gerou o escoamento.

`Projector` ajusta `razão = A·exp(B·CV)` sobre os eventos fechados (recalibrado a
cada evento novo, R² ≈ 0,90), o que reduz o desvio residual de 6,5 para
2,5 mm/m — o intervalo de confiança encolhe cerca de 60%. O efeito prático: um
cenário concentrado de 77 mm médios projeta cota **maior** (26,2 m) que um
espalhado de 100 mm (23,3 m), que é exatamente o que ocorreu em set/2026, quando
49,5 mm médios levaram a 24,46 m enquanto 117,3 mm levaram a 23,99 m.

**Ressalva:** quatro eventos é pouco para uma lei empírica. O efeito é
consistente e tem explicação clara, mas os coeficientes vão mudar conforme a
série cresce. A alternativa correta a médio prazo é ponderar a chuva por área de
contribuição (polígonos de Thiessen), que dispensa a correção.

### O que ainda não está resolvido

- **Sem previsão de chuva (QPF).** É o que trava o horizonte em ~12 h. Acoplar
  previsão meteorológica é o único caminho para 24-48 h com erro aceitável.
- **Amostra pequena:** quatro eventos de cheia. Toda calibração aqui é
  provisória.
- **Muskingum-Cunge** (celeridade variável com o nível) segue não implementado.
  Os dados mostram lag menor nos eventos mais intensos, mas sem relação limpa o
  bastante para calibrar com quatro casos.
- **Sem curva-chave.** Testei linearizar o roteamento com pseudo-vazão
  `(h−h₀)^β` para β = 1,0/1,4/1,67/2,0 (Manning): **β = 1,0 venceu em todos os
  eventos** (r = 0,982-0,997), então a relação nível-nível entre Encantado e
  Lajeado já é praticamente linear na faixa observada e não vale complicar.
- **O fator AMC não se sustentou.** Testei o API (*Antecedent Precipitation
  Index*, k = 0,9/dia) contra a eficiência chuva→cota e **não há relação
  monotônica**: jul/20 teve API de 9,9 mm e razão alta (21,0), jul/28 teve API de
  105 mm e razão igualmente alta (23,5). A condição antecedente não é o driver
  dominante nesta bacia — a distribuição espacial da chuva é.

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

## Referências bibliográficas

A calibração da projeção de cota (defasagem entre estações, recessão de cheia,
razão chuva/cota) se baseia em:

- **Tallaksen, L.M. (1995).** "A review of baseflow recession analysis."
  *Journal of Hydrology*, v.165, p.349-370. — recessão de cheia multi-segmento
  (decaimento exponencial bifásico), base do modelo de recessão usado na
  previsão de 24h em Lajeado.
- **Collischonn, W.; Tucci, C.E.M. (2001).** "Simulação Hidrológica de Grandes
  Bacias." *Revista Brasileira de Recursos Hídricos (RBRH)*, v.6, n.1, p.95-118.
  — trabalho fundacional do modelo MGB-IPH, referência para a razão
  chuva/excesso-de-cota calibrada por eventos históricos.
- **Chow, V.T. (1988).** *Applied Hydrology.* McGraw-Hill. — hidrograma
  unitário e fundamentos de roteamento de cheia.
- **Linsley, R.K.; Kohler, M.A.; Paulhus, J.L.H.** *Hydrology for Engineers.*
  McGraw-Hill. — curva de recessão e análise de hidrograma, referência
  clássica complementar a Tallaksen (1995).
- **Método de Muskingum-Cunge** (roteamento de cheia baseado em celeridade de
  onda cinemática, parâmetros K e X) — considerado como possível terceira via
  de calibração, complementar ao modelo estatístico (MLR) e ao heurístico
  atuais; ainda não implementado.

Defasagens entre estações upstream e Lajeado foram recalibradas por correlação
cruzada (Pearson) da taxa horária, evento a evento — ver
[Previsão de cota em Lajeado](#previsão-de-cota-em-lajeado--como-funciona-e-até-onde-vale).

- **Kohler, M.A.; Linsley, R.K. (1951).** *Predicting the runoff from storm
  rainfall.* US Weather Bureau Research Paper 34. — origem do API (*Antecedent
  Precipitation Index*), testado aqui como proxy de umidade antecedente: nos 4
  eventos observados **não** apresentou relação monotônica com a eficiência
  chuva→cota, e por isso não foi adotado.
- **Polígonos de Thiessen** (chuva média ponderada por área de contribuição,
  ver Chow 1988) — caminho indicado para substituir a correção empírica por
  concentração espacial hoje aplicada na razão chuva/cota.

### Previsibilidade e incerteza em previsão de cheias

Literatura de referência dos hidrólogos mais citados na área de previsão e
quantificação de incerteza em hidrologia, consultada como base conceitual
(nem tudo aqui já está implementado no projeto — ver observações):

- **Beven, K.; Binley, A. (1992).** "The future of distributed models: model
  calibration and uncertainty prediction." *Hydrological Processes*, 6(3),
  279-298. — introduz o GLUE; formaliza a equifinalidade (vários conjuntos de
  parâmetros reproduzem igualmente bem a vazão observada), por isso previsão
  confiável exige quantificar incerteza, não só ajustar "o melhor" parâmetro.
- **Gupta, H.V.; Kling, H.; Yilmaz, K.K.; Martinez, G.F. (2009).**
  "Decomposition of the mean squared error and NSE performance criteria."
  *Journal of Hydrology*, 377(1-2), 80-91. — origina o KGE (Kling-Gupta
  Efficiency), hoje padrão pra avaliar modelo hidrológico, mais robusto que o
  NSE isolado (única métrica usada hoje em `scripts/train_mlr.php`).
- **Sivapalan, M.; Takeuchi, K.; Franks, S.W.; et al. (2003).** "IAHS Decade
  on Predictions in Ungauged Basins (PUB), 2003–2012." *Hydrological
  Sciences Journal*, 48(6), 857-880. — lança a iniciativa PUB da IAHS,
  relevante pra qualquer tributário sem série longa de dados.
- **Hrachowitz, M.; Savenije, H.H.G.; Blöschl, G.; et al. (2013).** "A decade
  of Predictions in Ungauged Basins (PUB)—a review." *Hydrological Sciences
  Journal*, 58(6), 1198-1255. — revisão de fechamento da PUB: previsão de
  cheia é sistematicamente menos precisa em bacias pequenas, contexto pras
  estações menores da bacia do Taquari.
- **Todini, E. (2008).** "A model conditional processor to assess predictive
  uncertainty in flood forecasting." *International Journal of River Basin
  Management*, 6(2), 123-137. — referência central em previsão probabilística
  de cheia em tempo real (testado no rio Po, Itália).
- **Todini, E. (2017).** "Flood Forecasting and Decision Making in the new
  Millennium. Where are We?" *Water Resources Management*, 31, 3111-3129. —
  panorama do estado da arte em previsão de cheia.
- **Duan, Q.; Sorooshian, S.; Gupta, V. (1992).** "Effective and efficient
  global optimization for conceptual rainfall-runoff models." *Water
  Resources Research*, 28(4), 1015-1031. — introduz o SCE-UA, método de
  calibração automática padrão pra modelos chuva-vazão; relevante pra
  recalibração futura do MLR/heurístico do projeto.
- **Vrugt, J.A.; ter Braak, C.J.F.; Gupta, H.V.; Robinson, B.A. (2009).**
  "Equifinality of formal (DREAM) and informal (GLUE) Bayesian approaches in
  hydrologic modeling?" *Stochastic Environmental Research and Risk
  Assessment*, 23(7), 1011-1026. — compara calibração bayesiana formal
  (DREAM) com GLUE (Beven, acima).
- **Fan, F.M.; Schwanenberg, D.; Collischonn, W.; Weerts, A. (2015).**
  "Verification of inflow into hydropower reservoirs using ensemble
  forecasts of the TIGGE database for large scale basins in Brazil."
  *Journal of Hydrology: Regional Studies*, 4(B), 196-227. — extensão da
  linhagem Collischonn/Tucci (já citada acima) pra previsão por conjunto em
  bacias brasileiras, o parente metodológico mais próximo do problema do
  Taquari.

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
