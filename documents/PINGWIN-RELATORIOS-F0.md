# PingWin: relatórios de vendas para o marketing (F0)

Documentação dos pedidos capturados no back-office do PingWin da Yuko (empresa 5) a 7 de outubro de 2026, para a F1 (vendas por artigo e por dia) e a F2 (horas). As capturas foram feitas pela tabela dinâmica de cada relatório. As cópias usadas na análise não têm IDs de sessão nem assinaturas.

Regras que se mantêm: só leituras, à noite, em série e com espaçamento; nunca escrever no PingWin; o Laravel espelha o PingWin e não é a fonte de verdade.

## 1. Transporte (igual para todos)

- Pedido: `POST {api_url}/service/report/*/report`, cabeçalhos `Action: OPEN,GET,CLOSE` e `Content-Type: application/json;charset=UTF-8`, mais a sessão que o cliente Python já trata.
- Corpo: `{"params": {"querystring": "report.id = '<ID>'", <parâmetros>, "sendmail": 0, "mimetype": "application/json"}}`.
- Resposta: `{"report": {"report": [{"reportname", "reportfile", "reportdata"}]}}`. O `reportdata` é JSON em base64, dentro da própria resposta: não há download separado nem XLS.
- O JSON descodificado traz caracteres de controlo (`\r`) entre campos: ler com `json.loads(texto, strict=False)` ou limpar antes.
- O servidor está intermitente desde cerca de 1 de outubro (respostas vazias ao acaso). O `trigger_report` atual já repete na mesma sessão até 8 vezes; a F1 generaliza esse método (ID e parâmetros por argumento) em vez de criar outro.
- Datas no formato `AAAAMMDDT00:00:00`.
- Lojas da Yuko: `1099845342604` (Tabern Yuko Baixa) e `584955579139649880` (Tabern Yuko Original). A loja `1099511639284` (Yuko BO) é o back-office e não tem vendas; fica de fora.
- Os IDs dos relatórios ficam na configuração global (como o `PINGWIN_REPORT_ID_SALES`). O ID da margem é igual ao do projeto antigo, o que indica que são globais da cloud GrupoPIE. Confirmar com o segundo cliente.
- Nunca se chama o pedido `/service/report/<ID>/params` em produção: o corpo é fixo. Em alguns relatórios, essa chamada devolve listas de clientes e de funcionários com nomes.

## 2. h1: Análise ABC vendas artigos (ID `937257919450`)

A captura h1 trouxe este relatório, e não o "Vendas por artigo". Serve para a F1.

Corpo para um dia e as duas lojas:

```json
{"params": {
  "querystring": "report.id = '937257919450'",
  "Stores": "1099845342604,584955579139649880",
  "START_DATE": "20261006T00:00:00", "END_DATE": "20261006T00:00:00",
  "TaxesIncluded": "1", "FamGroup": "-1", "ProductsView": "0",
  "GroupModels": 0, "Detailed": 1, "PageBreak": 0, "StoreGroup": "",
  "sendmail": 0, "mimetype": "application/json"}}
```

- `TaxesIncluded`: `0` = com IVA, `1` = sem IVA (a lista do PingWin diz 0=Sim, 1=Não). A captura usou `0`; a F1 usa `1`, para bater com o `net_cents` do Resumo de Vendas.
- `FamGroup`: `-2` grupo de lojas, `-1` loja, `0` artigo, `1` a `4` nível de família. Usar `-1` (loja e depois artigo).
- `ProductsView`: `0` só os vendidos.
- Resposta em `dados.data`, uma linha por loja e artigo, sem data:

| Campo | Significado |
|---|---|
| `majorcode` / `smajorcode` / `band_0\majorname` | ID, código e nome da loja |
| `minorcode` | ID do artigo no PingWin (chave da F1) |
| `band_1\sminorcode` / `band_1\minorname` | Código e nome do artigo |
| `band_2\minorqnt` | Quantidade vendida |
| `band_3\minortotal` | Valor vendido |
| `band_*_perc`, `band_4\minoracum*` | Percentagens e acumulado ABC (calculam-se do nosso lado; não guardar) |

- **Intervalo e agrupamento por dia:** o relatório soma o intervalo inteiro e não tem opção de agrupar por dia. Para ter artigo × dia, faz-se **um pedido por dia**, com as duas lojas no mesmo pedido.
- Medido: 7 dias, 305 linhas (142 + 163 artigos), 300 KB, 4,7 s.
- Não traz a família do artigo (ver 6).

## 3. h2: Distribuição horária das vendas (ID `849312681223`)

Só gera PDF (`"mimetype": "application/pdf"`, `"reportaction": "execute"`); não tem tabela dinâmica. **Não se usa**: a h3 dá o mesmo em JSON.

## 4. h3: Vendas por hora (ID `1023875499019`)

Corpo para um dia e as duas lojas:

```json
{"params": {
  "querystring": "report.id = '1023875499019'",
  "START_DATE": "20261006T00:00:00", "END_DATE": "20261006T00:00:00",
  "Period": "1", "WithTax": 0,
  "Stores": "1099845342604,584955579139649880",
  "GroupHours": 0, "FilterHours": 0,
  "Tit1": "5h - 10h", "Tit2": "11h - 15h", "Tit3": "16h - 19h", "Tit4": "20h - 21h", "Tit5": "22h - 4h",
  "Periodo1": "10,5,6,7,8,9", "Periodo2": "11,12,13,14,15", "Periodo3": "16,17,18,19",
  "Periodo4": "20,21", "Periodo5": "0,1,2,22,23,3,4",
  "sendmail": 0, "mimetype": "application/json"}}
```

