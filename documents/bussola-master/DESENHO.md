# Bússola Master: Radar de oportunidade e imobiliário (R0, desenho)

Escrito a 12 de outubro de 2026, à noite, com leituras do código e da base de dados de dev e, pela exceção desta noite, com amostras reais dos feeds públicos (secção 3). Nenhuma outra chamada a serviços externos. As decisões técnicas por omissão estão marcadas **[Omissão]** e resumidas no fim; as perguntas de produto estão em `NOITE-PERGUNTAS.md`.

**Princípio:** a Bússola da restauração e a do stand não mudam. O Radar e o calendário são peças novas, com rotas, tabelas e componentes próprios, que a página da Bússola mostra ao lado do que já existe. Um teste de caracterização do stand (`StandCompassGoldenTest`, 8 respostas gravadas) junta-se ao da restauração (`RestaurantCompassGoldenTest`) antes de qualquer construção.

---

## 1. As três camadas e como se ligam

| Camada | O que é | De onde vem | Como chega à Linha Editorial |
|---|---|---|---|
| 1. Dados próprios | O que já existe: as vendas e as reservas (restauração), o stock, os preços e os anúncios (stand), e, de novo, a carteira de imóveis (imobiliário) | Os fornecedores da Bússola comum (`App\Services\Compass`) | As jogadas de sempre: "Criar publicação" cria uma ideia com texto opcional da IA |
| 2. Calendário | O que vem aí, com data marcada e fonte oficial confirmada | Uma lista em código com a fonte de cada data (secção 7), por ramo | "Reagir" cria uma ideia com a data como prazo, sem texto |
| 3. Radar | O que está a acontecer agora: tendências, notícias, avisos meteorológicos, decisões do BCE | A recolha de 15 em 15 minutos dos feeds públicos (secção 2) | "Reagir" cria uma ideia com o gancho, as fontes e o prazo, sem texto |

### 1.1 Onde aparece

- **Página da Bússola** (`/marketing/bussola`): um bloco "Radar" comum a todos os ramos, logo a seguir às jogadas da semana, com duas partes: "Está a subir" (camada 3) e "Vem aí" (camada 2). Numa empresa sem fornecedor da camada 1 (por exemplo, uma empresa de software como a XPLENDOR), a página mostra só o cabeçalho e o bloco "Radar".
- **Linha Editorial**: um painel "Agora" com os mesmos cartões, em versão curta, para reagir sem sair do calendário.
- **Administração › Radar** (só o root): tudo o que entrou, o que o filtro tirou e porquê, a relevância por ramo, os sinais por empresa, os custos de IA e o interruptor de cada empresa.

### 1.2 Como se liga à Bússola comum sem a mudar

**[Omissão]** O Radar tem rotas próprias (`/marketing/radar...`) e não entra nas respostas dos fornecedores. Assim:
- `GET /marketing/bussola` e `GET /marketing/bussola-stand` respondem exatamente como antes (os dois testes de caracterização confirmam byte a byte);
- o frontend compõe a página: o fornecedor de sempre, mais o cartão do Radar, que pede `GET /marketing/radar` por sua conta;
- o Radar segue as permissões da área `bussola` (`bussola.ver` para ver, `bussola.criar` para reagir, `bussola.editar` para ignorar, silenciar e mudar as definições).

### 1.3 O interruptor

**[Omissão]** O Radar é um módulo novo, `radar` (rótulo "Radar de oportunidade"), no registo central dos módulos (`ModuleRegistry`), fora de todos os presets: nasce desligado em todas as empresas e só o root o liga, no ecrã dos módulos da empresa que já existe (Administração › Empresas › Módulos) ou no painel Administração › Radar. Porquê um módulo e não uma coluna com um comando (como `bussola_stand_enabled`):
- o menu e a rota da Bússola passam a abrir também para as empresas que só têm o Radar (`anyModule: pingwin, stock, imoveis, radar`), sem código novo de gating;
- as rotas do Radar ficam dentro de `ensure_module:radar`, como todas as outras fronteiras de segurança do backend;
- na fotografia da ACL, a empresa sem módulos recebe 403 nas rotas novas, como nas da Bússola do stand.

Em dev, liga-se na XPLENDOR e nas empresas de demonstração (R4).

### 1.4 "Reagir": a ideia com prazo, sem texto

**[Omissão]** "Reagir" cria uma publicação na etapa **Ideia** da Linha Editorial, pelo mesmo caminho da Bússola do stand (`EditorialPostService::createPostRecord` com `stage = idea`), com:
- **título**: o gancho ("Está a subir: X"), que a pessoa pode mudar na janela antes de criar;
- **data de publicação**: o prazo (até quando a tendência vale, ou a data do calendário), nunca no passado, num mês aberto na Linha Editorial (como no stand: se o mês não estiver aberto, a resposta diz para o abrir);
- **redes e formato**: escolhidos na janela, como na Bússola;
- **sem texto**: nenhuma versão com legenda é criada; o "Sugerir texto" da Linha Editorial continua disponível, só a pedido, e passa a receber o gancho;
- **o gancho, as fontes e a janela**: a publicação não tem hoje nenhum campo de notas, ligações ou prazo (só o título, a data e o conteúdo nas versões). Uma migração aditiva dá-lhe cinco colunas, todas opcionais: `brief` (o gancho: "Está a subir X" e o "porque lhe interessa"), `sources` (a lista das fontes: título, nome, ligação e hora), `valid_until` (até quando vale), `origin` (`radar`, `calendario`) e `origin_ref` (a chave do sinal ou da data). O painel da publicação mostra-os no Planeamento, numa caixa "Gancho e fontes" só de leitura (as ligações abrem noutro separador), e o "Sugerir texto" junta o `brief` ao contexto da legenda. Nada do que já existe muda de forma: as publicações antigas ficam com as colunas vazias;
- **os avisos do cartão** (nome de pessoa, marca protegida) vão dentro do `brief`, numa linha própria;
- **a ligação**: `radar_signals.editorial_post_id` guarda a publicação, e o sinal passa a "reagido".

---

## 2. As fontes

Todas as fontes são lidas pelo servidor (nunca pelo browser do cliente), sem autenticação, sem cookies, com o User-Agent identificado `XPLENDOR-Radar/1.0 (leitura de feeds; contacto no site da XPLENDOR)`, com pedidos condicionais (`If-None-Match` e `If-Modified-Since` quando a fonte os devolve) e com o teto de 4 pedidos por fonte e por hora imposto no código (secção 4.1). Os termos de cada fonte foram lidos antes da primeira amostra, com no máximo 4 pedidos por domínio; o registo de todos os pedidos desta noite (hora, URL, código HTTP) está no relatório da manhã. Os veredictos são uma leitura técnica dos termos, não um parecer jurídico.

O que se guarda de qualquer fonte: o título, a ligação, o nome da fonte, a hora de publicação, a hora de leitura e o volume aproximado quando existe. Nunca o corpo do artigo, nunca a descrição, nunca imagens, nunca nomes de autores. Tudo apagado aos 30 dias.

| Fonte | Veredicto dos termos | Entra esta noite |
|---|---|---|
| Google Trends (tendências do momento, PT) | permitido com atribuição | sim |
| ECO, Público, Observador (RSS geral) | omissos (reserva genérica de direitos; feed público anunciado pelo próprio jornal) | sim, com autorização escrita a pedir antes do lançamento comercial (pergunta 14) |
| BCE (RSS de imprensa) | permitido com citação e a nota "disponível gratuitamente em ecb.europa.eu" | sim |
| IPMA (avisos e previsão) | cláusula de 2017 restringe a fins não lucrativos | não: fica no código desligada até confirmação escrita (pergunta 13) |
| INE (valor mediano por m²) | CC BY 4.0, uso comercial permitido, frase de atribuição obrigatória | sim, no R3 (referência do imobiliário; não é fonte do Radar) |
| Calendário do BCE (página oficial) | o mesmo aviso legal do BCE | sim, como lista em código (secção 7) |
| Agenda Fiscal da AT; Código do Trabalho (PDF do DRE); porto.pt | sem cláusula de leitura automática; datas estáveis em lei ou tradição municipal | sim, como lista em código (secção 7) |
| RTP, JN, DN, Jornal de Negócios, TSF, Expresso, SIC Notícias, Renascença, EMMI (Euribor), BPstat, lisboa.pt | ver 2.7 | não |

