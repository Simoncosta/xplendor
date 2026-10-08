# PingWin F1 a F3 em produção: lista de deploy e sessão acompanhada

Escrito a 8 de outubro de 2026, depois da F3. Este documento tem duas partes:
- a **lista de deploy** desde o último deploy (secção 1);
- o **guia da primeira leitura real em produção**, na Yuko, numa sessão acompanhada (secção 2);
- a alternativa escolhida para a Yuko: **ligar o interruptor no deploy** e completar o histórico na manhã seguinte (secção 3).

O guia segue o da sessão de dev (`documents/F1-RELATORIO-MANHA.md` §5 e §6), já com as correções dessa sessão. Os desenhos estão em `documents/PINGWIN-F1-DESENHO.md` (F1 e F2) e `documents/PINGWIN-F3-DESENHO.md` (F3).

Regras que continuam a valer:
- No PingWin, só leituras autorizadas, à noite ou fora de serviço, em série e com espaçamento. Nunca escrever no PingWin nem no CoverManager.
- Nunca usar a chamada `/service/report/<ID>/params`, porque devolve clientes e funcionários com nomes.
- Nenhum dado pessoal: só agregados.
- **O interruptor `companies.pingwin_item_sales_enabled` fica DESLIGADO em todas as empresas depois do deploy.** Liga-se empresa a empresa: na Yuko, na noite do deploy (secção 3); nas outras, só depois de validadas.

## 1. Lista de deploy

### 1.1 Desde quando

- **Não há registo do último deploy** no repositório: não há tags de deploy nem documento com a data. Os commits com "deploy" no nome vão até `47979eb` (5 de outubro); `72f8c2a` (7 de outubro, 13:50) preparou o deploy seguinte, mas não diz se correu.
- **O que é certo:** o `deploy.sh` faz `git pull origin main`, e o `origin/main` está em `e6c0596` (7 de outubro, 22:06). Os commits locais seguintes (de `837f4c6` ao commit deste documento) **não estão no origin**, por isso não estão em produção. É preciso fazer push antes do deploy.
- **Confirmar no servidor antes do deploy** (só leituras):
  ```
  cd /home/xplendor
  git log -1 --oneline
  git status --short
  docker exec xplendor-php php artisan migrate:status | grep -i pending
  ```
  O `git log` mostra o commit em produção. Se não for `e6c0596`, entram também no deploy os commits entre esse e `e6c0596` (as migrações deles correm igualmente com o `migrate --force`).

### 1.2 Commits que entram (só os que não estão no origin)

| Commit | Conteúdo |
|---|---|
| `837f4c6` | CoverManager: token mascarado antes de qualquer registo de erro |
| `8c1bfb6`, `01be303` | F0: documentação dos relatórios (sem código de produção) |
| `021a04e` | Desenho da F1 |
| `44090cb`, `5e74108`, `12c4921` | F1-1 a F1-3: vendas por artigo, histórico, início das lojas, catálogo completo, qualidade dos dados, categorias das famílias |
| `573eba3` | Relatório da manhã (documentos) |
| `4504ef6` | Correções da sessão real de dev |
| `9223545` | Registos fora do git (`docker/scraper/scraper.log`) |
| `1b13120` | F2: vendas por hora, agregados das reservas, mapa da semana |
| `6314808` | F3: "O que publicar e quando" |
| `8e497da` | Guia da sessão em produção e lista de deploy |
| (seguinte) | Mapa dos códigos de estado do CoverManager e reservas "por classificar" |

### 1.3 O pull antes do down, e o registo do scraper

O commit `9223545` retirou `docker/scraper/scraper.log` do git. Em produção, o scraper corre com `WORKDIR /app`, montado em `./docker/scraper`, e escreve nesse ficheiro. O ficheiro tem por isso alterações locais no servidor, e um `git pull` simples recusa-se a apagá-lo ("Your local changes … would be overwritten").

