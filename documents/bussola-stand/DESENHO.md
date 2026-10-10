# Bússola do stand: inventário e desenho (Parte 1)

Escrito a 11 de outubro de 2026, só com leituras do código e da base de dados de dev. Nenhuma chamada a serviços externos. As decisões técnicas por omissão estão marcadas **[Omissão]** e resumidas no fim; as perguntas de produto estão em `NOITE-PERGUNTAS.md`.

**Princípio:** onde o pedido refere uma regra que já existe (as regras da 2D, a comparação exata, os limiares), a Bússola **usa essa regra tal como está**, para nunca dizer uma coisa diferente das Recomendações do dashboard.

---

## 1. Inventário do automóvel

### 1.1 As empresas de stand em dev

O módulo `stock` está ligado nas empresas 1 a 4. O "stock próprio" são os estados `active`, `available_soon` e `reserved`, com `is_resume = 0`. Não existe distinção entre stock próprio e consignação: só `is_resume` (retoma).

| Empresa | Stock próprio | Notas |
|---|---|---|
| 1 | 0 | sem veículos |
| 2 | 2 carros | 10 vendidos; Linha Editorial e Ficheiros ligados |
| 3 | 33 carros (mais 13 retomas) | sem Linha Editorial; imagens só externas (`car_external_images`) |
| 4 | 20 autocaravanas e 5 caravanas | a única com autocaravanas; Linha Editorial e Ficheiros ligados |

Não há motos em dev.

### 1.2 Dias em stock e limiares (`StockAge`, `StockThresholds`)

- **Limiares** (`app/Constants/StockThresholds.php`):
  - carros e motos 45 dias, autocaravanas e caravanas 120, outro tipo 60;
  - `isStale` usa "maior ou igual";
  - as faixas são T/3, 2T/3 e 4T/3: 15, 30 e 60 dias nos carros; 40, 80 e 160 nas autocaravanas.
- **Dias em stock** (`app/Support/StockAge.php`):
  - desde `car_created_at` (do DMS ou da Carmine) ou, sem ela, desde `created_at` (a entrada no XPLENDOR);
  - até à venda ou até hoje.
- **Em dev:**
  - a data de entrada é estimada (pelo `created_at`) em quase todos os veículos das empresas 2 e 4;
  - todo o stock próprio está acima do limiar.
- **Fiabilidade:** boa quando há `car_created_at`; estimada nos outros. **[Omissão]** A Bússola mostra uma nota quando a data de entrada é estimada (quantos veículos).

### 1.3 Preço face ao mercado (`PricePosition`, `AutomotivePriceComparison`)

- **Os comparáveis:**
  - vêm do conjunto partilhado `car_market_snapshots` (já guardado; o refresh das 03:30 é uma tarefa externa, desligada em dev);
  - o resultado por veículo fica em `car_market_aggregates` (mediana, p25 e p75, número de comparáveis, confiança, método).
- **Carros:** cascata de degraus.
  1. Marca e modelo iguais, ano mais ou menos 1, combustível e caixa iguais, potência mais ou menos 25 cv; pelo menos 3 resultados.
  2. Ano mais ou menos 3, com os outros filtros.
  3. Marca, modelo e ano mais ou menos 3.

  Os degraus 2 e 3 contam como exatos, com a etiqueta "critérios alargados".
- **Autocaravanas** (`MotorhomeMarketEngine`):
  - a tipologia é eliminatória;
  - ano mais ou menos 2, preço entre 3 000 e 300 000 euros, anúncios dos últimos 60 dias, sem duplicados nem valores extremos.
- **Posição** (`PricePosition`):
  - sobre o preço efetivo (o promocional, se for menor que o bruto);
  - abaixo de menos 5% é abaixo do mercado, até 3% é alinhado, a partir de 3% é acima;
  - "acima" só com confiança média ou alta.
- **Regra da comparação exata** (`AutomotivePriceComparison`):
  - é aproximada só nos aggregates antigos de autocaravanas e caravanas (`method` nulo e `fallback_used`), que podem ter largado o modelo; tudo o resto é exato;
  - `counts_as_above_market` exige exata e acima.
  - A regra do motor (`PriceAboveMarketRule`) pede ainda uma diferença superior a 5%.
