# Bússola Master: relatório da manhã

Noite de 12 para 13 de outubro de 2026. O pedido está em `documents/NOITE-BUSSOLA-MASTER.md`; o desenho em `documents/bussola-master/DESENHO.md`; as perguntas em `documents/bussola-master/NOITE-PERGUNTAS.md`; os relatórios das fontes em `documents/bussola-master/fontes/`.

Estado: **o R0 e o R1 ficaram feitos, cada um com o seu commit na `main` local; o R2, o R3 e o R4 não foram construídos.** O agente de construção do R1 parou a meio da noite de 12 para 13 de outubro por limite de utilização da conta ("session limit"); o trabalho que deixou foi revisto, testado e gravado a 14 de outubro, com a suite completa, e a primeira recolha real foi feita nesse momento. As especificações do R2 e do R3, prontas para um agente de construção, ficaram em `documents/bussola-master/especificacoes/`; as ferramentas da verificação no ecrã (R4) em `documents/bussola-master/ferramentas/`. Pelo pedido ("se uma parte não ficar verde, NÃO avança: para e documenta"), e por falta de orçamento de utilização para continuar com segurança, a noite fica aqui.

Garantias:
- Sem push nem deploy; tudo na `main` local.
- Nenhuma chamada a serviços externos além da exceção desta noite (leitura de feeds e dados públicos, só leitura, sem autenticação, no máximo 4 pedidos por fonte e por hora, termos lidos antes das amostras); a IA simulada nos testes e no ecrã.
- `SCHEDULE_EXTERNAL_JOBS` desligado em dev; as tarefas novas do Radar são externas.

Trabalho por gravar de outra pessoa: nenhum no início da noite (a árvore só tinha os ficheiros do R0 por gravar).

## 1. O que ficou feito em cada parte

| Parte | Commit | O que ficou |
|---|---|---|
| Pedido | `196a18c` | O pedido gravado em `documents/NOITE-BUSSOLA-MASTER.md` |
| R0 Desenho | `f059d41` | `DESENHO.md` (três camadas, fontes com termos, amostras, modelo de dados, filtro, relevância e custos, calendário, imobiliário, riscos, decisões por omissão), `NOITE-PERGUNTAS.md` (18 perguntas), os seis relatórios das fontes e o registo dos pedidos HTTP em `fontes/`, e o teste de caracterização da Bússola do stand (`StandCompassGoldenTest`, 8 respostas gravadas) |
| R1 Recolha e filtro | `e68b441` | `config/radar.php` (6 fontes, 5 ligadas, o IPMA desligado), a migração `2027_01_02_100000_create_radar_tables` (radar_runs, radar_items, radar_topics), os leitores por fonte (`App\Services\Radar\Sources`), o coletor com o teto de 4 pedidos por fonte e por hora, o filtro de segurança (`Filter\Lexicon`, `RadarSafetyFilter`, `PersonNameDetector`), as tendências (`RadarTopics`), o comando `radar:collect`, os trabalhos `CollectRadarFeedsJob` (tarefa externa de 15 em 15 minutos) e `PurgeRadarItemsJob` (30 dias, 03:20), o painel do root (`GET /admin/radar`, `GET /admin/radar/items`; página Administração › Radar), 80 testes PHP com fixtures anonimizadas e 2 testes Jest |
| R2 Relevância, encaixe e cartão | não feito | especificação em `especificacoes/r2-spec.md` |
| R3 Imobiliário e calendário | não feito | especificação em `especificacoes/r3-spec.md` (com a receita ficheiro a ficheiro do fornecedor `imobiliario`) |
| R4 Verificação no ecrã | não feito | `ferramentas/demo-criar.php`, `demo-limpar.php`, `demo-confirmar.sh` prontos; sem capturas |

### 1.0 R0: o que as leituras das fontes decidiram

- Entram esta noite: Google Trends (permitido com atribuição), ECO, Público e Observador (termos omissos; feed público anunciado pelo próprio jornal; autorização escrita a pedir antes do lançamento comercial), o RSS de imprensa do BCE (permitido com citação e a nota "disponível gratuitamente em ecb.europa.eu"), o INE para a referência do imobiliário (CC BY 4.0, uso comercial permitido, frase de atribuição obrigatória) e o calendário oficial do BCE (lido diretamente).
- Ficam de fora: o IPMA (a cláusula de 2017 restringe a fins não lucrativos; no código, desligado, até confirmação escrita), a Euribor (a EMMI só permite uso não comercial sem licença; as médias mensais do BPstat e do BCE são dados de terceiros), RTP, JN, DN, Jornal de Negócios e TSF (termos proíbem), Expresso, SIC Notícias e Renascença (bloqueiam leitores automáticos), lisboa.pt (403 Cloudflare) e as datas sem fonte oficial.
- O pormenor de cada fonte (URL, formato, frequência, termos citados, o que se guarda) está no `DESENHO.md`, secção 2; os relatórios completos em `fontes/`.