**O `deploy.sh` novo** trata disto:
- faz o `git pull --ff-only` **antes** do `docker compose down`; se o pull falhar, pára sem desligar nada;
- antes do pull, se o registo do scraper ainda estiver seguido pelo git e tiver alterações, guarda uma cópia, repõe a versão do git, faz o pull e devolve a cópia (o ficheiro passa a ficar fora do git);
- se o pull falhar, devolve o registo como estava;
- o `down` vem logo a seguir ao pull, porque o código está montado nos contentores e não pode ficar a correr com a base de dados antiga mais do que o necessário.
- Testado numa réplica local com três casos: o servidor com o registo alterado, o pull que falha e o deploy seguinte já atualizado.

**Só no primeiro deploy:** o servidor ainda tem o `deploy.sh` antigo, que faz o `down` antes do pull. Por isso, desta vez, o pull faz-se à mão antes de correr o script:
```
cd /home/xplendor
cp docker/scraper/scraper.log /home/scraper.log.antes-do-deploy
git checkout -- docker/scraper/scraper.log
git pull --ff-only origin main
cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log
git status --short          # não deve aparecer nada
bash deploy-scripts/deploy.sh   # ou como o deploy é corrido habitualmente
```
- Se o `git pull` falhar, nada foi desligado: devolver o registo (`cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log`) e ver o motivo antes de continuar.
- O `deploy.sh` corrido a seguir já é o novo; o pull dele não traz nada.
- Nos deploys seguintes, basta o `deploy.sh`.

### 1.4 Migrações (correm no `deploy.sh`, pela ordem)

| Migração | O que faz |
|---|---|
| `2026_12_04_100000_create_pingwin_item_sales` | Tabelas `pingwin_item_sales_daily` e `pingwin_item_sales_days`; coluna `companies.pingwin_item_sales_enabled`, **desligada por omissão** |
| `2026_12_05_100000_add_item_history_to_pingwin_locations` | Colunas do histórico e do início em `pingwin_locations` (`opened_on`, `sales_first_month`, `sales_since`, `history_complete_at`, …) e em `pingwin_item_sales_days` |
| `2026_12_06_100000_create_restaurant_family_categories_and_quality` | Tabelas `restaurant_family_categories` e `restaurant_data_quality` |
| `2026_12_07_100000_create_f2_hourly_and_reservation_aggregates` | Tabelas `pingwin_hourly_sales`, `pingwin_hourly_sales_days`, `cm_reservation_hourly`, `cm_reservation_channel_daily`, `cm_reservation_leadtime_daily`, `cm_reservation_status_daily`; colunas `pingwin_locations.hourly_history_complete_at` e `cm_reservation_shift_summary.no_show_count` |
| `2026_12_08_100000_create_restaurant_signals` | Tabelas `restaurant_signals` e `restaurant_signal_actions`; colunas `restaurant_data_quality.signals_computed_at` e `signals_availability` |
| `2026_12_09_100000_add_unclassified_count_to_cm_reservation_shift_summary` | Coluna `cm_reservation_shift_summary.unclassified_count` (reservas com códigos fora do mapa) |

Todas criam tabelas novas ou acrescentam colunas com valor por omissão; nenhuma altera nem apaga dados existentes. Todas têm `down`, testado numa cópia da base de dev.

### 1.5 Variáveis de ambiente

Novas e **opcionais** (vazias, valem os IDs da Yuko, que são globais na cloud GrupoPIE):

| Variável | Valor por omissão | Relatório |
|---|---|---|
| `PINGWIN_REPORT_ID_ITEM_SALES` | `919525121217` | Vendas por artigo |
| `PINGWIN_REPORT_ID_ANNUAL` | `1093764095994` | Análise de vendas anual (início das lojas) |
| `PINGWIN_REPORT_ID_HOURLY_SALES` | `1023875499019` | Vendas por hora |

Não é preciso acrescentá-las ao `.env` de produção. Não há variáveis novas no scraper.

