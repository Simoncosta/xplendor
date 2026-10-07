# PingWin: relatórios de vendas para o marketing (F0)

Documentação dos pedidos capturados no back-office do PingWin da Yuko (empresa 5) a 7 de outubro de 2026, para a F1 (vendas por artigo e por dia) e a F2 (horas). As capturas foram feitas pela tabela dinâmica de cada relatório. As cópias usadas na análise não têm IDs de sessão nem assinaturas.

Regras que se mantêm: só leituras, à noite, em série e com espaçamento; nunca escrever no PingWin; o Laravel espelha o PingWin e não é a fonte de verdade.

## 1. Resumo

| Relatório | ID | Formato | Intervalo | Uso |
|---|---|---|---|---|
| Vendas por artigo (h1b) | `919525121217` | JSON | **Partido por dia** (`doc_date`) | F1: artigo × dia, com família |
| Vendas por hora (h3, h3b) | `1023875499019` | JSON | **Partido por dia** (`Period: "1"`) | F2: loja × dia × hora |
| Margem de artigos (h4) | `899489909529` | JSON | Data de referência do custo | F4: só o custo |
| Análise de vendas anual (h8) | `1093764095994` | JSON | Artigo ou família × mês de um ano | F1: deteção do primeiro mês com vendas |
| Análise ABC vendas artigos (h1) | `937257919450` | JSON | Soma o intervalo (sem data) | Substituído pelo Vendas por artigo |
| Distribuição horária (h2) | `849312681223` | Só PDF | | Não se usa |
| Artigos sem saídas (h5) | `915279705834` | JSON | Stock por armazém | Não se usa |

Conferência cruzada, ao cêntimo:
- o valor base do Vendas por artigo bate com o líquido diário já espelhado em `pingwin_daily_sales` (30 de setembro: Baixa 1 248,04 €, Original 3 286,30 €);
- o total bate com o faturado (1 427,60 € e 3 769,50 €);
- a soma das horas bate com o valor base, nos 9 dias e nas duas lojas.

## 2. Transporte (igual para todos)

- Pedido: `POST {api_url}/service/report/*/report`, cabeçalhos `Action: OPEN,GET,CLOSE` e `Content-Type: application/json;charset=UTF-8`, mais a sessão que o cliente Python já trata.
- Corpo: `{"params": {"querystring": "report.id = '<ID>'", <parâmetros>, "sendmail": 0, "mimetype": "application/json"}}`.
- Resposta: `{"report": {"report": [{"reportname", "reportfile", "reportdata"}]}}`. O `reportdata` é JSON em base64, dentro da própria resposta: não há download separado nem XLS.
- O JSON descodificado traz caracteres de controlo (`\r`) entre campos: ler com `json.loads(texto, strict=False)` ou limpar antes.
- O servidor está intermitente desde cerca de 1 de outubro (respostas vazias ao acaso). O `trigger_report` atual já repete na mesma sessão até 8 vezes; a F1 generaliza esse método (ID e parâmetros por argumento) em vez de criar outro.
- Datas no formato `AAAAMMDDT00:00:00`.
- Lojas da Yuko: `1099845342604` (Tabern Yuko Baixa) e `584955579139649880` (Tabern Yuko Original, a Costa Cabral). A loja `1099511639284` (Yuko BO) é o back-office e não tem vendas; fica de fora. Nos pedidos vão as lojas registadas em `pingwin_locations`, como no Resumo de Vendas.
- Os IDs dos relatórios ficam na configuração global (como o `PINGWIN_REPORT_ID_SALES`). O ID da margem é igual ao do projeto antigo, o que indica que são globais da cloud GrupoPIE. Confirmar com o segundo cliente.
- Nunca se chama o pedido `/service/report/<ID>/params` em produção: o corpo é fixo. Em alguns relatórios, essa chamada devolve listas de clientes e de funcionários com nomes.

## 3. h1b: Vendas por artigo (ID `919525121217`)

Corpo para 7 dias e as duas lojas:

```json
{"params": {
  "querystring": "report.id = '919525121217'",
  "START_DATE": "20260930T00:00:00", "END_DATE": "20261006T00:00:00",
  "ProdList": "", "ProductGroup": "", "Product_Type": "",
  "PIVOT": 0, "SHOWEXTFAC": 0, "GROUP_MENUS": 1,
  "Stores": "1099845342604,584955579139649880",
  "sendmail": 0, "mimetype": "application/json"}}
```

- `GROUP_MENUS: 1` agrupa os menus (o menu conta como um artigo). `PIVOT: 0` mantém as linhas. `SHOWEXTFAC: 0` exclui a faturação externa.
- Resposta em `data.data`, **uma linha por loja × dia × artigo** (confirmado: 1272 linhas, nenhuma chave repetida):