### 2.1 Google Trends, "Tendências do momento" (Portugal)

- **URL:** `https://trends.google.com/trending/rss?geo=PT`. **Formato:** RSS 2.0 com o espaço de nomes `ht`. Por item: `title` (a consulta, em minúsculas), `ht:approx_traffic` (escalões "100+", "200+", "500+", "1000+"), `pubDate` (fuso -0700, converter para Lisboa), 1 a 3 `ht:news_item` (título, URL e nome do órgão). O `link` do item aponta para o próprio feed: a ligação útil é a de cada notícia.
- **Frequência:** a ajuda oficial declara atualização em média a cada 10 minutos; o feed é uma janela deslizante com as 10 tendências mais recentes (na amostra, 90 minutos; cerca de uma tendência nova a cada 9 minutos). A leitura de 15 em 15 minutos chega em condições normais; em picos pode perder-se uma tendência entre leituras (aceita-se).
- **Termos:** permitido com condições. Os Termos de Utilização da Google (versão de 30 de julho de 2026) só proíbem o acesso automático que viole o `robots.txt`; o `robots.txt` de trends.google.com bloqueia apenas `/explore?` e `/trends/explore?`, não `/trending/rss`. A ajuda do Trends (artigo 4365538) diz: "You can use any information from Google Trends, subject to the Google Terms of Service. If you reuse Trends data, attribute the information to Google with a citation." O RSS é uma opção de exportação oficial (artigo 3076011). Os termos dizem ainda que o conteúdo de terceiros (um artigo de jornal) não se usa sem autorização "ou conforme permitido por lei". Frequência, limites de taxa e retenção: omissos.
- **O que se guarda e porquê:** a consulta (título do item), o volume aproximado, a hora de publicação e, por notícia associada, a ligação, o nome do órgão e o título. O título da notícia serve ao filtro de segurança e à relevância (a consulta sozinha, por exemplo "navio" ou "rússia", não chega para avaliar o tema) e **[Omissão]** não se mostra no cartão: o cartão mostra a consulta, o volume, a hora e as fontes com o nome e a ligação. As imagens (`ht:picture`) nunca se guardam.
- **Atribuição obrigatória:** "Fonte: Google Trends" junto de cada sinal desta fonte; nunca citado ao lado de dados do Google Ads.
- **Condição permanente:** o `robots.txt` de trends.google.com é relido uma vez por dia; se `/trending` passar a `Disallow`, a fonte não é lida e o painel do root mostra o motivo.

### 2.2 RSS de jornais portugueses

Onze jornais verificados; três entram, com os termos omissos quanto a feeds e leitura automática e com o feed público anunciado pelo próprio jornal:

| Jornal | URL do feed | Formato | Ritmo (segunda à tarde) | O que o feed traz a mais (descartado à entrada) |
|---|---|---|---|---|
| ECO | `https://eco.sapo.pt/feed/` | RSS 2.0 (WordPress) | cerca de 5 itens por hora | `description` e `content:encoded` com o artigo inteiro (164 kB por leitura), `dc:creator` |
| Público | `https://feeds.feedburner.com/PublicoRSS` | RSS 2.0, 10 itens | cerca de 7 por hora | `description`, `author` |
| Observador | `https://observador.pt/feed/` | RSS 2.0, 51 itens | cerca de 17 por hora (inclui rádio, noticiários horários e verificações de factos) | `description`, `media:content`, `dc:creator` |

- **Termos (citações):** Público: "Todos os conteúdos são protegidos por Direitos de Autor (...) não podendo ser utilizados fora das condições de uso livre permitidas por lei, sem o consentimento expresso e escrito"; Observador: "não pode ser utilizado sem o consentimento (...) exceto para os fins de utilização privada permitidos pela lei"; ECO: "não pode ser utilizado sem o consentimento prévio do ECO". Nenhum fala de feeds, agregação, ligações ou leitura automática; todos anunciam o feed no próprio sítio. A XPLENDOR só guarda e mostra o título com a ligação direta e o nome do jornal (a prática que a Diretiva (UE) 2019/790, artigo 15.º, deixa fora do direito dos editores: hiperligações e extratos muito curtos). **Antes do lançamento comercial pede-se autorização escrita aos três** (pergunta 14).
- **O que se guarda:** título, ligação, nome do jornal, hora de publicação e as categorias do item (nomes curtos, por exemplo "Economia", "Política": **[Omissão]** servem ao filtro, que trata uma categoria "Política", "Desporto", "Religião" ou "Justiça" como o motivo respetivo).
- **Atribuição:** o nome do jornal com a ligação direta ao artigo (abre noutro separador). Nunca o texto nem a descrição.
- **Fora, por agora:** RTP, JN, DN, Jornal de Negócios e TSF (os termos limitam a uso pessoal ou proíbem o uso comercial sem autorização escrita; a RTP permite ligações só à página principal), Expresso, SIC Notícias e Renascença (bloqueiam leitores automáticos na própria página de termos: 403). Ver 2.7.

### 2.3 IPMA, avisos meteorológicos e previsão por distrito

- **URLs:** `https://api.ipma.pt/open-data/forecast/warnings/warnings_www.json` (avisos até 3 dias: 25 áreas × 8 tipos, com `awarenessLevelID` green/yellow/orange/red, `startTime`, `endTime` e `text` só nos níveis amarelo a vermelho), `https://api.ipma.pt/open-data/forecast/meteorology/cities/daily/hp-daily-forecast-day{0,1,2}.json` (previsão agregada por capital de distrito, com `dataUpdate`) e `https://api.ipma.pt/open-data/distrits-islands.json` (35 locais com `idDistrito`, `idConcelho` e `idAreaAviso`; praticamente estática). **Formato:** JSON estático, sem parâmetros nem chave.
- **Frequência:** previsões duas vezes por dia (a partir das 10:00 e das 20:00), ficheiro regenerado de hora a hora; avisos sem periodicidade declarada. Horas em UTC segundo a documentação, sem fuso explícito nos campos.
- **Ligação à zona:** concelho da empresa → `idDistrito` → `idAreaAviso` → avisos da área; o aviso é sempre "para o distrito de X", a previsão "para X (capital de distrito)". Nunca "para o seu concelho".
- **Termos:** `https://api.ipma.pt/` (secção "termos e condições", Copyright IPMA 2017, sem licença nomeada). Obrigam a referir sempre a fonte, a mostrar o logótipo do IPMA com hiperligação para www.ipma.pt em páginas web, e pedem um email a webmaster@ipma.pt a informar do uso. A cláusula de direitos de autor diz: "O Utilizador pode copiar, importar ou utilizar gratuitamente informações ou símbolos nacionais existentes neste site para uso pessoal ou público desde que dessa utilização não decorram finalidades lucrativas ou ofensivas."
- **Veredicto: fica de fora até confirmação escrita do IPMA.** A XPLENDOR é um serviço pago; a cláusula de 2017 restringe o uso gratuito a fins sem finalidade lucrativa. O Regulamento de Execução (UE) 2023/138 (dados de elevado valor, categoria meteorológica, aplicável desde 9 de junho de 2024) aponta para reutilização livre, incluindo comercial, mas a sua aplicação formal pelo IPMA a estes ficheiros não foi confirmada esta noite. A fonte existe no código com o interruptor desligado (`enabled => false`), com o leitor testado por fixtures, para ligar no dia em que a resposta do IPMA chegar. Nesta noite só se fizeram as 4 leituras de avaliação (todas sem aviso ativo: 200 linhas "green"). Pergunta 13.
- **Atribuição quando ligar:** "Fonte: IPMA, Instituto Português do Mar e da Atmosfera (www.ipma.pt). Dados obtidos às HH:MM; última atualização indicada pelo IPMA: DD/MM/AAAA HH:MM.", com o logótipo e a hiperligação. O campo `text` do aviso mostra-se como citação do IPMA, sem reescrita.
- **O que se guardaria:** por aviso ativo (amarelo a vermelho): área, distrito, tipo, nível, início, fim e o texto do IPMA (é a redação oficial do aviso, não um artigo). Sem dados pessoais.