### 1.6 Jobs, agendamentos e comandos

- **Sem agendamentos novos.** O job das 05:00 (`ScheduledRestaurantSyncJob`) passa a fazer mais, **só nas empresas com o interruptor ligado**: vendas por artigo e por hora dos 7 dias anteriores, o histórico (até 10 pedidos por noite), a releitura dos dias marcados, a qualidade dos dados, ao domingo o catálogo completo e, no fim, os sinais da F3 (só leitura da base de dados). Com o interruptor desligado, faz o mesmo que hoje.
- **Job novo na fila:** `SyncItemSalesPeriodJob` (o "Sincronizar período" manual do cartão "Dados para o marketing", só com o interruptor). O `deploy.sh` já faz `queue:restart`.
- **Comandos novos** (manuais, para a sessão): `pingwin:item-sales-switch`, `pingwin:item-sales`, `pingwin:item-history`, `pingwin:catalog-complete`, `pingwin:hourly-sales`, `pingwin:hourly-history`, `covermanager:history`.

### 1.7 O que muda para os clientes com o interruptor desligado

- **Reservas do CoverManager:** passa a valer o mapa dos códigos de estado (`documents/PINGWIN-F1-DESENHO.md` §11). As faltas (código "-3") deixam de contar como anulações e passam a `no_show_count`; nos dias sincronizados depois do deploy, o número de anuladas pode descer um pouco. Um código fora do mapa (ou um estado vazio) deixa de contar como válida ou anulada e passa a "por classificar". Os códigos vistos na Yuko estão todos no mapa. Os dias anteriores ao deploy ficam como estavam.
- **Menu Marketing:** as empresas com o módulo PingWin passam a ver "O que publicar e quando". Com o interruptor desligado, a página diz só que a leitura está desligada. O mapa da semana e o resumo no dashboard não aparecem.
- **Integrações (root):** o cartão "Dados para o marketing" na integração PingWin, com o interruptor desligado.
- Nenhuma chamada nova ao PingWin, ao CoverManager nem à IA enquanto o interruptor estiver desligado.

### 1.8 Verificações depois do deploy

```
cd /home/xplendor
docker exec xplendor-php php artisan migrate:status | grep 2026_12_0
```
As seis migrações devem aparecer como "Ran".

**Interruptor desligado em todas as empresas** (deve devolver 0):
```
docker exec xplendor-php php artisan tinker --execute='echo App\Models\Company::where("pingwin_item_sales_enabled", true)->count(), PHP_EOL;'
```
Se devolver mais do que 0, desligar cada uma com `php artisan pingwin:item-sales-switch <ID> off` e voltar a verificar.

Outras verificações:
- `docker exec xplendor-php php artisan schedule:list | grep restaurant-daily-sync`: o job das 05:00 continua agendado.
- No dia seguinte, os registos do job das 05:00 sem erros novos (`storage/logs`, "[Scheduled Restaurant Sync] fim"), e o resumo ao dono só se houver falhas, como até aqui.
- No ecrã, com uma empresa de restauração: o dashboard de restauração abre como antes; "O que publicar e quando" mostra "A leitura das vendas por artigo está desligada nesta empresa".
- O registo do scraper no sítio e fora do git (`git check-ignore docker/scraper/scraper.log` devolve o caminho) e `git status --short` sem alterações.

## 2. Sessão acompanhada em produção (Yuko)

Numa hora fora de serviço do restaurante, com o utilizador presente, **um passo de cada vez**. Se algum resultado não bater com o esperado, parar e explicar, sem tentar corrigir. O botão "Pedir sugestões à IA" e o "Gerar ideias do mês" fazem chamadas reais à IA: só com pedido expresso.

Os comandos correm a partir de `/home/xplendor`, num contentor à parte do serviço `worker`, removido no fim. Assim, o reinício de hora a hora do `xplendor-worker` não os interrompe. Para encurtar:
```
cd /home/xplendor
art() { docker compose -f docker-compose.prod.yml run --rm --no-deps worker php artisan "$@"; }
```

