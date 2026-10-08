# F1: relatório da manhã (noite de 7 para 8 de outubro de 2026)

Fonte seguida: `documents/PINGWIN-F1-DESENHO.md`. Durante a noite não houve nenhuma chamada ao PingWin, ao CoverManager nem a fornecedores de IA, nada contra a produção e nenhum deploy. A sincronização nova está atrás do interruptor `companies.pingwin_item_sales_enabled`, desligado por omissão (a Yuko em dev continua desligada).

## 1. O que ficou feito

| Parte | Commit | Conteúdo |
|---|---|---|
| Desenho | `021a04e` | `documents/PINGWIN-F1-DESENHO.md` (fonte única) |
| F1-1 | `44090cb` | Vendas por artigo e por dia, interruptor, job das 05:00, comandos |
| F1-2 | `5e74108` | Início de cada loja, histórico, releitura dos dias marcados, catálogo completo, período manual |
| F1-3 | `12c4921` | Qualidade dos dados, categorias das famílias, cartão e página |

**F1-1**
- Python: o pedido de relatório passou a genérico (`run_report`, com as 8 tentativas de sempre). O Resumo de Vendas usa-o com os mesmos parâmetros (há teste). Novo modo `item_sales`: o "Vendas por artigo", até 7 dias por pedido, com uma lista fechada de campos.
- Tabelas `pingwin_item_sales_daily` (loja × dia × artigo, em cêntimos) e `pingwin_item_sales_days` (estado de cada loja × dia).
- Cada leitura substitui os artigos do dia (espelho do PingWin).
  - **Proteção:** um relatório sem linhas não apaga um dia com vendas; só um líquido diário a zero confirma um dia sem vendas.
  - **Conferência:** a soma dos artigos tem de bater com o líquido diário, com tolerância de 1%.
- Interruptor por empresa, fora do `fillable`: só o comando o muda. Com ele ligado, o job das 05:00 lê os 7 dias anteriores.
- Comandos `pingwin:item-sales` (com `--dry-run`) e `pingwin:item-sales-switch`.

**F1-2**
- **Início de cada loja:** pelo relatório anual, uma loja e um ano de cada vez, recuando enquanto janeiro tiver vendas.
  - **Salvaguarda:** se o anual der 0 num mês em que o líquido diário espelhado tem vendas, não conclui nem grava nada.
- **Histórico:** blocos de 7 dias, para trás, até ao primeiro mês com vendas. São até 10 pedidos por noite, com 20 s entre eles. No fim, cada loja fica com `sales_since` (o primeiro dia com vendas) e `history_complete_at`. Os dias antes do início da loja não se leem nem se marcam.
- **Releitura:** os dias marcados (não batem, ou vazios protegidos) voltam a ler-se até 3 vezes, nunca no mesmo dia em que foram lidos.
- **Catálogo completo:** a leitura nova avança pelo número de itens recebidos, por isso já não para nem salta numa página curta (o caso da Yuko).
  - Se ainda faltarem artigos vendidos nos últimos 90 dias, lê família a família. **Substituído na correção da sessão:** os vendidos em falta procuram-se nos anulados (a leitura que já existe) e a leitura família a família deixou de ser usada.
  - A leitura de sempre (o botão "Sincronizar artigos") ficou exatamente igual, porque a nova só se usa com o interruptor ligado.
- **Job das 05:00:** histórico todas as noites e catálogo completo ao domingo (hora de Lisboa), só com o interruptor ligado.
- **"Sincronizar período" manual:** no fim do batch dos dias, relê também as vendas por artigo do período (até 92 dias), só com o interruptor ligado.
- Comandos `pingwin:item-history` e `pingwin:catalog-complete`.

**F1-3**
- **Categorias:** as regras sugerem pelo caminho da família. Sugerem 20 das 23 famílias da Yuko; "Uber Eats \ Comida" fica como Entrega.
  - Sobram Sangria, Pão e Sandwiches, que ficam para a IA: função nova `family_categories` no ecrã dos modelos de IA, registada em `ai_requests`, com caso no teste cego.
  - Nada fica confirmado sem uma pessoa.