### 2.4 INE, a referência do imobiliário

- **Termos:** `https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_pufs_termos&contexto=up&xlang=pt`, lidos na íntegra: "A informação estatística disponível neste Portal é gratuita e pode ser usada e reutilizada pelos utilizadores de acordo com a licença Creative Commons CC BY Atribuição 4.0"; "O utilizador pode descarregar ou copiar informação estatística para uso pessoal ou comercial"; "Se a informação for modificada pelo utilizador, tal facto deve ser explicitamente mencionado." **Frase de atribuição exigida, tal e qual (o travessão é do INE e reproduz-se sem alteração):** "Fonte: Instituto Nacional de Estatística, IP – Portugal (identificação da informação estatística referida, período de referência da informação)". Pendente: a página da API diz ter "condições de utilização próprias" (`ine_api_v2`), não lida por limite de pedidos; é a primeira leitura da próxima janela.
- **Indicador:** o nome do pedido ("Valor mediano das vendas por m² de alojamentos familiares por Localização geográfica (Freguesia)") é a designação de 2017 a 2021, descontinuada. O sucessor é o **0012234**: "Valor mediano das vendas de alojamentos familiares nos últimos 12 meses (Metodologia 2022, euros por m²) por Localização geográfica (NUTS 2024) e Categoria do alojamento familiar; Trimestral". Último período disponível: 1.º trimestre de 2026 (publicado a 17 de julho de 2026). Geografia com 931 categorias: 308 municípios (código de 7 caracteres = NUTS III + DICO) e 584 freguesias (9 caracteres = NUTS III + **DICOFRE**), mas só nos municípios da área metropolitana do Porto, Grande Lisboa, Península de Setúbal, Algarve e municípios com mais de 100 mil habitantes (58 municípios). Categoria: `H1` Total, `H11` Novos, `H12` Existentes.
- **Chamadas (2 por trimestre):** `https://www.ine.pt/ine/json_indicador/pindicaMeta.jsp?varcd=0012234&lang=PT` (para ler o último período) e `https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0012234&Dim1=S5A20261&lang=PT` (573 kB; `Dim1` = `S5A` + ano + trimestre; nunca sem `Dim1`, por causa do limite de 40 000 células). A resposta: `Dados` indexado pela designação do período ("1.º Trimestre de 2026"), registos `{geocod, geodsg, dim_3, valor}`; 1 092 dos 2 793 registos vêm sem `valor` e com `sinal_conv` "-" (abaixo do limiar de difusão): guardar como "sem valor publicado", nunca zero.
- **Cobertura no 1.º trimestre de 2026 (Total):** 350 das 584 freguesias e 304 dos 308 municípios. Valores de controlo: Portugal 2 168, Lisboa 5 082, Porto 3 510, Cascais 4 687 euros por m² (batem certo com o destaque do INE de 17 de julho de 2026).
- **O que se guarda:** por geocódigo (freguesia e município): o nome, o código, o período, o valor Total em euros por m², a data de atualização do INE, a hora da leitura e a frase de atribuição. **[Omissão]** A ligação às freguesias da plataforma faz-se pelo nome normalizado dentro do concelho (a tabela `parishes` não tem o DICOFRE; os 6 últimos caracteres do geocódigo do INE são-no, o que permite acrescentar o código mais tarde); a comparação do preço só usa a freguesia (o município fica como nota), como o pedido manda; a maior parte do país ficará "sem referência da freguesia" (pergunta 16).
- **Atribuição no ecrã:** "Fonte: Instituto Nacional de Estatística, IP – Portugal, Estatísticas de preços da habitação ao nível local, valor mediano das vendas de alojamentos familiares nos últimos 12 meses (euros por m²), 1.º trimestre de 2026." Se a plataforma calcular algo a partir do valor (a diferença em percentagem), diz "cálculo da XPLENDOR a partir de dados do INE".
- **Calendário de difusão do INE:** existe em HTML (`ine_calendarios`), ICS (`http://www.ine.pt/ine/calendario_ics.jsp?lang=PT`) e RSS dos destaques (`https://www.ine.pt/ine/rssfeed_dst.jsp?lang=PT`); nenhum foi lido esta noite (limite de 4 pedidos gasto). A data da próxima publicação das estatísticas de preços da habitação (2.º trimestre de 2026) fica por confirmar; entra no calendário (secção 7) quando o ICS for lido.

### 2.5 BCE: comunicados e calendário; Euribor

- **Comunicados:** `https://www.ecb.europa.eu/rss/press.html`, RSS 2.0 com 15 itens (comunicados, discursos, entrevistas, atas), campos `title`, `link`, `guid`, `pubDate` (fuso +0200), `lastBuildDate`; as ligações trazem uma barra dupla depois do domínio e os títulos um espaço inicial (normalizar). Só entram os comunicados de decisão de política monetária: título a começar por "Monetary policy decisions" ou ligação com `/press/pr/date/AAAA/html/ecb.mp` (padrão a confirmar na primeira decisão apanhada, a 29 de outubro de 2026). A decisão sai às 14:15 CET (13:15 em Lisboa) no segundo dia da reunião; a conferência de imprensa às 14:45 CET.
- **Termos:** aviso legal do BCE (`https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html`): utilização livre da informação obtida diretamente do sítio, desde que reproduzida com exatidão e com o BCE citado como fonte; num produto pago, quem publica deve informar os compradores de que a informação está disponível gratuitamente no sítio do BCE; as ligações a partir de sítios comerciais abrem o sítio do BCE na janela inteira (sem iframes); se a informação for modificada, diz-se.
- **Atribuição:** "Fonte: Banco Central Europeu, comunicado de DD/MM/AAAA, HH:MM (hora de Lisboa); informação disponível gratuitamente em ecb.europa.eu", com a ligação a abrir noutro separador.
- **Calendário das reuniões:** a página oficial `https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html` foi lida diretamente esta noite (12 de outubro de 2026, 18:27 UTC) e confirma as reuniões de política monetária até dezembro de 2027 (secção 7). Não há ICS nem RSS oficial do calendário: fica em código, com leitura semestral da página por uma pessoa.
- **Euribor: fica de fora.** A EMMI é a única fonte oficial; os valores diários gratuitos têm 24 horas de atraso, exigem registo, cobrem 25 dias e "may solely be used for non-commercial purposes"; os termos do sítio proíbem "scraper, robot, bot, spider" e a redistribuição comercial exige licença. As médias mensais existem no BPstat do Banco de Portugal (API pública, séries 13168436, 13168438 e 13168437) e no portal de dados do BCE, mas são dados de terceiros ("provided by Refinitiv") que a política de reutilização do SEBC exclui sem autorização do originador. Até confirmação escrita do Banco de Portugal, a Euribor não aparece no Radar nem no imobiliário (pergunta 15).