- **Fora das comparações:** autocaravanas sem tipologia (aggregate `failed`), caravanas (sem mercado) e veículos sem comparáveis.
- **Em dev:**

  | Empresa | Exata, acima | Exata, alinhada | Exata, abaixo | Aproximada | Sem dados |
  |---|---|---|---|---|---|
  | 2 | 0 | 1 | 1 | 0 | 0 |
  | 3 | 20 | 2 | 1 | 0 | 8 |
  | 4 | 3 | 0 | 0 | 6 | 15 |

- **Fiabilidade:** a dos comparáveis; a confiança e o número de comparáveis são mostrados sempre.
- **O preço recomendado do mercado (`mediana × 0,99`) nunca é usado:** a Bússola não sugere preços.

### 1.4 Gasto em anúncios por veículo (`CarAdSpendRepository`, a etiqueta `[id:N]`)

- **A etiqueta:** `[id:N]` no nome do anúncio da Meta liga o anúncio a um ou mais veículos (`AdCarTag`); o gasto reparte-se em partes iguais (`MetaAdCarAllocator`, tabela `meta_ad_car_spend_daily`).
- **A fonte única:** `CarAdSpendRepository` junta a etiqueta e o legado (mapeamentos manuais em `car_ad_campaigns`), sem contar duas vezes.
- **Anúncio ativo:** um anúncio com a etiqueta em `effective_status = ACTIVE`, ou um mapeamento legado ativo.
- **Em dev:**
  - as tabelas da etiqueta estão vazias;
  - o legado só tem a empresa 3, de abril a junho (49 euros);
  - não há gasto nas últimas 4 semanas.
- **Fiabilidade:** boa com etiquetas; o legado reparte por pesos manuais.

### 1.5 O funil por veículo (vistas, contactos, leads)

- **As tabelas:** `car_views`, `car_interactions` (WhatsApp, chamada, ver e copiar telefone, formulário…) e `car_leads`.
- **A vista diária** `car_funnel_metrics_daily` dá, por veículo e por dia, as vistas, os contactos (WhatsApp, chamada, ver e copiar telefone) e as leads.
- **As interações não guardam a origem** (pago ou orgânico). As leads guardam (`channel`).
- **Em dev:**
  - há vistas e interações das empresas 2 e 3 até 9 de junho de 2026;
  - há 3 leads, todas da empresa 2;
  - **nas últimas 4 semanas não há nada**.
- **Dados pessoais:** as leads e as interações têm nomes, telefones e e-mails. A Bússola só lê contagens.

### 1.6 O motor de recomendações e as regras da 2D

- **O motor** (`app/Recommendations/RecommendationEngine.php`) corre as regras do ramo ligadas em cada empresa, calculadas na hora. O dashboard mostra as recomendações no **separador Stock** (`AutomotiveHubStock`, cartão "Recomendações").
- **As regras da "Fase 2D"** (`tests/Feature/AutomotiveRulesTest.php`):
  - **Vendido com anúncio ativo** (`SoldCarAdStillActiveRule`): vendido, com um anúncio com a etiqueta ativo ou com gasto depois da venda (30 dias).
  - **Gasto sem leads** (`SpendWithoutLeadRule`): gasto com etiqueta de pelo menos 30 euros em 14 dias e 0 leads pagas. Conta **leads pagas, não contactos**.
  - **Acima do mercado** (`PriceAboveMarketRule`): comparação exata, acima, diferença superior a 5%. Quando o veículo também está parado, junta-se com "Acima do mercado e parado".
  - **Muitas vistas e zero contactos** (`HighViewsNoContactsRule`): em 14 dias, vistas pelo menos no p75 do stock e pelo menos 30, e 0 contactos.
- **Também do motor:** o stock parado (`DeadStock`: no limiar ou acima, com uma lead ou menos), a pouca procura e o anúncio fraco.
- **O universo das regras:** só `status = active` (sem retomas). O hub conta `active`, `available_soon` e `reserved`.