### Passos da F1 (vendas por artigo)

**P0. Ambiente e ID da Yuko** (só leituras):
```
docker ps --format '{{.Names}}' | grep xplendor
art tinker --execute='App\Models\Company::where("fiscal_name", "like", "%Yuko%")->get(["id", "fiscal_name"])->each(fn ($c) => print($c->id." ".$c->fiscal_name.PHP_EOL));'
```
Nos passos seguintes, `<ID>` é o ID da Yuko em produção (em dev é 5). Depois:
```
art pingwin:item-sales-switch <ID>
art tinker --execute='echo App\Models\PingwinDailySale::where("company_id", <ID>)->max("business_date"), PHP_EOL;'
```
Deve dizer "vendas por artigo desligadas". O último dia do líquido diário deve ser ontem: em produção, o job das 05:00 já o mantém em dia, por isso não é preciso o passo 1 da sessão de dev.

**P1. Lojas e abertura:** em Restauração › Lojas, confirmar as duas lojas ativas e as datas de abertura (Baixa a 13/03/2026; Costa Cabral a 09/06/2026, os primeiros dias com vendas lidos na sessão de dev).

**P2. Simulação dos últimos 7 dias** (1 pedido, nada gravado):
```
art pingwin:item-sales <ID> --dry-run
```
Esperado: 14 linhas (2 lojas × 7 dias), todas "OK", sem "NÃO BATE" nem "VAZIO (protegido)"; as francesinhas à frente nas famílias; "Pedidos ao PingWin: 1".

**P3. Ligar o interruptor e gravar os 7 dias:**
```
art pingwin:item-sales-switch <ID> on
art pingwin:item-sales <ID>
```
Deve terminar com "Concluído: vendas por artigo gravadas."

**P4. Início de cada loja** (2 a 4 pedidos ao relatório anual, 20 s entre eles). Primeiro com os postos de venda vazios (vazio = todos, confirmado em dev):
```
art pingwin:item-history <ID> --detect-only
```
Esperado: Baixa em março de 2026 e Costa Cabral em junho de 2026. Se aparecer "Deteção do início não fiável", nada foi gravado: o root preenche os postos de venda no cartão "Dados para o marketing" da integração (nunca pela chamada que lista os parâmetros) e repete-se o passo.

**P5. Histórico completo** (cerca de 30 blocos de 7 dias, 20 s entre pedidos; 15 a 20 minutos; pode dividir, continua de onde parou):
```
art pingwin:item-history <ID> --calls=40
```
Esperado: "ok" nos dias com líquido diário, nenhum "mismatch" que fique, "empty_protected" só em dias fechados, os dias antes da primeira venda como "sem vendas", e no fim "Histórico completo."

**P6. Catálogo completo** (um pedido, 1 a 2 minutos):
```
art pingwin:catalog-complete <ID>
```
Esperado: "parou por: página vazia" (ou "total anunciado"), "fora do catálogo depois: 0", "cobertura 100%", "Catálogo completo."

**P7. Conferir no ecrã:** cartão "Dados para o marketing" da integração ("Leitura ligada", dias que batem, 0 marcados, catálogo a 100%, as duas lojas "Completo"). Em `/restauracao/categorias`, as categorias das famílias aparecem por confirmar: as confirmações feitas em dev não passam para produção. A equipa confirma-as quando puder; até lá, os sinais por categoria não aparecem.

### Passos da F2 (períodos fracos)

**F2-1. Simulação das vendas por hora** (1 pedido, nada gravado):
```
art pingwin:hourly-sales <ID> --dry-run
```
Esperado: 14 linhas "OK"; a soma das horas igual, ao cêntimo, ao líquido diário; o almoço e o jantar à frente no peso de cada hora.

**F2-2. Gravar os 7 dias:**
```
art pingwin:hourly-sales <ID>
```
Deve terminar com "Concluído: vendas por hora gravadas."