- **Retrato da qualidade por empresa** (`restaurant_data_quality`):
  - cobertura do catálogo;
  - conferência com o líquido diário nos últimos 90 dias;
  - famílias por confirmar e o seu peso;
  - por loja: abertura indicada, início detetado (aviso acima de 7 dias), início efetivo (manda a abertura), dias lidos e estado do histórico.
- **Ecrãs:** o cartão "Dados para o marketing" nas Integrações (quando o PingWin está ligado) e a página "Categorias das famílias" em `/restauracao/categorias`.

## 2. Testes e verificação no ecrã

**Testes**
- Laravel: 1465 passam e falham 24, as mesmas 24 conhecidas (carros, autenticação, promoções de stock), sem aumento.
  - Novos: F1-1 com 16, F1-2 com 14 e F1-3 com 6.
  - Ajustados:
    - o teste dos fornecedores de IA (a função nova);
    - o teste de acesso por agência (as duas rotas novas de gestão entram na lista "só administradores");
    - o teste do job noturno.
- Python: todos os testes do PingWin passam, 8 novos na F1-1 e 12 na F1-2. Correram dentro do contentor do scraper, com um executor mínimo, porque o contentor não tem o pytest.
- MariaDB de dev:
  - as três migrações estão aplicadas;
  - o `down` e o `up` foram exercitados numa cópia temporária, já apagada;
  - as gravações reais (as linhas da captura h1b e o histórico com dados de amostra) correram dentro de transações revertidas, e a base ficou como estava.
- A gravação em dev a partir da captura bate **ao cêntimo** com o líquido diário espelhado nos 6 dias em que existe.
- O frontend compila (`yarn build`, sem avisos nos ficheiros novos) e o tipo verifica (`tsc`).

**Ecrã** (servidor de desenvolvimento, API simulada com respostas geradas pelo código Laravel real sobre a captura h1b; o início das lojas e as sugestões da IA são de amostra):
- O cartão foi verificado em computador e telemóvel, claro e escuro: sem travessões, sem deslocamento horizontal a 390 px e sem erros de JavaScript.
  - Mostra "6 de 14 dias batem", "21,7% dos artigos vendidos" (o catálogo incompleto de dev) e "20 de 23 por confirmar".
  - Na Baixa aparece o aviso "Difere 92 dias da abertura indicada" (abertura em dev a 01/08, início de amostra em maio).
- A página das categorias foi verificada em computador e telemóvel, claro e escuro: sem seletores nativos e com lista empilhada no telemóvel.
  - Fluxo: "Pedir sugestões à IA" (simulada) acrescentou a sugestão de Sandwiches; a Sangria mudou para Vinho no react-select; "Confirmar 20 categorias" enviou as 20 e o cabeçalho passou a "Todas confirmadas".
  - Sem permissão, os botões ficam desativados com o motivo e os seletores também (23 de 23). Como o tema partilhado não os distingue visualmente, acrescentei uma nota visível para quem só pode consultar.
- Capturas de ecrã: na pasta temporária da sessão (`scratchpad/f13/shots`), dez imagens.

## 3. Decisões tomadas por omissão (para confirmar)

1. **Catálogo:** a leitura completa só se usa com o interruptor ligado; a leitura de sempre ficou igual, para nada mudar em produção sem o interruptor.
2. **Histórico até ao início detetado:** o histórico importa-se até ao início detetado, mesmo que a abertura indicada seja posterior. A abertura indicada só decide o início efetivo (mostrado e usado), como na decisão 1.
3. **Relatório anual:** `GROUPBY` "1" (o capturado) e `Locals` vazio, com a salvaguarda; ver a pergunta 1.
4. **Releitura dos dias marcados:** até 3 leituras e nunca no mesmo dia; os dias sem líquido diário e sem linhas ficam "vazios protegidos".
5. **Leitura família a família:** 2 s entre os pedidos ao catálogo (são leves) e limite de 15 minutos por execução; só corre quando faltam artigos vendidos. **Retirada na correção da sessão** (era pesada: 200 famílias, e o filtro já inclui as subfamílias).
6. **Período manual:** as vendas por artigo releem-se no fim do batch dos dias, para a conferência já ter os líquidos diários.
7. **Função de IA `family_categories`:** modelo por omissão `claude-opus-5-5`, esforço baixo. Quando não houver categoria clara, a IA responde "outros". O root muda o modelo no ecrã dos modelos de IA.
8. **Regras das categorias:** lista de palavras por categoria (em `FamilyCategoryRules`). Sangria, Pão e Sandwiches ficam de propósito para a IA ou para a equipa.
9. **Quem confirma:** quem configura integrações (ver a pergunta 2).
10. **Seletor por confirmar:** na página, o seletor de uma família por confirmar vem preenchido com a sugestão. O botão "Confirmar N categorias" confirma de uma vez todas as escolhas visíveis; é sempre uma ação de uma pessoa.
11. **Janela de 90 dias:** usada na cobertura do catálogo, na conferência e no peso das famílias. O retrato recalcula-se ao abrir o cartão e todas as noites (com o interruptor ligado).
12. **Página sem entrada no menu:** só se abre pelo cartão (ver a pergunta 3).