### 2.6 Calendários oficiais (datas com fonte)

Fontes lidas esta noite, usadas na lista em código da secção 7:
- **Autoridade Tributária, Agenda Fiscal 2026:** `https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx` (HTML com dois PDF de nome fixo e dois quadros anuais; sem ICS nem RSS). As regras estáveis estão nos códigos: IMI, artigo 120.º do CIMI (maio; maio e novembro; maio, agosto e novembro, por escalões); IRS, artigo 60.º do CIRS (1 de abril a 30 de junho). O dia exato confirma-se todos os anos em janeiro na Agenda Fiscal.
- **Código do Trabalho, artigos 234.º e 235.º** (feriados obrigatórios e facultativos): o DRE é uma aplicação JavaScript ilegível por máquina; o PDF oficial da 1.ª série (`https://files.diariodarepublica.pt/1s/2009/02/03000/0092601029.pdf`, páginas 974 e 975) foi lido. Os feriados nacionais já existem como âncoras universais da Linha Editorial e não se repetem no Radar.
- **Município do Porto:** `https://www.porto.pt` (notícias; o corpo é renderizado no browser; o RSS por categoria existe mas o caminho público fica por confirmar). São João: noite de 23 de junho e feriado municipal a 24.
- **Município de Lisboa:** `lisboa.pt` e `cm-lisboa.pt` respondem 403 (desafio Cloudflare) a qualquer leitor automático; nem os termos foram legíveis. Santo António (12 e 13 de junho) fica de fora até confirmação humana (secção 7).
- **BCE:** o calendário oficial, lido (2.5).

### 2.7 As que ficam de fora e porquê

| Fonte | Porquê |
|---|---|
| RTP Notícias | termos: uso "exclusivamente para seu uso pessoal", "expressamente proibida (...) com fins comerciais", hiperligações só à página principal salvo autorização escrita |
| JN e TSF | termos: "expressamente interdita qualquer cópia, reprodução, difusão (...) ou qualquer outro uso, total ou parcial, comercial ou não comercial (...) salvo com autorização expressa" |
| DN | termos: "não pode copiar, modificar, distribuir ou utilizar o conteúdo para fins comerciais sem autorização prévia" |
| Jornal de Negócios | termos: "unicamente para as suas necessidades pessoais (...) a não realizar (...) uma exploração comercial" |
| Expresso, SIC Notícias | 403 (DataDome) na própria página de termos: não verificáveis por máquina |
| Renascença | 403 (WAF) na página de termos: não verificável |
| IPMA | cláusula de fins não lucrativos (2.3); no código, desligada |
| EMMI (Euribor diária) | só uso não comercial sem licença; proíbe leitores automáticos |
| BPstat e portal de dados do BCE (Euribor mensal) | dados de terceiros (EMMI, Refinitiv) sem autorização do originador; termos do BPstat não localizados |
| lisboa.pt, cm-lisboa.pt | 403 Cloudflare a leitores automáticos; confirmação humana anual |
| DRE (páginas) | aplicação JavaScript; só o PDF da 1.ª série é legível por máquina |
| INE: RSS, ICS e JSON dos destaques | não lidos esta noite (limite de pedidos); a ler na próxima janela |
| Dias sem fonte oficial (Dia do Pai, Dia dos Namorados, Black Friday, Halloween, São Martinho, Dia dos Avós) | ninguém os fixa em Portugal; não entram no calendário do Radar (alguns já são âncoras da Linha Editorial, onde a origem é outra) |

## 3. As amostras reais e o que mostram

Todas lidas a 12 de outubro de 2026 entre as 19:14 e as 19:28 (hora de Lisboa), uma segunda-feira ao fim da tarde. Os títulos com nomes de pessoas não se transcrevem; contam-se.

| Fonte | Itens | Janela da amostra | O que o filtro tiraria | Nomes de pessoas |
|---|---|---|---|---|
| Google Trends PT | 10 tendências, 26 notícias de 21 órgãos | 90 minutos | 3 por segurança (política e conflito 1, tragédia 1, vida pessoal 1) e mais 3 por desporto: 6 de 10 (60%) com o desporto desligado, 3 de 10 (30%) com o desporto ligado | 3 de 10 (um futebolista, um concorrente de telerrealidade, um músico) |
| Público | 10 | 1,3 horas | 5 de 10 (política 4, crime 1) | 3, todos em evento público |
| Observador | 51 | 2,9 horas | 33 de 51 (65%): política 22, desporto 6, tragédia e conflito 5 | 18, todos em contexto de política, desporto ou conflito |
| ECO | 22 | 3,9 horas | 7 de 22 (32%): política 7 | 3 (governantes em contexto público) |
| BCE (RSS) | 15 (comunicados, discursos, entrevistas, atas) | 8 dias | nenhum comunicado de decisão na amostra (a última decisão foi a 10 de setembro; a próxima é a 29 de outubro) | 0 |
| IPMA (avisos) | 200 linhas, todas "green" | 3 dias | nada a mostrar: sem avisos ativos no país | 0 |
| INE 0012234 | 2 793 registos (931 geografias × 3 categorias); 1 092 sem valor | 1.º trimestre de 2026 | não se aplica | 0 |
| Calendário do BCE | 10 reuniões de política monetária até dezembro de 2027 | 2026 e 2027 | não se aplica | 0 |

O que as amostras mostram:
- **O ruído do Trends** é sobretudo desporto (3 em 10), política e conflitos (2 em 10) e vida pessoal de figuras públicas (1 em 10). O que sobra com o desporto desligado são temas de economia, transportes e turismo locais ("thyssenkrupp" com um investimento em Gaia, "linha do douro" com obras, "navio" com uma escala no Funchal), que são exatamente o tipo de gancho que interessa a um restaurante, a um stand ou a um consultor. Uma das tendências mantidas (um músico que anunciou uma pausa e um concerto) tem nas manchetes a morte de dois amigos: o filtro lexical retirá-la-ia por "morte". Aceita-se: o filtro prefere tirar.
- **Os jornais** trazem uma proporção de política muito maior do que o Trends (Observador 22 em 51); por isso a tendência de jornais exige dois jornais diferentes com o mesmo assunto em 6 horas, o que elimina a maior parte dos títulos isolados, e o filtro tira a política antes de qualquer IA.
- **O volume por dia (estimativa):** Trends, cerca de 100 a 150 tendências novas; jornais, cerca de 300 a 400 títulos, de que resultam talvez 10 a 20 tendências com dois jornais; BCE, 8 comunicados de decisão por ano; IPMA, dezenas de avisos por mês no inverno, nenhum hoje. Depois do filtro, cerca de 50 tendências por dia chegam à relevância (secção 6.5).
- **Os nomes de pessoas** aparecem em 3 de 10 tendências do Trends e em 27 de 83 títulos de jornais: a regra "nome de pessoa só em evento público, nunca a vida pessoal" é indispensável e tem de ser conservadora.
- **A contagem real da noite** (o que entrou e o que o filtro tirou, por motivo, nas recolhas feitas à mão depois do R1) está no relatório da manhã, secção 3.

---

## 4. O modelo de dados dos sinais (comum às três camadas)

Todas as tabelas são novas e aditivas. Nenhuma tabela existente muda.

### 4.1 A recolha (camada 3)