### 1.7 IPS e desempenho por veículo

- **IPS** (`CarSalePotentialScoreService`):
  - de 0 a 100, com 7 fatores (preço face ao mercado, envolvimento, dias em stock, procura do segmento, anúncio, histórico do modelo, promoção);
  - histórico em `car_sale_potential_scores`, recalculado às 01:00.
  - A Bússola não o usa: é um resumo de sinais que ela já lê diretamente, e mostrá-lo seria repetir.
- **CarPerformance:** é o agregado diário por canal (`car_performance_metrics`). Não acrescenta nada à vista do funil, e em dev só vai até junho.

### 1.8 Meta e GA4 guardados

- **Meta:** os insights por conta e por anúncio estão vazios em dev. As ligações Meta das empresas 2 e 3 estão expiradas.
- **GA4:** não é guardado. É lido na hora (uma chamada externa), e não tem dados por veículo. **A Bússola não usa o GA4.**

### 1.9 Publicações ligadas a veículos

**Não existe ligação** entre as publicações da Linha Editorial e os veículos. Ver o ponto 3.8.

### 1.10 Fotografias do veículo

- **As tabelas:** `car_images` (com a ordem: a principal primeiro) e `car_external_images` (a empresa 3 só tem estas).
- **Nos Ficheiros:**
  - o catálogo regista cada `car_images` como ficheiro (`source = viatura`, pasta `viaturas:{id}`, que se chama "Veículos");
  - as imagens externas não entram.
- **Em dev:** o catálogo quase não correu (4 ficheiros).

---

## 2. A Bússola da restauração e o plano para a tornar comum

### 2.1 Como está construída

- **Página:** `GET /marketing/bussola` (`RestaurantCompassController`, `RestaurantCompassService`).
  - Devolve `enabled, company, locations, location_id, categories_pending, computed_at, data_until, top, plays, blocks, financial, can_act, can_act_reason, formats`.
  - Com `plays_only=1`, só as jogadas (para o dashboard).
- **Sinais:** são calculados por `RestaurantSignalService` e guardados em `restaurant_signals`.
  - Recálculo: de noite (quando o interruptor está ligado), na leitura se tiverem mais de 24 horas, e quando os dados mudam.
  - Só ficam os de confiança alta e média.
- **Jogadas:**
  - os sinais juntam-se em grupos: dias fracos a meio da semana; artigos da mesma categoria (o caso das sobremesas);
  - escolhe-se o melhor grupo de cada tipo e ficam no máximo 3, pela força (confiança, desvio, valor).
- **"O que fazer":**
  - **O quê:** o texto da IA `bussola_jogadas`, guardado em `restaurant_compass_texts`, ou um modelo de texto.
  - **Onde:** sempre Instagram e Facebook, com o formato recomendado.
  - **Quando:** calculado na leitura, nunca no passado.
- **A IA:** a função `bussola_jogadas` gera uma frase por jogada.
  - O prompt usa o perfil da marca: tom, público, palavras a usar e a evitar, temas a evitar, emojis, chamada à ação.
  - A frase tem no máximo 160 caracteres, sem números nem causas, e passa pelo verificador do português de Portugal.
  - Se falhar, fica o modelo de texto.
- **Ignorar e exclusão permanente:**
  - "Ignorar durante 4 semanas" é por sinal (`restaurant_signal_actions`, só de inserção);
  - "Não voltar a sugerir este artigo" é por artigo (`restaurant_excluded_items`).
  - As rotas são `/integrations/pingwin/signals/*`.
- **Sem Finanças:** `CompassFinancials::redact` percorre a resposta e anula todos os valores em euros (`*_cents`, `format: eur`, frases com euros). O ecrã mostra números fictícios desfocados e "Sem acesso aos valores financeiros".
- **Interruptor:**
  - é a coluna `companies.pingwin_item_sales_enabled`, que liga também a sincronização das vendas por artigo do PingWin;
  - muda-se só pelo comando `pingwin:item-sales-switch`;
  - nasce desligada.