**F2-3. Histórico por hora** (cerca de 30 pedidos, 20 s entre eles; 15 a 20 minutos):
```
art pingwin:hourly-history <ID> --calls=40
```
Esperado: no fim, "Histórico por hora completo." e as duas lojas "completo".

**F2-4. Reservas dos últimos 90 dias** (a mesma leitura do job das 05:00, um pedido por loja e por dia, 5 s entre dias; 10 a 15 minutos):
```
art covermanager:history <ID> --days=90
```
Depois, confirmar o mapa dos códigos de estado (só contagens):
```
art tinker --execute='DB::table("cm_reservation_status_daily")->where("company_id", <ID>)->selectRaw("status_code, SUM(reservations_count) n")->groupBy("status_code")->orderByDesc("n")->get()->each(fn ($r) => print($r->status_code." ".$r->n.PHP_EOL));'
```
Esperado: sobretudo "3" e "5", alguns "-2" e "-3", e poucos "1", "-1", "4", "-11" e "2" (todos no mapa, `documents/PINGWIN-F1-DESENHO.md` §11). O comando mostra "por classificar: 0" e o cartão "Dados para o marketing" mostra "Reservas por classificar: Nenhuma". Se aparecer um código novo, registar qual e quantos, sem mexer no mapa durante a sessão.

**F2-5. Conferir no ecrã:** no painel de restauração, o cartão "Mapa da semana" por loja, com "Vendas" e "Pessoas"; as horas mais escuras no almoço e no jantar.

### Passos da F3 (O que publicar e quando)

**F3-1. Calcular os sinais** (só leitura da base de dados; nenhum pedido ao PingWin, ao CoverManager nem à IA):
```
art tinker --execute='$r = app(App\Services\Restaurant\RestaurantSignalService::class)->compute(<ID>); echo json_encode(["calculado" => $r["computed"], "sinais" => $r["signals"]]), PHP_EOL;'
```
Esperado: `"calculado":true` e algumas dezenas de sinais (em dev foram 27 sugestões, com menos histórico).

**F3-2. Conferir no ecrã**, em Marketing › "O que publicar e quando":
- a nota "Confirme as categorias das famílias" (as categorias estão por confirmar em produção);
- as sugestões da semana com confiança Alta ou Média, frases sem causas, e as datas para publicar;
- nos períodos fracos, a indicação dos dias especiais fora da média;
- "Os mais vendidos" sem modificadores nem artigos de entrega;
- "Reservas e canais" com a antecedência e os canais (com as reservas do passo F2-4);
- "Quando chegam os outros sinais" com as datas de cada loja;
- no dashboard de restauração, separador "Marketing", o cartão "O que publicar esta semana" e o contador.

**F3-3. "Criar publicação" e "Ignorar":** não usar na sessão sem pedido. "Criar publicação" cria uma ideia na Linha Editorial da Yuko, visível para a equipa (só com o mês aberto e com o módulo da Linha Editorial ativo). "Ignorar" esconde a sugestão durante 4 semanas, para todos.

**F3-4. Âncoras:** confirmar no catálogo de âncoras de produção "Passagem de Ano" e "Janeiro Verde" (em dev estão fixas a 15/12 e a 02/01). Os sinais usam as âncoras como estão.

### No fim da sessão

Decidir se o interruptor da Yuko fica ligado (o job das 05:00 continua sozinho, com o histórico, o catálogo ao domingo e os sinais) ou se se desliga:
```
art pingwin:item-sales-switch <ID> off
```
Nas outras empresas, continua desligado até cada uma ser validada.

## 3. Ligar no deploy (Yuko)

Em vez da sessão acompanhada da secção 2, o interruptor da Yuko liga-se na noite do deploy e o job das 05:00 faz a primeira leitura. Na manhã seguinte, antes das 11h, completa-se o histórico de uma vez. Valem as mesmas regras: só leituras, em série, um comando de cada vez, e parar se algum resultado não bater.