| Campo | Significado | Guardar |
|---|---|---|
| `store_id` / `store` | Loja | ID |
| `doc_date` | Dia (fiável em JSON; o problema da data era do XLS) | Sim |
| `product_id` / `product_code` / `product_desc` | Artigo | Sim |
| `family_id` / `family_desc` | Família folha e caminho completo (`Família \ Comidas \ Francesinhas`) | Sim |
| `qnt` | Quantidade (pode ter decimais) | Sim |
| `basevalue` / `taxvalue` / `total` | Sem IVA, IVA, com IVA | Sim |
| `discount` | Desconto % da linha agregada | Não (não soma) |
| `company_id` / `company` | Empresa do PingWin ("Yuko BO") | Não |

- Medido: 8 dias, 1272 linhas (cerca de 160 por dia nas duas lojas), 753 KB, 2,6 s.
- As famílias vêm limpas: 23 famílias folha em 5 ramos (Comidas, Bebidas, Sobremesas, Cafetaria, Uber Eats). As famílias desarrumadas da análise anterior ("PRODUÇÃO", "ESPIRITUOSOS", "STAFF MEAL") são de stock e não aparecem nas vendas.
- Peso na faturação dos 8 dias: Francesinhas 58%, Acompanhamentos 9%, Cerveja 7%, Cozinha 6%, Sumos 4%, Sangria 3%, Sobremesas 3%.
- A ABC (h1) dá os mesmos valores na Baixa e 0,6% acima na Original (provavelmente o agrupamento dos menus); fica de fora.

## 4. h3 e h3b: Vendas por hora (ID `1023875499019`)

Corpo para 7 dias e as duas lojas, hora a hora:

```json
{"params": {
  "querystring": "report.id = '1023875499019'",
  "START_DATE": "20260930T00:00:00", "END_DATE": "20261006T00:00:00",
  "Period": "1", "WithTax": 0,
  "Stores": "1099845342604,584955579139649880",
  "GroupHours": 0, "FilterHours": 0,
  "Tit1": "5h - 10h", "Tit2": "11h - 15h", "Tit3": "16h - 19h", "Tit4": "20h - 21h", "Tit5": "22h - 4h",
  "Periodo1": "10,5,6,7,8,9", "Periodo2": "11,12,13,14,15", "Periodo3": "16,17,18,19",
  "Periodo4": "20,21", "Periodo5": "0,1,2,22,23,3,4",
  "sendmail": 0, "mimetype": "application/json"}}
```

- `Period`: `1` dia, `2` semana, `3` mês. `WithTax: 0` sem IVA.
- `GroupHours: 0` dá hora a hora (h3). `GroupHours: 1` agrupa nos 5 períodos `Tit`/`Periodo` (h3b).
- Resposta em `grid_data.data`: **uma linha por loja e por dia** (confirmado na h3b: 9 dias, 18 linhas).
- As colunas são dinâmicas: `<n>\amount` (valor), `<n>\oldamount` e `<n>\variation`. O `<n>` é interno: a hora (ou o período) lê-se em `grid_data.info["<n>\amount"].group_label`. Só aparecem as horas com vendas.
- **O "homólogo" (`oldamount`) é o dia anterior** (confirmado nos 9 dias). Não se guarda.
- Só dá valor; não dá talões nem quantidades por hora.
- Medido: 1 dia hora a hora, 11 KB, 2,4 s; 9 dias por períodos, 17 KB, 2,7 s.
- Por confirmar na primeira noite da F2: o intervalo de 7 dias com `GroupHours: 0` (combinação das duas capturas).

## 5. h4: Margem de artigos (ID `899489909529`)

Um pedido por loja (`STORE` no singular):

```json
{"params": {
  "querystring": "report.id = '899489909529'",
  "START_DATE": "20261006T00:00:00",
  "TAX_SELECTION": "15001", "TAXSCENARIO": "3100",
  "STORE": "1099845342604",
  "SALETABLES": "1649601157540", "ProductGroups": "",
  "sendmail": 0, "mimetype": "application/json"}}
```

- `START_DATE` é a data de referência do custo (não é um intervalo).
- `TAXSCENARIO`: `3100` venda. `SALETABLES`: a única tabela listada é "Uber" (`1649601157540`). **Os preços desta tabela são os da Uber e estão acima do preço médio vendido em sala** (francesinha: 17,00 € na tabela, 15,53 € de média vendida com IVA).
- Resposta em `data.data`, uma linha por artigo de venda da loja (296 na Baixa, 316 na Original): `product_id`, `pcode`, `product`, `store_id`, `warehouse_id`, `price`, `table_price`, `taxvalue`, `cost`, `margin`, `margin_perc`.
- **`margin_perc` é a margem sobre o custo** (francesinha: 305%), não sobre o preço.
- Medido: 222 a 239 KB, 3,1 a 3,4 s por loja.