## 2. Os testes e as capturas

### 2.1 A suite

- Base no início da noite (12 de outubro, com o teste de caracterização do stand já na árvore): 1885 testes, 24 falhas conhecidas (as mesmas 24 de sempre: 10 `CarControllerTest`, 2 `AuthTest`, 9 `StockPromotionControllerTest`, 3 `Tests\Unit`), nenhuma nova.
- R1: os 80 testes de `tests/Feature/Radar` (comando com simulação e `--execute`, o 5.º pedido na mesma hora recusado e `--force`, uma fonte em falha não pára as outras, cada regra do filtro com um título que dispara e um que não, nomes de pessoas em evento público e em vida pessoal, marcas protegidas e desporto como marcas, as tendências de jornais com dois jornais em 6 horas, a limpeza aos 30 dias, o painel só para o root, nenhum dado pessoal), mais `ScheduledExternalJobsTest`, `AccessSnapshotTest`, `AccessCatalogTest` e `TenancyArchitectureTest`, verdes. Frontend: TypeScript e ESLint limpos; `AdminRadarPage.test.tsx` 2 testes verdes.
- A suite completa depois do R1 (14 de outubro): 1929 testes (de 1885), as mesmas 24 falhas conhecidas, nenhuma nova.

### 2.2 As capturas

(a preencher no R4)

## 3. A amostra real da noite

### 3.1 As leituras de avaliação (termos e amostras, 19:14 a 19:28)

| Fonte | Pedidos | Itens lidos | O que o filtro tiraria | Nomes de pessoas |
|---|---|---|---|---|
| Google Trends PT | 2 a trends.google.com (robots.txt e RSS), 1 a policies.google.com, 2 a support.google.com | 10 tendências, 26 notícias | 6 de 10 com o desporto desligado (3 por segurança: política e conflito, tragédia, vida pessoal; 3 por desporto) | 3 de 10 |
| Público | 1 (termos) + 1 ao feed (FeedBurner) | 10 | 5 de 10 (política 4, crime 1) | 3, em evento público |
| Observador | 2 (termos e feed) | 51 | 33 de 51 (política 22, desporto 6, tragédia e conflito 5) | 18, em política, desporto ou conflito |
| ECO | 2 (termos e feed) | 22 | 7 de 22 (política 7) | 3 (governantes) |
| RTP, JN, DN, SIC, Expresso, Jornal de Negócios, TSF, Renascença | 1 a 2 por domínio (só termos) | sem amostra (termos proíbem ou não verificáveis) | | |
| BCE | 4 a www.ecb.europa.eu (aviso legal com redirecionamento, RSS, calendário), 3 a data-api.ecb.europa.eu | 15 itens do RSS (nenhuma decisão na amostra); calendário com 10 reuniões até dezembro de 2027 | | 0 |
| EMMI, BPstat | 3 e 3 (só termos e metadados) | sem amostra de taxas (fora) | | |
| IPMA | 4 a api.ipma.pt (termos, avisos, previsão, locais) | 200 linhas de avisos, todas "green" | nada a mostrar | 0 |
| INE | 4 a www.ine.pt (termos, API, metainformação, dados) | 2 793 registos do 1.º trimestre de 2026 (350 de 584 freguesias com valor) | | 0 |
| AT, DRE, DGAEP, Porto, Lisboa | 3, 3, 3, 3, 3 | Agenda Fiscal, PDF do Código do Trabalho, notícia do São João; Lisboa 403 | | 0 |

O registo completo (hora, URL, código) está em `fontes/pedidos-http.md`. Exemplos de tendências mantidas, sem nomes de pessoas: um investimento industrial em Gaia ("thyssenkrupp", 100+), a suspensão da circulação na Linha do Douro para obras ("linha do douro", 100+), a escala de um navio de expedição no Funchal ("navio", 500+). Exemplos retirados: declarações sobre um cessar-fogo ("rússia", 1000+, política e conflito), uma explosão com uma vítima adolescente ("israel", 500+, tragédia), revelações sobre a vida de um concorrente de telerrealidade (200+, vida pessoal).

### 3.2 As recolhas da noite (depois do R1)

Uma recolha real com `radar:collect --execute` (14 de outubro, a seguir ao commit do R1; 1 pedido por fonte, 2 no Google Trends por causa do `robots.txt`; todos HTTP 200):