- **`radar_runs`**: cada recolha, por fonte: `source_key`, `started_at`, `finished_at`, `status` (ok, erro), `http_status`, `items_seen`, `items_new`, `error` (até 500 caracteres). É o que o painel do root mostra.
- **`radar_items`**: cada item lido de um feed. Guarda **só** o que a regra permite: `title` (até 255), `url`, `source_name`, `published_at`, `fetched_at`, `approx_volume` (do Google Trends, quando vem) e `extra` (as notícias relacionadas de um item do Trends, só título, fonte e ligação; os códigos de distrito e a gravidade de um aviso do IPMA). Nunca o corpo do artigo. Mais: `external_key` (um hash da ligação, para não repetir), `filter_verdict` (passou, bloqueado), `filter_reasons` e `filter_flags` (secção 5), `topic_id`, `expires_at` (30 dias depois de `fetched_at`). Único por `(source_key, external_key)`.
- **`radar_topics`**: a tendência, que junta itens: `kind` (trend, news, weather, bce), `title`, `normalized_key`, `first_seen_at`, `last_seen_at`, `approx_volume`, `sources` (lista: título, fonte, ligação, hora), `districts` (avisos do IPMA), o veredicto e os motivos do filtro herdados dos itens, `window_until` (secção 6.4) e `expires_at`.
  - Um item do Google Trends é uma tendência por si (com o volume aproximado e as notícias que o próprio feed traz).
  - Itens de jornais juntam-se numa tendência quando dois ou mais jornais diferentes partilham, em 6 horas, duas ou mais palavras significativas (5 ou mais letras, sem as palavras vazias) no título **[Omissão]**. Um título num só jornal não é uma tendência.
  - Um aviso meteorológico do IPMA é uma tendência por distrito e nível (amarelo, laranja, vermelho).
  - Um comunicado do BCE sobre a decisão de política monetária é uma tendência no próprio dia.

### 4.2 A relevância e o encaixe

- **`radar_relevances`**: uma vez por tendência e por ramo: `topic_id`, `sector_slug`, `relevant`, `score` (0 a 100), `theme` (o tema que a IA atribui, para o "Não mostrar temas como este"), `reason` (uma frase descritiva, só para o painel do root), `model`, `effort`, `input_tokens`, `output_tokens`, `cost_usd`. Única por `(topic_id, sector_slug)`.
- **`radar_signals`**: o cartão de uma empresa: `company_id`, `topic_id`, `why` (o "porque lhe interessa"), `why_source` (ai, template), `window_until`, `status` (novo, reagido, ignorado, silenciado), `flags` (desporto, nome de pessoa, marca protegida), `sources` (a cópia das fontes no momento), `editorial_post_id`, `acted_at`, `acted_by_user_id`. No máximo 3 por empresa e por dia (contados em `created_at`, hora de Lisboa).
- **`radar_settings`**: por empresa: `sports_enabled`, `sports_clubs` (lista), `muted` (lista de temas silenciados: tema, termo, título da tendência, quando e por quem), `updated_by_user_id`. Sem linha, valem as omissões: desporto não, nada silenciado.

### 4.3 O calendário (camada 2)

**[Omissão]** Sem tabela: uma lista em código (`App\Services\Radar\Calendar\OfficialDates`) com, por data: o título, o ramo (ou todos), a data de início e de fim, a fonte oficial (nome e ligação), a data em que a fonte foi confirmada e o tipo (data fixa de um ano, regra anual). As datas vêm só das fontes confirmadas na secção 7. Os feriados nacionais que a restauração já calcula (`RestaurantSpecialDays::nationalHolidays`) não se repetem aqui. As âncoras da Linha Editorial (universais, por ramo e próprias) também não: continuam onde estão.

### 4.4 As funções de IA

- `radar_relevancia` (por omissão `claude-haiku-5-5`, esforço baixo): entra o ramo e um lote de tendências (título, tipo, títulos das fontes); sai, por tendência, se é relevante para o ramo, uma pontuação, um tema e uma frase descritiva.
- `radar_porque` (por omissão `claude-opus-5-5`, esforço médio): entra a tendência com as fontes, o ramo, a zona, o perfil da marca (tom, público, pilares, palavras a usar e a evitar, temas a evitar) e um resumo da camada 1 (3 a 5 factos); sai o "porque lhe interessa": até 240 caracteres, descritivo, sem causas, sem o texto da publicação, verificado pelo verificador do português de Portugal. Se falhar, fica um modelo de frase: "Está a subir em Portugal: X (fonte, hora). Encaixa no ramo da sua marca."
- As duas entram em `AiRequest::MODES` e em `config/ai.php` (com a dica para Modelos de IA). O catálogo de modelos ganha o `claude-haiku-5-5` (preços da referência da API: 0,10 USD por milhão de tokens de entrada e 0,50 de saída; sem preço de cache, que a XPLENDOR não usa). Como hoje não há modelo inicial por função (todas nascem com `ai.default_model`), cada função passa a poder declarar `default_model` em `config/ai.php`, lido por `AiFunctionSettings::for()` **[Omissão]**; o root muda depois em Modelos de IA, como nas outras.
- O teste às cegas compara sempre o Opus 5.5 com o GPT-6.1 Sol e rebenta numa função sem casos; as duas funções do Radar ficam fora dele (`'blind' => false`, filtrado no serviço e no ecrã) **[Omissão]**.
- **[Omissão]** A relevância é por ramo, não por empresa, por isso não tem `company_id`: não passa por `ai_requests` (que exige a empresa); o custo e os tokens ficam em `radar_relevances` e somam-se no painel do root (ver a pergunta 6). O "porque lhe interessa" é por empresa e passa por `ai_requests` como as outras funções, com o limite mensal por empresa `radar_porque` (100 pedidos; os 3 por dia dão 93 no máximo).
- **A simulação em dev e nos testes.** Nos testes, a IA é simulada como sempre (`Tests\Concerns\FakesAi`, respostas falsas dos dois fornecedores por `Http::fake`). Em dev, as chaves reais estão no `.env` e o worker corre a fila, por isso o Radar precisa de um interruptor para não gastar dinheiro durante a verificação no ecrã: `AI_FAKE=true` (nunca em produção: a opção só é lida fora de `production`) regista no contentor um fornecedor simulado que devolve respostas fixas e plausíveis por função, mantendo os registos, os custos simulados e o verificador do português **[Omissão]**.

---

## 5. O filtro de segurança (antes de qualquer IA; não se desliga)

Corre no momento em que o item entra (`RadarSafetyFilter`), só com regras, sem IA, sobre o título e os títulos das notícias relacionadas. Guarda o veredicto, os motivos e as marcas no item e na tendência. O painel do root mostra tudo o que foi tirado e porquê.