## 4. Perguntas pendentes

Estão em `documents/F1-NOITE-PERGUNTAS.md`:
1. **Relatório anual:** o `Locals` vazio quer dizer "todos"? E onde devem ficar os postos de venda para o job das 05:00?
2. **Quem confirma as categorias:** só o root, ou também o administrador da empresa e da agência?
3. **Menu:** entrada no menu para "Categorias das famílias"?
4. **Job das 05:00 com várias empresas:** separar o histórico num job próprio já, ou só com a segunda empresa?

## 5. A sessão real de hoje, passo a passo

Numa hora fora de serviço do restaurante. Só fazem leituras no PingWin (e, no passo 1, no CoverManager, como o job de todas as noites).

**Atualizado depois da sessão real de 8 de outubro.** Os comandos correm, a partir da raiz do repositório, num contentor à parte do serviço `worker` (`docker compose run --rm --no-deps worker …`), que tem o socket do Docker e é removido no fim. O `xplendor-worker` reinicia de hora a hora (`queue:work --max-time=3600`) e esse reinício matou o passo 6 a meio. Mudou também:
- um dia que não bate relê primeiro o Resumo de Vendas desse dia e só fica marcado se continuar a não bater;
- os dias antes do primeiro dia com vendas de cada loja ficam como "sem vendas" e não voltam a ler-se;
- o catálogo procura os vendidos em falta nos anulados, sem leitura família a família;
- os postos de venda do relatório anual ficam na configuração da integração (só o root os edita, no cartão "Dados para o marketing"); o `--locals` do comando sobrepõe-se.

**0. Verificar o ambiente** (devem aparecer `xplendor-scraper` e `xplendor-db`; o `xplendor-worker` não é preciso para os comandos manuais):
```
docker ps --format '{{.Names}}' | grep xplendor
docker compose run --rm --no-deps worker php artisan pingwin:item-sales-switch 5
```
Deve dizer "vendas por artigo desligadas".

**1. Pôr o líquido diário em dia** (o espelho de dev acaba a 2 de outubro). Sem isto, a conferência dos últimos dias aparece como "sem resumo para conferir". Faz as mesmas leituras do job das 05:00, um dia de cada vez, com 20 s entre dias (cerca de 3 minutos):
```
docker compose run --rm --no-deps worker php artisan tinker --execute='foreach (Carbon\CarbonPeriod::create("2026-10-03", "2026-10-07") as $d) { App\Jobs\SyncRestaurantJob::dispatchSync(5, $d->toDateString(), false); sleep(20); } echo "ok";'
```

**2. Simulação dos últimos 7 dias** (1 pedido, nada gravado; cerca de 10 a 40 s):
```
docker compose run --rm --no-deps worker php artisan pingwin:item-sales 5 --dry-run
```
O que deve aparecer:
- 14 linhas (2 lojas × 7 dias, de 01/10 a 07/10), todas "OK", sem "NÃO BATE" nem "VAZIO (protegido)";
- "Família \ Comidas \ Francesinhas" à frente nas famílias, perto de 58%;
- "Pedidos ao PingWin: 1".
- Os valores de 01/10 a 06/10 devem ser os da tabela em `documents/PINGWIN-RELATORIOS-F0.md` §9.

**3. Ligar o interruptor e gravar os 7 dias:**
```
docker compose run --rm --no-deps worker php artisan pingwin:item-sales-switch 5 on
docker compose run --rm --no-deps worker php artisan pingwin:item-sales 5
```
Deve aparecer o mesmo resultado, a terminar com "Concluído: vendas por artigo gravadas."