Os comandos correm a partir de `/home/xplendor`, num contentor à parte do serviço `worker`, removido no fim:
```
cd /home/xplendor
art() { docker compose -f docker-compose.prod.yml run --rm --no-deps worker php artisan "$@"; }
```
Nos comandos, `<ID>` é o ID da Yuko em produção, confirmado no passo 3.1.

### 3.0 Confirmado no código: ligar o interruptor não lê nada

- O comando `pingwin:item-sales-switch` só grava a coluna `companies.pingwin_item_sales_enabled` e escreve uma linha no registo (`app/Console/Commands/PingwinItemSalesSwitchCommand.php`). Não chama o PingWin, o CoverManager nem a IA, e não põe nenhum job na fila.
- O `CompanyObserver` só reage à criação de uma empresa (`creating` e `created`), não às alterações. Não há outros observadores nem ouvintes de eventos da empresa.
- Nenhum ecrã nem rota da API altera o interruptor: só o comando.
- Com o interruptor ligado, as leituras só acontecem: no job das 05:00 (`ScheduledRestaurantSyncJob`); nos comandos manuais desta secção e da secção 2; e no botão "Sincronizar período" do cartão "Dados para o marketing" (`SyncItemSalesPeriodJob`, só o root). **Não usar esse botão na noite do deploy.**
- A leitura diária do CoverManager (já existente, no job das 05:00) passa a guardar também os agregados por hora, canal, antecedência e estado, tirados da mesma leitura: nenhum pedido a mais.

### 3.1 Na noite do deploy, depois das verificações da secção 1.8 (sem chamadas ao PingWin)

**a) ID da Yuko e estado do interruptor** (só leitura):
```
art tinker --execute='App\Models\Company::where("fiscal_name", "like", "%Yuko%")->get(["id", "fiscal_name", "pingwin_item_sales_enabled"])->each(fn ($c) => print($c->id." ".$c->fiscal_name." interruptor=".($c->pingwin_item_sales_enabled ? "ligado" : "desligado").PHP_EOL));'
```
Deve aparecer uma só empresa, com `interruptor=desligado`.

**b) Lojas e data de abertura de cada uma** (só leitura):
```
art tinker --execute='App\Models\PingwinLocation::where("company_id", <ID>)->orderBy("id")->get()->each(fn ($l) => print($l->id." ".($l->display_name ?: $l->winrest_name)." | loja PingWin ".$l->winrest_store_id." | ativa ".($l->is_active ? "sim" : "não")." | abertura ".($l->opened_on?->toDateString() ?? "vazia")." | CoverManager ".($l->cm_slug ? "sim" : "não").PHP_EOL));'
```
Esperado: as duas lojas ativas, com CoverManager; abertura da Baixa a 2026-03-13 e da Costa Cabral a 2026-06-09 (os primeiros dias com vendas lidos na sessão de dev).
- Se uma abertura estiver vazia ou diferente, corrigir em Restauração › Lojas antes de ligar o interruptor. Gravar uma loja não chama o PingWin.
- A abertura indicada é a que os sinais usam como início da loja. O cartão "Dados para o marketing" avisa quando difere do início detetado.

**c) Ativar a Linha Editorial** (permite "Criar publicação" a partir da Bússola). Primeiro, o estado:
```
art tinker --execute='echo app(App\Services\CompanyModuleService::class)->isEnabled(<ID>, "linha_editorial") ? "Linha Editorial ativa" : "Linha Editorial inativa", PHP_EOL;'
```
Se estiver inativa:
```
art tinker --execute='app(App\Services\CompanyModuleService::class)->enable(<ID>, "linha_editorial", "manual", null, "Deploy da Bússola"); echo "ok", PHP_EOL;'
```
- Fica registado no histórico de módulos da empresa.
- O mesmo pode fazer-se no ecrã dos módulos da empresa, como root.
- Ativar o módulo não chama a IA nem cria ideias. "Criar publicação" só funciona com o mês aberto na Linha Editorial, e o mês nunca se abre sozinho.