| Regra | Como se reconhece (listas de palavras em português, com e sem acentos, no título) | O que faz |
|---|---|---|
| Tragédias, mortes, acidentes | "morreu", "morte", "mortos", "faleceu", "funeral", "vítima", "tragédia", "acidente", "colisão", "despiste", "incêndio", "explosão", "naufrágio", "desabamento", "catástrofe"... | bloqueia (`tragedia`, `morte`, `acidente`) |
| Crimes | "crime", "homicídio", "assassinato", "violação", "agressão", "assalto", "roubo", "furto", "rapto", "sequestro", "tráfico", "detido", "condenado", "tribunal", "julgamento", "PJ", "GNR", "PSP"... | bloqueia (`crime`) |
| Doenças | "doença", "cancro", "surto", "epidemia", "pandemia", "vírus", "gripe", "covid", "internado", "hospital", "ébola", "sarampo"... | bloqueia (`doenca`) |
| Política | "governo", "ministro", "ministra", "primeiro-ministro", "presidente da república", "parlamento", "assembleia da república", "deputado", "eleições", "partido", "PS", "PSD", "Chega", "IL", "BE", "PCP", "Livre", "CDS", "moção", "orçamento do estado", "autárquicas", "legislativas", "presidenciais", "campanha eleitoral"... | bloqueia (`politica`) |
| Religião | "papa", "igreja", "missa", "fátima", "peregrinação", "bispo", "cardeal", "muçulman", "judaic", "evangélic"... | bloqueia (`religiao`) |
| Conflitos | "guerra", "ataque", "bombardeamento", "míssil", "drone militar", "exército", "invasão", "cessar-fogo", "refugiados", "terrorismo", "atentado", "Gaza", "Ucrânia", "Rússia", "Israel", "Irão", "Hamas", "Hezbollah"... | bloqueia (`conflito`) |
| Nomes de pessoas | Duas ou mais palavras seguidas com maiúscula inicial que não abrem a frase e não estão na lista de lugares, marcas, equipas e instituições conhecidas | marca `nome_pessoa`; **passa só** se o título tiver uma palavra de evento público ("jogo", "concerto", "estreia", "final", "espetáculo", "festival", "prémio", "gala", "cerimónia", "estádio", "digressão"); **bloqueia** se tiver uma palavra de vida pessoal ("casou", "casamento", "namor", "divórcio", "grávida", "filho", "filha", "bebé", "separação", "traição", "relação", "fotografias", "biquíni", "polémica"); sem nenhuma das duas, bloqueia (`vida_pessoal`) |
| Marcas protegidas | "Champions", "Liga dos Campeões", "Mundial", "Euro 20", "Jogos Olímpicos", "Olimpíadas", "UEFA", "FIFA", "Super Bowl", "NBA", "Fórmula 1", "Óscares", "Grammy", "Eurovisão"... | marca `marca_protegida` (passa; o cartão avisa para não usar nomes nem logótipos oficiais) |
| Desporto | "futebol", "jogo", "golo", "Benfica", "Sporting", "Porto", "Braga", "seleção", "liga", "taça", "treinador", "árbitro", "ténis", "ciclismo", "surf", "atletismo", "maratona", "F1", "MotoGP"... | marca `desporto` (passa; só chega às empresas com o desporto ligado e, se escolheram clubes, só às tendências que os nomeiam) |
| Temas a evitar do perfil da marca | As palavras de `topics_to_avoid` e `words_to_avoid` do perfil da marca de cada empresa | tira a tendência **dessa empresa** no encaixe (motivo `marca_empresa`); não é global |

- Um item bloqueado nunca chega à IA nem a nenhuma empresa. Uma tendência com um item bloqueado fica bloqueada.
- As listas vivem em código (`App\Services\Radar\Filter\Lexicon`), por motivo, e cada regra tem um teste com um título que a dispara e um que não.
- O filtro é deliberadamente conservador: na dúvida, bloqueia. O painel do root é a forma de ver o que ficou de fora; se um motivo tirar demasiado, ajusta-se a lista (não o princípio).
- **O cartão lembra sempre**, nas tendências com `nome_pessoa`, que não se usa a imagem nem o nome da pessoa para promover; e nas de `marca_protegida`, que não se usam os nomes nem os logótipos oficiais.

---

## 6. A relevância, o encaixe, o cartão e os custos

### 6.1 A relevância (uma vez por tendência e por ramo)

- Corre num trabalho da fila depois de cada recolha (`EvaluateRadarRelevanceJob`), só para as tendências novas que passaram o filtro e só para os ramos com pelo menos uma empresa com o Radar ligado.
- **[Omissão]** Em lote: um pedido por ramo com até 20 tendências, para o custo e o número de pedidos ficarem baixos. A resposta é JSON com um registo por tendência (a chave é o `id` da tendência).
- Fica em `radar_relevances`; uma tendência avaliada não volta a ser avaliada para esse ramo.

### 6.2 O encaixe na empresa

Num trabalho por empresa (`FitRadarSignalsJob`), depois da relevância:
1. as tendências relevantes para o ramo da empresa (`companies.content_sector_id`; sem ramo, não há Radar: ver a pergunta 1), ainda dentro da janela, sem sinal já criado para a empresa;
2. fora as de desporto (salvo com o desporto ligado; com clubes escolhidos, só as que nomeiam um dos clubes), as de temas silenciados (tema igual, ou o termo no título) e as que tocam nos temas e palavras a evitar do perfil da marca;
3. a ordem: a pontuação da relevância, mais 15 pontos se um aviso do IPMA for do distrito da empresa (ou de uma loja), mais 10 se o título tiver uma palavra dos pilares ou das palavras a usar da marca **[Omissão]**;
4. ficam as primeiras até perfazer 3 no dia (contando os sinais já criados hoje, incluindo os ignorados);
5. para cada uma, o "porque lhe interessa" pela função `radar_porque`, com a zona (distrito e concelho da empresa) e um resumo da camada 1 do fornecedor da empresa (por exemplo, no stand: "25 veículos em stock, 4 acima do mercado, 3 sem publicação há 30 dias"; na restauração: os 3 artigos mais vendidos e o dia fraco; no imobiliário: os imóveis em carteira e os parados). Sem IA, o modelo de frase.

### 6.3 O cartão

"**Está a subir: X**" com, por esta ordem:
- há quanto tempo ("há 2 horas"; de `first_seen_at`) e o volume aproximado ("mais de 20 mil pesquisas", do Trends; "em 3 jornais", das notícias; "aviso laranja no distrito de Lisboa", do IPMA);
- **porque lhe interessa** (a frase da IA ou o modelo);
- **vale até** (a janela, com dia e hora);
- **as fontes**, cada uma com o nome, a hora e a ligação (abre numa janela nova, `rel="noopener noreferrer"`);
- os avisos (nome de pessoa, marca protegida) quando existem;
- os botões: **Reagir** (`bussola.criar`), **Ignorar** e **Não mostrar temas como este** (`bussola.editar`). A fonte e a hora estão sempre visíveis; não há valores em euros; nunca uma causa.

### 6.4 "Até quando vale" (a janela) **[Omissão]**

| Tendência | Janela |
|---|---|
| Google Trends | 48 horas depois de ser vista pela primeira vez |
| Notícias (dois ou mais jornais) | 72 horas depois do primeiro título |
| Aviso do IPMA | até ao fim do aviso |
| Decisão do BCE | até às 23:59 do próprio dia (Lisboa) |
| Calendário | até à data (as de período, até ao último dia) |

O prazo da ideia é a janela (ou hoje, se a janela acabar hoje), nunca no passado (`CompassWhen`). Um sinal fora da janela deixa de aparecer, mesmo que ninguém lhe tenha tocado.

### 6.5 Os custos de IA por dia (estimativa)

Preços da referência da API (`config/ai.php`): Haiku 5.5 a 0,10 e 0,50 USD por milhão de tokens (entrada e saída); Opus 5.5 a 4 e 20.

| Função | Volume por dia | Tokens por pedido (entrada, saída com raciocínio) | Custo por pedido | Custo por dia |
|---|---|---|---|---|
| `radar_relevancia` | pelas amostras (secção 3): 100 a 150 tendências do Trends, 10 a 20 das notícias e alguns comunicados ou avisos por dia; cerca de 50 depois do filtro; em lotes de 20, por ramo (até 4 ramos): 12 pedidos | 2 500 e 800 | 0,0007 USD | 0,01 a 0,02 USD |
| `radar_porque` | até 3 por empresa | 1 800 e 1 000 | 0,027 USD | 0,08 USD por empresa |

- Com 20 empresas com o Radar ligado: cerca de 1,7 USD por dia; com 100: cerca de 8 USD. O limite de 3 sinais por empresa e por dia é o teto do custo por empresa (0,08 USD).
- O verificador do português pode repetir um pedido (o custo dobra nesse pedido).
- Os limites: o lote da relevância (20 por pedido), os 3 por empresa e por dia, e o limite mensal por empresa de `radar_porque` (100). As recolhas não custam IA.
- A calibração com IA real (o relatório da manhã, ponto 7) é que fixa os números.

---

## 7. O calendário por ramo (camada 2)