**4. Detetar o início de cada loja** (2 a 4 pedidos ao relatório anual, 20 s entre eles; cerca de 1 a 2 minutos):
```
docker compose run --rm --no-deps worker php artisan pingwin:item-history 5 --detect-only
```
O que deve aparecer:
- por loja, o primeiro mês com vendas e os acumulados de cada ano lido;
- para a Costa Cabral, junho de 2026 (o espelho de dev tem a primeira venda a 9 de junho);
- para a Baixa, maio de 2026 ou antes (o espelho de dev já tem vendas a 1 de maio; o acumulado de todas as lojas começa em março).

Se aparecer "Deteção do início não fiável", nada foi gravado: repita com os postos de venda da captura (pergunta 1):
```
docker compose run --rm --no-deps worker php artisan pingwin:item-history 5 --detect-only --redetect --locals=584955579139621602,62590524561075475,1649601157548,1649601157547,1649601156521,56161405118316322
```

**5. Importar o histórico completo:**
```
docker compose run --rm --no-deps worker php artisan pingwin:item-history 5 --calls=40
```
- **Quantos pedidos:** com início em março, cerca de 30 blocos de 7 dias (com início em maio, cerca de 22).
- **Quanto tempo:** cada pedido leva 5 a 15 s, mais 20 s de espaçamento, por isso conte com 15 a 20 minutos. Pode dividir (por exemplo `--calls=15` e repetir); o comando continua de onde parou.
- **O que deve aparecer:**
  - um bloco por linha, com estados "ok" onde há líquido diário espelhado (desde maio em dev) e "unverified" antes disso;
  - nenhum "mismatch";
  - "empty_protected" só em dias em que a loja esteve fechada;
  - no fim, "Histórico completo." e uma tabela por loja com o primeiro mês detetado, o primeiro dia com vendas, os dias lidos e "completo".

**6. Catálogo completo** (1 a 2 minutos, um pedido):
```
docker compose run --rm --no-deps worker php artisan pingwin:catalog-complete 5
```
O que deve aparecer:
- as páginas lidas, com "parou por: página vazia" (ou "total anunciado") e mais de 1012 artigos;
- se houver vendidos que estão anulados no PingWin, a linha "Vendidos encontrados nos anulados (entram como anulados)" (na Yuko, os três Soalheiro, se forem de facto anulados);
- no fim, "fora do catálogo depois: 0", "cobertura 100%" e "Catálogo completo."

**7. Conferir no MariaDB de dev:**
```sql
-- Conferência com o líquido diário (últimos 90 dias): esperado ok na grande maioria, mismatch 0
SELECT status, COUNT(*) FROM pingwin_item_sales_days WHERE company_id = 5 AND business_date >= CURDATE() - INTERVAL 90 DAY GROUP BY status;
-- Histórico por loja: primeiro dia lido e dias lidos
SELECT l.display_name, l.opened_on, l.sales_first_month, l.sales_since, l.history_complete_at, MIN(d.business_date) desde, COUNT(d.id) dias
FROM pingwin_locations l LEFT JOIN pingwin_item_sales_days d ON d.location_id = l.id WHERE l.company_id = 5 GROUP BY l.id;
```

**8. Conferir no ecrã:**
- Integrações da Yuko, cartão "Dados para o marketing":
  - "Leitura ligada";
  - "X de Y dias batem" com 0 dias marcados;
  - cobertura do catálogo a 100%;
  - por loja: o início, os dias lidos e "Completo".
  - A Baixa vai mostrar o aviso de diferença porque a abertura indicada em dev é 01/08; corrija a data em Restauração › Lojas se não for a real.
- `/restauracao/categorias`: confirmar as categorias.
  - O botão "Pedir sugestões à IA" faz uma **chamada real** ao fornecedor de IA configurado para Sangria, Pão e Sandwiches. Use-o só se quiser; também pode escolher à mão.

**9. No fim:** decidir se o interruptor fica ligado em dev (o job das 05:00 do Mac continua sozinho, com o histórico e, ao domingo, o catálogo) ou se o desliga:
```
docker compose run --rm --no-deps worker php artisan pingwin:item-sales-switch 5 off
```
Em produção continua tudo desligado até decidir ligá-lo, empresa a empresa, depois de validado.