**Regra (aceite):** do relatório de margem só se usa `product_id` e `cost`. A margem calcula-se do nosso lado:
- margem bruta = (valor líquido vendido − custo × quantidade) ÷ valor líquido vendido;
- o valor líquido vendido e a quantidade vêm do Vendas por artigo (`basevalue`, `qnt`).
- Assim, a tabela Uber não entra nas contas.

## 6. Outros relatórios capturados

- **h8, Análise de vendas anual (ID `1093764095994`):** artigo × mês de um ano, sem IVA, em 6,3 s. A primeira linha ("Acumulado") dá o acumulado mês a mês.
  - Corpo: `"YEAR": "2026"`, `"GROUPBY": "1"`, `"ProdList": ""`, `"WithTax": 0`, `"Client": ""`, `"Employee": ""`, `"Locals": ""`, `"Stores": "<uma loja>"`.
  - `GROUPBY`: `1` artigo, `3` família. **Nunca** `2`, `4`, `5` ou `6` (cliente, comercial, local, local/cliente): trazem dados pessoais ou não servem. O agrupamento por local não está disponível neste relatório.
  - Uso na F1: com uma loja de cada vez, o primeiro mês com acumulado positivo indica onde parar o histórico.
- **h5, Artigos sem saídas (ID `915279705834`):** é um relatório de stock por armazém (1721 linhas, incluindo matérias-primas, com stock e última compra). Não serve para o marketing; o "o que não está a sair" calcula-se do nosso lado.
- **h0:** a página da lista de relatórios só pediu os favoritos (vazio). Não faz falta, porque cada captura revela o ID.

## 7. O que as capturas mostraram sobre os dados

1. **Histórico por loja:** o acumulado anual (todas as lojas) começa em março de 2026. O início de cada loja deteta-se automaticamente (primeiro dia com vendas).
   - Em dev, o espelho diz que a Baixa já vendia a 1 de maio (início do espelho) e que a Costa Cabral tem 11 dias a zero em maio e a primeira venda a 9 de junho. Isto é o contrário do esperado; a deteção na F1 tira a dúvida.
2. **Custos:** a fonte certa é o relatório de margem, não o catálogo espelhado. Em 7 dias, a faturação dos artigos com custo foi de 89% (Baixa) e 91% (Original). Com margem bruta plausível (entre 5% e 95%), foi de 88% e 85%. **A Yuko passa o limiar de 80%.**
   - Erros detetados pela regra: a "Coca Cola 0" na Original tem custo de 12,66 €, provavelmente o custo de uma embalagem e não de uma unidade; o "Maracujá" está sem custo. Ficam de fora e entram na lista de artigos a corrigir no PingWin.
3. **Catálogo espelhado incompleto:** em dev, só 40 dos 185 artigos vendidos em 8 dias e 78 dos 325 artigos do relatório de margem estão em `pingwin_catalog_items`.
   - A causa está na leitura: o catálogo vem ordenado pelo código como texto ('0', '1', '10', '100', '100078' … '101250') e todos os artigos que viriam a seguir ('102', '11', '2', '225' …) ficaram de fora.
   - A leitura parou nos 1012 artigos (uma página de 1000 e outra de 12). A gravação no Laravel não filtra nada.
   - O motivo exato (limite do servidor ou da paginação por `Range`) só se vê com um pedido. A F1 corrige e verifica (ver o desenho da F1).
4. **Lojas:** o back-office (Yuko BO) aparece nas listas de lojas mas não vende; fica fora dos pedidos.

## 8. Plano de pedidos (F1 e F2)

Por empresa, em série, à noite, com pelo menos 20 s entre pedidos e uma sessão por bloco:

| Pedido | Frequência | Pedidos |
|---|---|---|
| Vendas por artigo, os 7 dias anteriores (inclui as correções tardias) | Todas as noites | 1 |
| Vendas por hora, os 7 dias anteriores (F2) | Todas as noites | 1 |
| Catálogo completo | Ao domingo | 2 a 4 páginas |
| Margem, uma por loja (F4) | Ao domingo | 2 |
| Anual, uma loja e um ano de cada vez (início das lojas) | Uma vez por loja, e de novo se aparecer uma loja nova | 2 a 4 |
| Histórico do Vendas por artigo, 7 dias por pedido | Uma vez, até 10 pedidos por noite | cerca de 30 para a Yuko (3 noites) |

Hoje a integração faz 1 relatório por noite. Com a F1 passa a 2, e a 3 com a F2, mais o catálogo e a margem ao domingo e o histórico inicial.