| Fonte | Lidos | Passaram | Retirados | Motivos (um item pode ter mais de um) |
|---|---|---|---|---|
| Google Trends | 10 | 6 | 4 | política 2, morte 1, crime 1, vida pessoal 1 |
| ECO | 22 | 15 | 7 | política 3, crime 2, vida pessoal 2, conflito 1 |
| Público | 10 | 5 | 5 | vida pessoal 2, crime 1, conflito 1, política 1 |
| Observador | 51 | 19 | 32 | política 12, crime 9, conflito 7, vida pessoal 5, religião 3, tragédia 2, doença 2, acidente 1 |
| BCE | 15 fora do âmbito (nenhum comunicado de decisão) | | | |
| IPMA | desligada | | | |
| **Total** | **93** | **45** | **48** | |

Tendências formadas: 10 do Google Trends (6 passaram, 4 bloqueadas) e 1 de jornais (dois jornais com o mesmo assunto em 6 horas). 20 itens levaram marcas (desporto, nome de pessoa em evento público, marca protegida). Nada do que foi guardado tem corpo de artigo: só título, ligação, fonte, hora, volume e categorias. Os títulos ficam em dev na tabela `radar_items` (apagados aos 30 dias) e não se transcrevem aqui.

## 4. As decisões tomadas por omissão, para confirmar

As 17 decisões da secção 10 do `DESENHO.md` (rotas próprias do Radar; o módulo `radar` como interruptor; as cinco colunas opcionais de `editorial_posts` para o "Reagir"; dois jornais em 6 horas; as janelas; a relevância por ramo em lotes com o custo fora de Modelos de IA; a pontuação do encaixe; o calendário em código; o imobiliário com o módulo `imoveis`, o preset `real_estate` e a área pelo padrão dos Ficheiros; os limiares 90/45 dias e 10%; a ligação freguesia e INE pelo nome; os contadores manuais; o aviso da classe energética sem bloquear; as fontes desta noite; o calendário com quatro entradas; o indicador 0012234 do INE; o "Vem aí" a 30 dias), mais as do R1:
18. O coletor conta no teto horário também as corridas falhadas e as respostas 304; o Google Trends gasta 2 pedidos por recolha quando relê o `robots.txt` (uma vez por dia).
19. As categorias dos itens dos jornais ("Política", "Desporto", "Religião", "Justiça") contam como motivo do filtro.
20. Um título de jornal sem par noutro jornal fica como item sem tendência (visível no painel do root).
21. O IPMA está no código, desligado por configuração (`config/radar.php`), com o motivo visível no comando e no painel.
22. A limpeza aos 30 dias corre às 03:20 (Lisboa) como tarefa interna; a recolha é externa (desligada em dev).

## 5. A diferença na fotografia da ACL

- R1: **nenhuma diferença.** As duas rotas novas são de administração (`GET /admin/radar`, `GET /admin/radar/items`, no grupo `ensure_super_admin`), fora do varrimento `/companies/{id}`; o `AccessSnapshotTest` passou sem reescrita e as fotografias em `tests/Fixtures/acl/fotografia/` não mudaram.
- Ponto de partida para o R2 e o R3: 399 rotas; `sem_modulos_admin` com 253 rotas proibidas, dos quais 4 vêm do commit `f869922` de outra pessoa (F2d), que a tabela da secção 7 do `documents/acl/F3-DECISOES.md` ainda diz 249. A correção da tabela e as secções 6e (Radar) e 6f (imobiliário) ficaram nas especificações do R2 e do R3.

## 6. As perguntas pendentes

1. Empresa sem ramo na Linha Editorial: sem ramo não há sinais do Radar.
2. O Radar sozinho na página da Bússola quando a Bússola do ramo está desligada: sim.
3. O interruptor do Radar é um módulo (`radar`), fora dos presets.
4. As definições do Radar (desporto, clubes, temas silenciados) numa janela aberta do próprio cartão.
5. "Reagir" com o mês fechado: o servidor recusa e o ecrã liga à Linha Editorial.
6. O custo da relevância fica em Administração › Radar, não em Modelos de IA.
7. O ramo "Imobiliário" como folha de "Universal"; o módulo `imoveis` com o preset `real_estate`; a área `imoveis` pelo padrão dos Ficheiros.
8. O interesse dos imóveis: dois contadores manuais com a data.
9. Os limiares: parado a 90 dias (venda) e 45 (arrendamento); faixa de 10% face ao INE.
10. Freguesias e INE ligadas pelo nome normalizado (a tabela das freguesias não tem o DICOFRE).
11. A jogada do BCE só no imobiliário, no dia da decisão, com a ligação, sem explicar.
12. As fontes ligadas: Google Trends, ECO, Público, Observador e o RSS do BCE; cerca de 50 tendências por dia chegam à relevância.
13. O IPMA: quer que se envie o email a pedir a confirmação do uso comercial? Até lá, desligado.
14. Os jornais: pedir autorização escrita aos três antes do lançamento (e à RTP e à Medialivre?).
15. A Euribor fica de fora (EMMI, BPstat); a jogada do BCE não mostra taxas.
16. A referência do INE só por freguesia (o INE só a publica em 58 municípios); permitir o município como comparação de confiança baixa?
17. O IRS no calendário dos três ramos.
18. Santo António de Lisboa e as publicações do INE fora do calendário até confirmação.