- `Period`: `1` dia, `2` semana, `3` mês. `WithTax`: `0` sem IVA. `GroupHours: 0` mantém a hora a hora (os `Tit`/`Periodo` só contam com `GroupHours: 1`, mas vão no corpo como na captura).
- Resposta em `grid_data.data`: uma linha por loja e dia (`store_id`, `store`, `dday`).
- As colunas das horas são dinâmicas: `<n>\amount` (valor), `<n>\oldamount` (homólogo) e `<n>\variation` (%). O `<n>` é interno: a hora lê-se em `grid_data.info["<n>\amount"].group_label` (`"0"` a `"23"`). Só aparecem as horas com vendas.
- Só dá valor; não dá talões nem quantidades por hora.
- O "homólogo" não corresponde ao ano anterior (o PingWin da Yuko só tem vendas desde março de 2026). Não se usa; as comparações fazem-se do nosso lado.
- Medido: 1 dia, 2 linhas, 11 KB, 2,4 s. Conferência: Baixa a 6 de outubro somou 1 231,26 € sem IVA, na ordem do líquido diário espelhado nas terças anteriores.
- **Por confirmar:** se um intervalo de 7 dias com `Period: "1"` devolve uma linha por loja e por dia (captura h3b). Se sim, basta um pedido por semana; se não, um por dia.

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

**Regra para a F1/F4:** do relatório de margem só se usa `product_id` e `cost`. A margem calcula-se do nosso lado:
- margem bruta = (valor líquido vendido − custo × quantidade) ÷ valor líquido vendido;
- o valor líquido vendido e a quantidade vêm da h1 (sem IVA).
- Assim, a tabela Uber não entra nas contas.

## 6. Outros relatórios capturados

- **h8, Análise de vendas anual (ID `1093764095994`):** artigo × mês de um ano, sem IVA, todas as lojas somadas, em 6,3 s.
  - `GROUPBY`: `1` artigo, `3` família, `5` local (canal de venda: sala, Uber, encomenda). **Nunca** `2`, `4` ou `6` (cliente, comercial, local/cliente), porque trazem dados pessoais.
  - Útil para conferir os totais mensais e, com `GROUPBY: "5"`, para separar a sala da entrega.
- **h5, Artigos sem saídas (ID `915279705834`):** é um relatório de stock por armazém (1721 linhas, incluindo matérias-primas, com stock e última compra). Não serve para o marketing; o "o que não está a sair" calcula-se a partir da h1.
- **h0:** a página da lista de relatórios só pediu os favoritos (vazio). Não faz falta, porque cada captura revela o ID.

## 7. O que as capturas mostraram sobre os dados

1. **Histórico:** o acumulado do relatório anual está a zero em janeiro e fevereiro de 2026 e começa em março. O PingWin da Yuko tem cerca de 7 meses de vendas.
   - A decisão "24 meses" passa a "todo o histórico disponível" (desde março de 2026).
   - A sazonalidade face ao ano anterior só existe a partir de março de 2027. Até lá, as âncoras usam o calendário do ramo, não as vendas da própria Yuko.
2. **Custos:** a fonte certa é o relatório de margem, não o catálogo espelhado. Em 7 dias, a faturação dos artigos com custo foi de 89% (Baixa) e 91% (Original). Com margem bruta plausível (entre 5% e 95%), foi de 88% e 85%. **A Yuko passa o limiar de 80%.**
   - Erros detetados pela regra: a "Coca Cola 0" na Original tem custo de 12,66 €, provavelmente o custo de uma embalagem e não de uma unidade; o "Maracujá" está sem custo. Ficam de fora e entram na lista de artigos a corrigir no PingWin.
3. **Catálogo espelhado incompleto:** em dev, só 40 dos 181 artigos vendidos na semana estão em `pingwin_catalog_items` (6% da faturação; a francesinha não está). O nome e o código vêm da h1. A família fica pendente da captura h1b ou da correção do catálogo.
4. **Lojas:** o back-office (Yuko BO) aparece nas listas de lojas mas não vende; fica fora dos pedidos.

## 8. Capturas que faltam

Pela tabela dinâmica, como as anteriores:

| Ficheiro | Relatório e parâmetros | Para quê |
|---|---|---|
| `h1b-vendas-por-artigo.har` | "Vendas por artigo" (não a ABC), 7 dias, as duas lojas; se houver opção de agrupar por dia ou por família, ligá-la | Família por artigo; saber se um intervalo vem partido por dia |
| `h3b-vendas-hora-7d.har` | "Vendas por hora", 7 dias, "Agrupar por: Dia" | Confirmar uma linha por loja e por dia (um pedido por semana) |
| `h8b-anual-local.har` (opcional) | "Análise de vendas anual", 2026, "Agrupar por: Local" | Separar a sala da entrega (Uber) por mês |

## 9. Plano de pedidos (F1 e F2)

Por empresa, em série, à noite, com pelo menos 20 s entre pedidos e uma sessão por bloco:

| Pedido | Frequência | Pedidos |
|---|---|---|
| ABC (h1), dia anterior | Todas as noites | 1 |
| ABC, os 7 dias anteriores (correções tardias) | Ao domingo | 7 |
| Vendas por hora (h3), dia anterior | Todas as noites | 1 |
| Margem (h4), uma por loja | Ao domingo | 2 |
| Histórico ABC desde março de 2026 | Uma vez, 60 dias por noite | cerca de 200, em 4 noites |
| Histórico por hora | Uma vez | 30 (se h3b confirmar) ou cerca de 200 |

Hoje a integração faz 1 relatório por noite. Com a F1 e a F2, passa a 3 por noite e 12 ao domingo, mais o histórico inicial.