- **Quem vê e quem age:**
  - **ver:** `bussola.ver` e o módulo `pingwin`;
  - **agir** (criar publicação, sugerir texto, ignorar): `bussola.criar` ou `bussola.editar`, a Linha Editorial ligada e a pessoa ser produtora (`can_act`);
  - a agência convidada vê, sem os euros.
- **Dashboard:** "As 3 jogadas da semana" no fim do separador "Marketing e resultados" do dashboard da restauração (`PingwinDashboard`).
- **Testes que têm de continuar verdes:**
  - `RestaurantCompassTest` (13), `RestaurantSignalsPanelTest` (7) e `RestaurantSignalsTest` (11);
  - os de acesso e da IA;
  - no frontend, `BussolaDashboardSummary.test.tsx` (7).

### 2.2 O plano para a tornar comum (Parte 2)

**[Omissão]** Para não mudar nada do que a restauração mostra:
- As rotas, as tabelas, o interruptor e as classes da restauração ficam onde estão, com os mesmos nomes públicos.
- O comum é extraído para um núcleo novo, e a restauração passa a usá-lo com o mesmo comportamento.
- Antes de mexer, um teste de caracterização grava as respostas da restauração (completa, sem os blocos, só jogadas, e sem Finanças) e confirma, depois da separação, que são iguais byte a byte.