## 7. O plano da calibração com IA real (para fazer acompanhados, depois do R2)

- **Amostra:** 3 dias de recolhas reais (cerca de 300 tendências do Trends e 30 a 60 de jornais depois do filtro), avaliadas pela `radar_relevancia` para os quatro ramos (restauração, automóvel, imobiliário, universal) e, para 3 empresas de demonstração, o "porque lhe interessa" (`radar_porque`) dos 3 sinais por dia.
- **Custo estimado:** relevância, cerca de 60 pedidos em lotes de 20 com o Haiku 5.5 (0,10 e 0,50 USD por milhão de tokens): menos de 0,10 USD; "porque lhe interessa", 27 pedidos com o Opus 5.5 (4 e 20 USD por milhão): cerca de 0,80 USD; com as repetições do verificador do português, menos de 2 USD no total.
- **O que medir:** a fração de tendências relevantes por ramo (esperada: 10% a 30%); a concordância entre dois modelos na relevância numa amostra de 50 (Haiku contra Opus); as frases do "porque lhe interessa" rejeitadas por causa ou por português (esperado: menos de 10%); o tempo por lote; o custo real por dia por empresa (teto 0,08 USD); os falsos negativos do filtro (títulos sensíveis que passaram) e os falsos positivos ("Porto" como clube e como cidade) numa leitura humana de 100 itens do painel do root.

## 8. A lista de deploy (só do que ficou feito: o R1)

- **Código:** os commits do R0 e do R1 na `main` local (sem push).
- **Migração (aditiva):** `2027_01_02_100000_create_radar_tables` (radar_runs, radar_items, radar_topics). Já correu em dev.
- **Configuração:** `config/radar.php` (as fontes e os interruptores; o IPMA desligado); `config/scheduler.php` com a tarefa externa `radar-collect-feeds` em `external_job_names`. Nenhuma variável nova no `.env`.
- **Tarefas agendadas:** `radar-collect-feeds` (externa, de 15 em 15 minutos, só corre com `SCHEDULE_EXTERNAL_JOBS=true`) e `radar-purge-items` (interna, 03:20 Lisboa).
- **Comandos:** `php artisan radar:collect` (simulação), `radar:collect --execute`, `radar:collect {fonte} --execute --force`.
- **Build do frontend:** a página Administração › Radar (só root).
- **Interruptores:** nenhum por empresa ainda (o módulo `radar` é do R2). O Radar não chega a nenhum cliente com o R1: só recolhe e mostra ao root.
- **Antes de ligar em produção:** confirmar os termos (perguntas 13 e 14), e ver o painel do root durante uma semana para afinar o léxico do filtro.
- **Como voltar atrás:** desligar a tarefa externa (ou pôr `enabled => false` nas fontes); as tabelas são novas e podem ficar vazias.

## 9. O que falta e por onde continuar

1. **R2** (`especificacoes/r2-spec.md`): as funções de IA simuladas, a relevância por ramo, o encaixe com o limite de 3 por dia, as definições, o módulo `radar`, o bloco na Bússola, o painel "Agora", "Reagir", "Ignorar", "Não mostrar temas como este", a fotografia da ACL e a secção 6e do F3-DECISOES.
2. **R3** (`especificacoes/r3-spec.md`): o ramo e o modelo "Imobiliário", o módulo `imoveis`, a carteira, a referência do INE (indicador 0012234), as jogadas, o aviso da classe energética, o calendário com as quatro entradas, a secção 6f.
3. **R4** (`ferramentas/`): as empresas de demonstração, as pessoas temporárias, as capturas (claro e escuro, computador e telemóvel) e a limpeza. Atenção à lição da noite anterior: a tarefa interna `bussola-stand-nightly` chama a IA real em dev para a empresa 4 às 05:20; durante a verificação, esvaziar as chaves ou usar o `AI_FAKE` do R2.
4. As leituras pendentes nas fontes (uma por janela de pedidos): a página `ine_api_v2` (condições da API do INE), o ICS do INE (a data da próxima publicação das estatísticas de preços da habitação) e o caminho público do RSS de porto.pt.