**d) Ligar o interruptor:**
```
art pingwin:item-sales-switch <ID> on
```
Deve dizer "vendas por artigo LIGADAS". Nada é lido nesse momento (3.0).

**O que o job das 05:00 vai fazer nessa noite** (na Yuko, depois da leitura diária de sempre do PingWin e do CoverManager, que não muda):

| Passo | Pedidos ao PingWin |
|---|---|
| Vendas por artigo dos 7 dias anteriores | 1 |
| Vendas por hora dos mesmos 7 dias (20 s depois) | 1 |
| Início de cada loja: relatório anual, um ano por loja (as duas abriram em 2026, por isso basta o ano corrente) | 2 (no máximo 5 por loja) |
| Histórico das vendas por artigo: 10 blocos de 7 dias para trás, 20 s entre eles | 10 |
| Histórico por hora e releitura dos dias marcados | 0 (o orçamento de 10 pedidos fica gasto no histórico por artigo; o histórico por hora só começa quando se conhece o primeiro dia com vendas) |
| Só se a noite for de sábado para domingo: catálogo completo | 1 |
| Qualidade dos dados e sinais da Bússola | 0 (só a base de dados) |

- **Total: 14 pedidos novos ao PingWin (15 ao domingo), em série, com 20 s entre eles: cerca de 8 a 10 minutos.**
- No fim, o cálculo dos sinais põe na fila a geração das frases "O quê" das jogadas (`GenerateCompassTextsJob`): **1 pedido à IA** (função `bussola_jogadas`, cerca de 0,02 USD em dev), se a chave do fornecedor estiver no `.env` de produção. Sem chave, ficam os modelos de frase.
- O CoverManager continua com a leitura de sempre (o dia anterior), sem pedidos a mais.
- Se a deteção do início der "não fiável", nada se grava para essa loja, o histórico não avança e a falha entra no resumo ao dono. Na manhã seguinte, tratar como no passo P4 da secção 2, antes de continuar.
- Depois dessa noite, há cerca de 11 semanas de vendas por artigo e 7 dias de vendas por hora. Os sinais dessa manhã são parciais (os períodos fracos precisam de 8 semanas de vendas por hora). Ficam completos com o passo 3.2.

### 3.2 Na manhã seguinte, antes das 11h

**a) O job das 05:00 acabou sem erros:**
```
docker exec xplendor-php sh -lc 'grep -h "Scheduled Restaurant Sync\|PingWin Histórico\|Restauração Sinais" storage/logs/laravel-$(date +%F).log | cut -c1-300'
```
Esperado: "[PingWin Histórico] início detetado" para as duas lojas, "[PingWin Histórico] noite" com `"pedidos":10`, "[Restauração Sinais] calculados" e "[Scheduled Restaurant Sync] fim" com `"falhas":0`. Sem "vendas por artigo falharam" nem "sinais falharam".

**b) No cartão "Dados para o marketing"** (Empresas › Yuko › Integrações › PingWin, como root):
- o distintivo "Leitura ligada";
- "Conferência com o líquido diário (90 dias)": todos os dias lidos batem ("N de N dias batem") e "Sem dias marcados" (ou poucos, que se releem nas noites seguintes);
- "Catálogo": a percentagem dos artigos vendidos. Abaixo de 100% é normal antes do primeiro domingo. Se se quiser já, `art pingwin:catalog-complete <ID>` (1 pedido, 1 a 2 minutos);
- "Categorias das famílias": todas por confirmar (as confirmações de dev não passam para produção);
- "Reservas por classificar": "Nenhuma";
- "Por loja": Baixa com início a 13/03/2026 (abertura indicada) e "Detetado: março de 2026"; Costa Cabral com 09/06/2026 e "Detetado: junho de 2026"; sem o aviso "Difere … da abertura indicada"; cerca de 77 dias lidos em cada loja; o histórico "A importar".