Só datas com fonte oficial lida esta noite. A lista vive em código (`App\Services\Radar\Calendar\OfficialDates`, secção 4.3), cada entrada com: a chave, o título, os ramos, a zona (quando é de um município), o tipo (data fixa de um ano, regra anual, regra legal), a data de início e de fim, a fonte (nome, ligação, formato), a data em que a fonte foi confirmada e quem confirmou (máquina ou pessoa), e o mês da verificação anual. O cartão "Vem aí" mostra as entradas dos próximos 30 dias do ramo e da zona da empresa; "Reagir" cria a ideia com a data como prazo.

| Entrada | Ramos | Datas | Fonte (lida a 12 de outubro de 2026) | Verificar |
|---|---|---|---|---|
| Decisão de política monetária do BCE (anúncio às 13:15, conferência às 13:45, hora de Lisboa) | imobiliário (pergunta 11) | 29 de outubro de 2026; 17 de dezembro de 2026; 4 de fevereiro, 18 de março, 29 de abril, 10 de junho, 22 de julho, 9 de setembro, 28 de outubro e 16 de dezembro de 2027 | página oficial do calendário do BCE, `https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html`, lida por máquina às 18:27 UTC | semestral (quando o BCE publica o ano seguinte) |
| IMI: fim do prazo de pagamento (até 100 euros, só maio; de 100 a 500, maio e novembro; acima de 500, maio, agosto e novembro) | imobiliário | 31 de maio, 31 de agosto e 30 de novembro de cada ano | Código do IMI, artigo 120.º; Agenda Fiscal da AT, `https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx` (página lida; os PDF do ano não) | janeiro (a Agenda Fiscal do ano) e depois de qualquer despacho de ajustamento |
| IRS: período de entrega da declaração **[Omissão]** (pergunta 17) | restauração, stand, imobiliário | 1 de abril a 30 de junho de cada ano | Código do IRS, artigo 60.º; Agenda Fiscal da AT (acima) | março |
| São João do Porto (noite de 23; feriado municipal a 24) | restauração, stand, imobiliário; só empresas do município do Porto | 23 e 24 de junho de cada ano | Município do Porto, `https://www.porto.pt` (notícia lida; o programa anual é da Ágora, agenda-porto.pt) | maio |

Ficam de fora, com o motivo:
- **Santo António e Festas de Lisboa (12 e 13 de junho):** `lisboa.pt` recusa leitores automáticos (403); entra quando uma pessoa confirmar a página oficial e registar a data da confirmação.
- **Publicações do INE** (por exemplo, as estatísticas de preços da habitação do 2.º trimestre de 2026): a data não foi confirmada esta noite; entra depois de ler o ICS do INE (uma leitura na próxima janela de pedidos), com a fonte e a hora.
- **Feriados nacionais, Carnaval, Páscoa, Dia da Mãe, mudança da hora, Santos Populares:** já são âncoras universais da Linha Editorial (migração `2026_10_20_000002_seed_editorial_line.php`); não se repetem.
- **IUC e inspeção periódica:** são regras por veículo (mês da matrícula), não datas de calendário; podem ser uma jogada do stand noutra noite.
- **Dia do Pai, Dia dos Namorados, Black Friday, Halloween, São Martinho, Dia dos Avós, 24 e 31 de dezembro, saldos:** sem fonte oficial em Portugal.

---

## 8. O imobiliário

### 8.1 O que se reaproveita do stand

O stand é um fornecedor da Bússola comum: dá os factos, os sinais e as jogadas, e o núcleo faz o resto. O imobiliário é **outro fornecedor**, construído ao lado, com a mesma forma e sem tocar em nada do stand (o teste de caracterização do stand garante-o):
- o núcleo comum, tal como está: `CompassProvider`, `CompassProviders` (entra um terceiro fornecedor na lista), `CompassPlayChooser` (uma jogada por tipo, 3 no total), `CompassWhen`, `CompassWhere`, `CompassTexts` (a função `bussola_jogadas`), `CompassAccess`;
- as tabelas comuns `compass_signals`, `compass_signal_actions`, `compass_excluded_subjects`, `compass_texts`, `compass_post_subjects` (com `provider = imobiliario` e `subject_type = property`), sem alterações;
- a forma da página (topo, jogadas, blocos, rodapé) e os componentes do frontend (o cartão das jogadas, as janelas, o "Sem acesso aos valores financeiros");
- a regra sem Finanças do stand, com a lista explícita dos campos financeiros (o valor da carteira; os preços dos imóveis e a referência do INE são públicos e ficam visíveis);
- as ações: criar publicação (uma ideia, ligada ao imóvel), sugerir texto, ignorar 4 semanas, "Não voltar a sugerir este imóvel".

### 8.2 O que é novo

- **O ramo** "Imobiliário" (`content_sectors`, folha de "Universal", selecionável) com o modelo do ramo no perfil da marca (`BrandProfileTemplates`): tom, público, pilares (a zona, os imóveis, o processo, a confiança), palavras a usar e a evitar, e temas a evitar.
- **O módulo** `imoveis` ("Imobiliário: Imóveis"), fora dos presets, **[Omissão]** com um preset novo `real_estate` (marketing, suporte, Linha Editorial e imóveis) para as empresas criadas com o ramo "Imobiliário".
- **A área de permissão** `imoveis` (ver, criar, editar, apagar), pelo padrão dos Ficheiros: os perfis de compatibilidade recebem-na em `CompatibilityProfiles::NEW_AREAS` (Administrador tudo; Utilizador ver, criar e editar; a agência convidada ver) **[Omissão]**; nenhuma rota existente muda.
- **A carteira** (`properties`): a referência, o título, o tipo (apartamento, moradia, terreno, loja, escritório, armazém, outro), a tipologia (T0 a T6), a área útil em m², a freguesia (e com ela o concelho e o distrito), venda ou arrendamento, o preço, a classe energética (A+ a F, isento, em processo, ou por preencher), o estado (disponível, reservado, fechado) com a data de entrada em carteira e a de fecho, e o interesse (secção 8.3). Uma página simples "Imóveis" (lista e formulário), só com os campos que a Bússola usa. Sem morada exata nem dados do proprietário.
- **O fornecedor** `ImoveisCompassProvider` (chave `imobiliario`, módulo `imoveis`; **[Omissão]** o interruptor é o próprio módulo, porque o módulo nasce desligado em todas as empresas).

### 8.3 As jogadas e de onde vêm os dados

| Jogada | Quando aparece | Dados |
|---|---|---|
| **Publicar o imóvel novo** | entrou em carteira há 7 dias ou menos e não tem publicação nos últimos 30 dias | `properties.listed_at`, `compass_post_subjects` |
| **Rever o preço do imóvel parado** | disponível há 90 dias ou mais (venda) ou 45 (arrendamento) **[Omissão]**; com a referência do INE da freguesia quando existe (o preço por m² face ao valor mediano), e sem ela diz só os dias | `listed_at`, `price`, `area_m2`, a referência do INE |
| **Explicar a decisão do BCE no próprio dia** | no dia de uma reunião de política monetária do BCE (calendário) e, se já houver comunicado no feed, com a ligação | a camada 2 e a 3 |
| **Mostrar a zona** | uma freguesia com imóveis em carteira e sem publicação sobre a zona nos últimos 30 dias; com a referência do INE da freguesia (descritiva: "o valor mediano das vendas na freguesia X foi Y euros por m² no trimestre T") | `parish_id`, a referência do INE |
| **Pedir o testemunho a quem fechou** | um imóvel fechado nos últimos 30 dias, sem publicação de testemunho | `closed_at`, `compass_post_subjects` |