**Backend (`App\Services\Compass\`):**
- `CompassProvider`: a interface de um ramo. Diz se a empresa tem o ramo (pelos módulos), se está ligado (o interruptor), e devolve a resposta da página.
- `CompassProviders`: escolhe o fornecedor pelos módulos da empresa. Com o PingWin é a restauração; com o stock é o stand. Com os dois, ver a pergunta 6.
- `CompassPlayChooser`: no máximo uma jogada por tipo e 3 no total, pela ordem que o fornecedor der.
- `CompassWhen`: a data de publicação (nunca no passado) e o texto ("Publicar hoje", "Publicar na terça, 13/10").
- `CompassWhere`: Instagram e Facebook, com o formato recomendado; o stand acrescenta "Anúncio" quando a jogada é de tráfego pago.
- `CompassTexts`: a frase da IA. Faz o enquadramento do prompt com o perfil da marca, o limite de caracteres, sem números nem causas, o verificador e o recurso ao modelo de texto.
- A restauração passa a usar estas peças, com o mesmo resultado.

**Frontend:**
- A página `/marketing/bussola` escolhe o fornecedor pelos módulos.
- O cartão das jogadas, o cartão de cada jogada, as janelas (criar publicação, sugerir texto, detalhe), o topo e o "Sem acesso aos valores financeiros" ficam comuns. Os botões de cada jogada passam a vir do fornecedor, cada um com a sua permissão.
- Os blocos da restauração ficam numa pasta própria.
- Os nomes das funções de `laravel_helper.ts` e os `data-testid` usados nos testes não mudam.

---

## 3. O fornecedor do stand

### 3.1 O universo

- **[Omissão]** Como o hub e o dashboard:
  - "em stock" é o stock próprio (`active`, `available_soon` e `reserved`, sem retomas);
  - "à venda" é só `active`.
- As jogadas de preço, de interesse, de palco e de stock parado só olham para veículos à venda. O "Parar o anúncio" olha também para os vendidos.
- Os tipos são os de `vehicle_type`.
  - O seletor tem "Todos", "Carros" (os `car`) e "Autocaravanas" (as `motorhome` e as `caravan`).
  - **[Omissão]** As caravanas vão com as autocaravanas, porque partilham o limiar de 120 dias; as motos só contam em "Todos". Ver a pergunta 3.

### 3.2 Os sinais e as jogadas

As jogadas estão por ordem de prioridade: primeiro o dinheiro a perder, depois a força do sinal.

| # | Jogada | Quando aparece (fonte) | Força (dentro do tipo) |
|---|---|---|---|
| 1 | **Parar o anúncio de [veículo]** | as regras da 2D: vendido com anúncio ativo ou gasto depois da venda (`SoldCarAdStillActiveRule`); gasto sem leads (`SpendWithoutLeadRule`) | o gasto em risco |
| 2 | **Rever o preço de [veículo]** | à venda, no limiar de dias do seu tipo ou acima (45 nos carros, 120 nas autocaravanas) **e** acima do mercado com comparação exata, confiança média ou alta e mais de 5% (a regra `PriceAboveMarketRule`) | a percentagem acima, depois os dias |
| 3 | **Renovar o stock parado** | dois ou mais veículos à venda no limiar ou acima, que não estejam na jogada 2 | o número de veículos e os dias |
| 4 | **Aproveitar o interesse em [veículo]** | muitas vistas e zero contactos (`HighViewsNoContactsRule`, 14 dias) | as vistas |
| 5 | **Dar palco a [veículo]** | preço alinhado ou abaixo do mercado (exata, confiança média ou alta), sem anúncio ativo nem gasto nos últimos 30 dias, e sem publicação nos últimos 30 dias (3.8) | abaixo antes de alinhado, depois a percentagem |

- **Agrupamento:** vários veículos com o mesmo sinal juntam-se numa só jogada, com a lista ("Rever o preço de 3 veículos").
  - A jogada 3 é sempre uma lista.
  - A jogada 1 junta o vendido com anúncio e o gasto sem leads.
- **Ordem:** fica no máximo uma jogada de cada tipo, e as 3 primeiras pela prioridade da tabela.
- **[Omissão] A ordem 1, 2, 3, 4, 5:**
  - o gasto que continua a sair é dinheiro a perder já;
  - o preço acima num veículo parado prende capital;
  - o stock parado prende capital;
  - o interesse sem contacto e o palco são oportunidades.
- **Confiança:** cada jogada mostra a confiança e a amostra.
  - preço: a do aggregate e o número de comparáveis;
  - interesse: as vistas e a janela;
  - stock parado e anúncio: alta, porque são factos.
  - Os sinais de confiança baixa não entram.
- **"Rever o preço":**
  - mostra os dias em stock, a percentagem acima e a mediana dos comparáveis, com o número de comparáveis;
  - **nunca sugere um preço** (o preço recomendado do mercado não é usado).

### 3.3 "O que fazer"

- **O quê:** a frase da IA `bussola_jogadas`.
  - Leva os dados do veículo (marca, modelo, ano, quilómetros, preço) e o perfil da marca da empresa (tom, palavras a usar e a evitar).
  - Sem números inventados nem causas, e com o verificador do português de Portugal.
  - Se falhar, fica um modelo de texto por tipo.
  - Nos testes e no ecrã de dev a IA é simulada.
  - **[Omissão]** A mesma função de IA `bussola_jogadas` da restauração; muda só a dica em Modelos de IA, que deixa de dizer "restauração".
- **Onde:**
  - Instagram e Facebook, com o formato recomendado (como na restauração);
  - "Parar o anúncio" e o tráfego pago: o anúncio (Gestor de Anúncios da Meta), porque a ligação só pode ler (pausar é à mão).
- **Quando:**
  - **[Omissão]** "Parar o anúncio": hoje;
  - as outras: amanhã, como na restauração;
  - nunca uma data passada (calculada na leitura).

### 3.4 Os botões

Cada botão só aparece a quem tem a permissão do destino.

| Botão | Jogadas | Permissão |
|---|---|---|
| Criar publicação | palco, interesse, stock parado | `bussola.criar`, mais a Linha Editorial e ser produtor (`can_act`, como na restauração) |
| Sugerir texto | palco, interesse, stock parado | o mesmo |
| Ver veículo | todas com um veículo | `automovel.ver` |
| Rever preço (abre a edição do veículo, `/cars/{id}`) | preço | `automovel.editar` |
| Ver detalhe (janela grande) | todas | quem vê a Bússola |

**"Criar publicação"** cria uma ideia na Linha Editorial com o texto. Leva as fotografias do veículo a partir dos Ficheiros, sem cópia:
- usa a mesma media do ficheiro, como "Escolher dos Ficheiros", com as primeiras 4 fotografias pela ordem do veículo;
- se a empresa não tiver os Ficheiros ligados, ou a pessoa não puder ver a pasta "Veículos", abre sem fotografias e diz para as escolher;
- antes de escolher, o registo dos Ficheiros corre para a empresa, só se estiver desatualizado.

### 3.5 O topo (só na página)

- **Os números:**
  - veículos em stock e o valor do stock (financeiro);
  - dias médios em stock;
  - a percentagem de veículos acima do mercado (sobre os que têm comparação exata e confiança suficiente, com o número);
  - o gasto em anúncios das últimas 4 semanas (financeiro);
  - o custo por contacto (financeiro).
- **[Omissão] O custo por contacto:** o gasto das 4 semanas a dividir pelos contactos dos veículos com gasto, nas mesmas 4 semanas. As interações não guardam a origem, por isso conta os contactos de todas as origens desses veículos. Ver a pergunta 2.
- **O contexto** é dito uma só vez, dentro do cartão branco: as datas das 4 semanas, a data dos dados e as notas (datas de entrada estimadas, veículos fora das comparações).

### 3.6 Os blocos

Cada bloco tem uma manchete descritiva.
- **Stock a envelhecer:**
  - as faixas de dias (as de `StockThresholds`) por tipo;
  - os 5 mais antigos;
  - manchete do tipo "X veículos passaram o limiar de dias do seu tipo".
- **Preço face ao mercado:**
  - abaixo, alinhado, acima (só comparação exata e confiança suficiente);
  - os 5 mais acima;
  - a nota dos que ficam fora (sem tipologia, sem comparáveis, comparação aproximada).
- **O que os anúncios estão a render:**
  - gasto, contactos e custo por contacto por veículo, nas 4 semanas (financeiro);
  - o gasto sem contactos.
- **Os mais procurados:** vistas e contactos, 4 semanas, os 5 primeiros.
- **Esquecidos:** à venda há mais de 30 dias, sem anúncio nem publicação nos últimos 30 dias.
- **O rodapé** fica dentro de um cartão branco: de onde vêm os números e a regra da comparação exata.

### 3.7 O dashboard do stand

- "As 3 jogadas da semana" fica no fim do separador "Marketing e resultados", com o seletor "Todos, Carros, Autocaravanas" no cabeçalho do cartão.
- Não repete os números do separador Stock.
- **[Omissão]** As Recomendações estão no separador **Stock**, não no de Marketing. Segue-se a instrução explícita ("no FIM do separador Marketing e resultados"); ver a pergunta 4.

### 3.8 "Sem publicação nos últimos 30 dias"

- **[Omissão]** Uma tabela nova e aditiva, `compass_post_subjects` (publicação e veículo), preenchida quando a publicação é criada a partir da Bússola.
- Assim, "publicação nos últimos 30 dias" conta as publicações criadas pela Bússola para esse veículo. As outras, criadas à mão na Linha Editorial, não sabem a que veículo se referem.
- Ver a pergunta 5.

### 3.9 Sem Finanças

Quem não tem `financas.ver` não recebe do servidor:
- o valor do stock;
- o gasto em anúncios;
- o custo por contacto (no topo, nos blocos e nas jogadas: o gasto em risco do "Parar o anúncio").

Os valores chegam vazios e marcados como escondidos; o ecrã mostra números fictícios desfocados e "Sem acesso aos valores financeiros". **O preço de cada veículo e a mediana dos comparáveis ficam visíveis**, porque são públicos.

**[Omissão]** Um filtro próprio do stand, com a lista explícita dos campos financeiros, em vez do filtro recursivo da restauração (que esconderia também os preços públicos).

### 3.10 Quem vê, e o interruptor

- **Quem vê:** as regras da restauração (`bussola.ver`, `bussola.criar`, `bussola.editar`).
  - Não exige a área automóvel, porque a Bússola não mostra dados pessoais; os botões que abrem essa área só aparecem a quem a tem.
  - A agência convidada vê, sem os euros.
- **O interruptor:**
  - **[Omissão]** uma coluna nova `companies.bussola_stand_enabled`, que nasce desligada em todas as empresas, e um comando do root (`bussola:stand-switch {empresa} {on|off}`), como o da restauração;
  - em dev, liga-se nas empresas de stand de teste;
  - a página só aparece nas empresas com o módulo `stock`.

### 3.11 As rotas

Ficam dentro de `ensure_module:stock`, com as permissões da área `bussola`:
- `GET {id}/marketing/bussola-stand` (com `plays_only` e `type`);
- `POST {id}/marketing/bussola-stand/post`;
- `POST {id}/marketing/bussola-stand/caption` e `GET {id}/marketing/bussola-stand/caption/{requestId}`;
- `POST {id}/marketing/bussola-stand/ignore`, `/restore` e `/exclude`.

As rotas da restauração não mudam. Na fotografia da ACL, só entram as rotas novas.

### 3.12 As tabelas novas (aditivas)

- `compass_signals`: o fornecedor, a empresa, a chave do sinal, o tipo, o veículo, os números, a confiança e a data do cálculo.
- `compass_signal_actions`: ignorar e restaurar, por chave, durante 4 semanas; só de inserção.
- `compass_excluded_subjects`: "Não voltar a sugerir este veículo", por fornecedor, tipo de sujeito e identificador. Pode voltar a incluir-se.
- `compass_texts`: as frases da IA, por fornecedor, empresa e jogada.
- `compass_post_subjects`: a publicação criada a partir da Bússola e o veículo.
- `companies.bussola_stand_enabled`.

### 3.13 O recálculo

Os sinais do stand recalculam-se:
- todas as noites, com uma tarefa interna `bussola-stand-nightly` às 05:20, só nas empresas com o interruptor ligado;
- na leitura, quando têm mais de 24 horas;
- quando o stock ou os preços mudam (o veículo é gravado);
- quando o mercado ou os anúncios mudam (um aggregate novo, a repartição do gasto refeita).

O recálculo vai para a fila, sem repetir em menos de um minuto. A IA é pedida depois de cada recálculo, como na restauração.

### 3.14 Fontes externas de contactos (para mais tarde)

Os contactos vêm de uma lista de fontes. Hoje há só uma, o site do stand (as interações e as leads). O Standvirtual entra mais tarde como outra fonte, sem mudar as jogadas. Fica o lugar, sem construir.

### 3.15 Dados pessoais

As respostas da Bússola levam só contagens. Nunca leem nem devolvem os nomes, os telefones ou os e-mails das leads e das interações; há um teste que o confirma.

---

## 4. O que o dev permite verificar

O dev não tem eventos do funil desde junho, nem gasto da Meta recente. Por isso, "Parar o anúncio", "Aproveitar o interesse" e o gasto do topo não aparecem com os dados de dev. A verificação no ecrã (Parte 4) usa uma empresa de demonstração claramente fictícia, apagada no fim.

## 5. Resumo das decisões por omissão

1. A Bússola usa as regras que já existem (2D, comparação exata, limiares), sem regras paralelas.
2. A restauração fica intacta. O comum vai para `App\Services\Compass`, e um teste de caracterização confirma as respostas byte a byte.
3. Em stock é o stock próprio (`active`, `available_soon`, `reserved`); à venda é só `active`.
4. As caravanas vão com as autocaravanas no seletor.
5. A ordem das jogadas é: anúncio, preço, stock parado, interesse, palco.
6. A mesma função de IA `bussola_jogadas`, com a dica atualizada.
7. "Parar o anúncio" é para hoje; as outras jogadas para amanhã.
8. O custo por contacto é o gasto a dividir pelos contactos (de todas as origens) dos veículos com gasto.
9. "Sem publicação" conta as publicações criadas pela Bússola (tabela `compass_post_subjects`).
10. "Criar publicação" leva as primeiras 4 fotografias do veículo, a partir dos Ficheiros.
11. Um filtro sem Finanças próprio do stand, com a lista dos campos financeiros.
12. O interruptor é uma coluna nova e um comando do root, desligado por omissão.
13. As jogadas ficam no fim do separador "Marketing e resultados" do dashboard.
14. A área de permissão `bussola` continua presa ao módulo `pingwin` no editor de perfis; ver a pergunta 7.