Se alguma coisa não bater, parar aqui e ir a 3.3.

**c) Completar o histórico, um comando de cada vez, pela ordem** (cerca de 45 a 55 minutos no total; começar até às 10h00):

1. **Vendas por artigo** (cerca de 21 pedidos, 20 s entre eles; 12 a 15 minutos):
   ```
   art pingwin:item-history <ID> --calls=40
   ```
   Esperado: "ok" nos dias com líquido diário, "empty_protected" só em dias fechados, "Histórico completo." e, na tabela, as duas lojas "completo", com o "1.º dia com vendas" preenchido. O limite de 40 pedidos deixa margem; o comando pára quando chega ao início.

2. **Vendas por hora** (só depois do 1, porque precisa do primeiro dia com vendas de cada loja; cerca de 30 pedidos, 20 s entre eles; 15 a 20 minutos):
   ```
   art pingwin:hourly-history <ID> --calls=40
   ```
   Esperado: "Histórico por hora completo." e as duas lojas "completo".

3. **Reservas dos últimos 90 dias do CoverManager** (um pedido por loja e por dia, 5 s entre dias; 10 a 15 minutos; nenhum pedido ao PingWin):
   ```
   art covermanager:history <ID> --days=90
   ```
   Esperado: "por classificar: 0". Um código de estado novo regista-se (qual e quantos), sem mexer no mapa.

4. **Recálculo da qualidade dos dados e dos sinais** (só a base de dados; segundos):
   ```
   art tinker --execute='app(App\Services\Restaurant\RestaurantDataQualityService::class)->compute(<ID>); $r = app(App\Services\Restaurant\RestaurantSignalService::class)->compute(<ID>); echo json_encode(["calculado" => $r["computed"], "sinais" => $r["signals"]]), PHP_EOL;'
   ```
   Esperado: `"calculado":true` e algumas dezenas de sinais. Tal como à noite, põe na fila 1 pedido à IA para as frases das jogadas (se houver chave).

**d) No ecrã:** o cartão "Dados para o marketing" com as duas lojas "Completo"; a Bússola (Marketing › Bússola) com o topo da semana, as 3 jogadas e os blocos; no painel de restauração, o "Mapa da semana" e, no separador "Marketing e resultados", as jogadas no fim. A nota "Há N famílias de artigos por confirmar" aparece até a equipa confirmar as categorias.

A partir daqui, o job das 05:00 continua sozinho: os 7 dias anteriores, a releitura dos dias marcados, o catálogo ao domingo e os sinais.

### 3.3 Desligar o interruptor, se algo não bater

```
art pingwin:item-sales-switch <ID> off
```
Deve dizer "vendas por artigo desligadas". O efeito é imediato:
- o job das 05:00 volta a fazer só o que fazia antes do deploy (sem vendas por artigo, por hora, histórico, catálogo nem sinais);
- a leitura diária do CoverManager deixa de guardar os agregados novos;
- os comandos desta secção recusam-se a correr ("O interruptor está desligado");
- um "Sincronizar período" ainda na fila não lê nada, e a geração das frases da IA não corre;
- a Bússola e o cartão mostram "A leitura das vendas por artigo está desligada nesta empresa".

Nada é apagado. O que já foi lido fica guardado e, ao voltar a ligar, o histórico continua de onde parou.

Um comando que já esteja a correr só verifica o interruptor no início. O mais seguro é deixá-lo acabar (cada um tem o seu limite de pedidos). Se for preciso pará-lo, Ctrl+C termina o contentor; nesse caso, registar a hora e o último bloco mostrado antes de voltar a ligar.

A Linha Editorial pode ficar ativa (não lê nada). Para a desativar:
```
art tinker --execute='app(App\Services\CompanyModuleService::class)->disable(<ID>, "linha_editorial"); echo "ok", PHP_EOL;'
```