- **A referência do INE**: a tabela `ine_housing_prices` (freguesia ou concelho, o nome, o período, o valor mediano em euros por m², a data da leitura e a ligação), carregada pelo comando `ine:housing-prices --execute` a partir da API do INE (secção 2), uma vez por trimestre (tarefa externa `ine-housing-prices-quarterly`). A comparação: o preço por m² do imóvel face ao valor mediano da freguesia; acima de 10% é "acima da referência", até 10% abaixo é "alinhado", abaixo disso é "abaixo" **[Omissão]** (uma faixa mais larga do que a dos carros, porque a mediana do INE é de todos os alojamentos da freguesia, não de comparáveis). Confiança: média com a freguesia; sem valor da freguesia, não se compara (o concelho fica só como nota). **Nunca sugere um preço.** Sem recolha de portais.
- **A ligação freguesia e INE**: a tabela `parishes` não tem o código DICOFRE; a ligação faz-se pelo nome normalizado da freguesia dentro do concelho **[Omissão]**; as que não casarem ficam "sem referência" e o painel do root lista-as.
- **O interesse**: não há site nem portal ligado; **[Omissão]** dois contadores no formulário do imóvel, que a pessoa atualiza ("contactos recebidos nos últimos 30 dias", "visitas marcadas"), com a data da última atualização visível. Ver a pergunta 3.
- **A classe energética**: a Linha Editorial não tem hoje avisos de conformidade; só a validação dos ficheiros (`media_validation`, erros e avisos, no separador Conteúdo). O aviso entra pelo mesmo caminho, numa chave própria `compliance` do detalhe da publicação, com avisos (nunca erros):
  - a publicação está ligada a um imóvel (`compass_post_subjects`, `subject_type = property`) sem classe energética na carteira (vazia ou "em processo"): "Falta a classe energética do imóvel: é obrigatória em qualquer anúncio de venda ou arrendamento (Decreto-Lei 101-D/2020, artigo 18.º)";
  - a publicação está ligada a um imóvel com classe, mas a legenda escrita não a menciona ("classe energética" seguida da classe): "A legenda não refere a classe energética (B) do imóvel: é obrigatória em qualquer anúncio (Decreto-Lei 101-D/2020, artigo 18.º)".
  O ecrã mostra-os no separador Conteúdo, ao lado dos avisos dos ficheiros, e no cabeçalho do painel. O aviso não bloqueia o envio **[Omissão]**.

### 8.4 Dados pessoais

A carteira não guarda proprietários, compradores nem inquilinos. O testemunho é pedido pela pessoa, fora da plataforma.

---

## 9. O que pode correr mal

| Risco | O que se faz |
|---|---|
| Uma fonte muda o formato ou deixa de responder | Cada fonte tem o seu leitor com testes de fixtures; um erro fica em `radar_runs` e no painel do root; as outras fontes continuam; a tarefa tem `withoutOverlapping` e tempo limite de 20 segundos por pedido. |
| Uma fonte muda os termos de uso | A lista de fontes está em código com a data da confirmação dos termos; o painel do root mostra-a; desligar uma fonte é uma constante. |
| Ruído: o Trends traz sobretudo desporto, política e entretenimento | O filtro tira o que não pode entrar; o desporto só entra a pedido; a relevância por ramo tira o resto; as amostras (secção 3) dão a medida. |
| O filtro deixa passar algo sensível | As listas são conservadoras e ampliáveis; o painel do root mostra tudo o que passou; a pessoa decide sempre (o Radar nunca publica). |
| O filtro tira demasiado (por exemplo, "Porto" como clube e como cidade) | Os motivos ficam registados; ajusta-se o léxico com os exemplos reais da noite; "Porto" só conta como desporto com outra palavra de futebol no título. |
| Nomes de pessoas por heurística | A heurística prefere bloquear; os falsos positivos ficam visíveis no painel. |
| A IA diz uma causa ou escreve o texto da publicação | O prompt proíbe; o verificador do português e a regra "sem causas" de `CompassTexts` rejeitam; fica o modelo de frase. |
| Um prazo no passado | `CompassWhen` e a validação ao criar a ideia. |
| O custo de IA sobe com as empresas | 3 por empresa e por dia, o limite mensal, a relevância por ramo e em lote; o painel do root soma os custos. |
| A tenancy | As rotas estão no grupo da empresa (`ensure_company`), as tabelas têm `company_id`, e os testes confirmam que uma empresa não vê os sinais de outra. |
| Dados pessoais nos feeds | Só o título, a ligação, a fonte, a hora e o volume; nunca o corpo; tudo apagado aos 30 dias (`radar:purge`, 03:20); os títulos com nomes de vida pessoal são bloqueados. |
| A recolha no dev faz chamadas reais | A tarefa é externa (`external_job_names`): desligada no dev por `SCHEDULE_EXTERNAL_JOBS`; os testes usam fixtures (`Http::fake`); a recolha à mão desta noite respeita 4 pedidos por fonte e por hora. |
| A referência do INE não casa com as freguesias | As que não casam ficam sem referência; a jogada do preço diz só os dias; o painel do root lista-as. |
| A troca de ramo de uma empresa | A relevância é por ramo: a empresa passa a receber as do ramo novo; os sinais antigos ficam até expirar. |

---

## 10. Resumo das decisões por omissão

1. O Radar tem rotas próprias e não entra nas respostas dos fornecedores; os dois testes de caracterização ficam verdes.
2. O interruptor do Radar é o módulo `radar` (fora dos presets; só o root o liga).
3. "Reagir" cria uma ideia (etapa Ideia) com o título, a data e as redes; o gancho, as fontes, a janela e a origem vão em cinco colunas novas e opcionais de `editorial_posts` (`brief`, `sources`, `valid_until`, `origin`, `origin_ref`), mostradas no Planeamento e usadas pelo "Sugerir texto"; sem texto.
4. As notícias só formam uma tendência com dois ou mais jornais em 6 horas; um título isolado não.
5. As janelas: 48 horas (Trends), 72 (notícias), até ao fim do aviso (IPMA), o próprio dia (BCE), até à data (calendário).
6. A relevância é por ramo, em lotes de 20, e o seu custo fica em `radar_relevances` (sem empresa); o "porque lhe interessa" passa por `ai_requests` com o limite mensal de 100 por empresa.
7. O encaixe ordena pela pontuação, com 15 pontos pela zona (avisos do IPMA no distrito) e 10 pelos pilares e palavras a usar.
8. O calendário é uma lista em código com a fonte e a data de confirmação; sem tabela.
9. O imobiliário: o ramo `imobiliario`, o módulo `imoveis` com o preset `real_estate`, a área de permissão `imoveis` pelo padrão dos Ficheiros, o interruptor é o módulo.
10. Os limiares do imobiliário: parado a partir de 90 dias (venda) e 45 (arrendamento); a referência do INE com uma faixa de 10%; só a freguesia conta.
11. A ligação freguesia e INE pelo nome normalizado dentro do concelho.
12. O interesse dos imóveis são dois contadores manuais com a data da atualização.
13. O aviso da classe energética aparece no painel da publicação e não bloqueia.
14. As fontes desta noite: Google Trends, ECO, Público, Observador e o RSS do BCE entram; o IPMA fica no código desligado até confirmação escrita; a Euribor (EMMI, BPstat) fica de fora; os títulos das notícias do Trends guardam-se para o filtro e a relevância mas não se mostram; as categorias dos itens dos jornais guardam-se para o filtro.
15. O calendário desta noite tem quatro entradas (BCE, IMI, IRS, São João do Porto); Santo António de Lisboa e as publicações do INE esperam confirmação; as datas já cobertas pelas âncoras universais não se repetem.
16. A referência do INE vem do indicador 0012234 (sucessor do indicador pedido), por freguesia onde o INE a publica e por município nos restantes casos, mas a comparação do preço só usa a freguesia.
17. O "Vem aí" mostra as entradas dos próximos 30 dias do ramo e, nas entradas municipais, só às empresas desse município.
