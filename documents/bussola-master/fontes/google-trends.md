# Fonte: Google Trends, "Tendências do momento", Portugal (geo=PT)

Radar XPLENDOR, ronda r0. Avaliação feita em 2026-10-12 (18:13 a 18:20 UTC; 19:13 a 19:20 em Lisboa).
Fim avaliado: leitura automática do feed RSS de 15 em 15 minutos; guardar apenas título, ligação, nome da fonte, hora e volume aproximado durante 30 dias; mostrar aos clientes "está a subir X" com ligação para a fonte; nunca republicar o conteúdo.

## 1. Veredicto resumido

Permitido com condições. Os Termos de Utilização da Google só proíbem o acesso por meios automáticos quando este viola as instruções legíveis por computador (robots.txt); o robots.txt de trends.google.com não bloqueia o caminho /trending/rss. A ajuda oficial do Google Trends diz que se pode usar qualquer informação do Google Trends, sujeita aos Termos, desde que se atribua a informação à Google com uma citação. O feed RSS é uma opção de exportação oficial da página "Tendências do momento". Condições a cumprir: citar "Google Trends" como fonte, respeitar o robots.txt (e verificá-lo periodicamente), não copiar nem redistribuir o serviço, e não reutilizar o conteúdo de terceiros (títulos e textos dos jornais) para além de uma ligação com indicação da fonte.

Os Termos são omissos quanto a frequência de leitura, limites de taxa ou licença específica para o RSS. Classificação dessas matérias: omisso.

## 2. Registo de pedidos HTTP (limite rígido: 4 por domínio)

Todos com curl, sem cookies, sem autenticação, User-Agent "XPLENDOR-Radar/0.1 (leitura de feed; contacto no site da XPLENDOR)". Nenhum redirecionamento ocorreu (num_redirects = 0 em todos).

| N.º | Hora (UTC) | Método | URL | Código HTTP | Redir. | Bytes |
|---|---|---|---|---|---|---|
| 1 | 2026-10-12T18:14:20Z | GET | https://policies.google.com/terms?hl=pt-PT | 200 | 0 | 616 871 |
| 2 | 2026-10-12T18:14:50Z | GET | https://support.google.com/trends/answer/3076011?hl=en | 200 | 0 | 1 519 373 |
| 3 | 2026-10-12T18:14:51Z | GET | https://support.google.com/trends/answer/4365538?hl=en | 200 | 0 | 1 519 894 |
| 4 | 2026-10-12T18:15:36Z | GET | https://trends.google.com/robots.txt | 200 | 0 | 93 |
| 5 | 2026-10-12T18:16:14Z | GET | https://trends.google.com/trending/rss?geo=PT | 200 | 0 | 19 683 (text/xml; charset=utf-8) |

Contagem por domínio: policies.google.com 1 de 4; support.google.com 2 de 4; trends.google.com 2 de 4. O URL alternativo (https://trends.google.com/trends/trendingsearches/daily/rss?geo=PT) não foi pedido porque o primeiro funcionou. Houve ainda uma pesquisa web (WebSearch) para localizar os artigos de ajuda; não toca na fonte.

Ordem cumprida: termos (pedidos 1 a 4) antes da amostra (pedido 5).

## 3. Termos de uso: o que a fonte diz

### 3.1 Termos de Utilização da Google (pt-PT)

URL: https://policies.google.com/terms?hl=pt-PT. Versão "Em vigor a 30 de julho de 2026".

Secção "Não utilize os nossos serviços de forma abusiva". A lista de ações proibidas inclui, citação exata:

> "Utilizar meios automáticos para aceder a conteúdo de qualquer um dos nossos serviços de uma forma que viole as instruções legíveis por computador nas nossas páginas Web (por exemplo, ficheiros robots.txt que não permitam o rastreio, a preparação ou outras atividades)"

Leitura: o acesso automático não é proibido em si; é proibido quando viola o robots.txt ou instruções equivalentes. Daí a verificação do robots.txt em 3.2.

Secção "Conteúdo da Google", citação exata:

> "Pode utilizar o conteúdo da Google conforme autorizado pelos presentes termos e por quaisquer termos adicionais específicos de serviços, mas a Google detém todos os direitos de propriedade intelectual sobre o respetivo conteúdo. Não pode remover, ocultar ou alterar quaisquer marcas, logótipos ou avisos legais da Google."

Secção "Outro conteúdo", citação exata:

> "alguns dos nossos serviços concedem-lhe acesso a conteúdo que pertence a outras pessoas ou entidades, como a descrição do proprietário de uma loja do seu próprio negócio ou um artigo de jornal apresentado no Google Notícias. Não pode utilizar este conteúdo sem autorização dessa pessoa ou entidade, ou conforme permitido por lei."

Secção "Software em serviços Google", citação exata:

> "Não pode copiar, modificar, distribuir, vender ou alugar nenhuma parte dos nossos serviços ou software."

Leitura: as duas últimas cláusulas limitam o que se faz com o conteúdo, não a leitura. O plano da XPLENDOR (guardar o título da tendência, a ligação, o nome da fonte, a hora e o volume; mostrar "está a subir X" com ligação; nunca republicar) mantém-se do lado permitido, desde que os títulos das notícias dos jornais não sejam reproduzidos como conteúdo próprio. Nota, sem valor de parecer jurídico: a ligação com nome da fonte é a prática que a Diretiva (UE) 2019/790 (artigo 15.º) exclui do direito conexo dos editores de imprensa, que apenas não cobre hiperligações e "extratos muito curtos".

### 3.2 robots.txt de trends.google.com

URL: https://trends.google.com/robots.txt. Conteúdo integral (93 bytes):

```
# trends.google.com/robots.txt

User-agent: *
Disallow: /explore?
Disallow: /trends/explore?
```

Leitura: só a página "Explorar" (com parâmetros) está bloqueada. O caminho /trending/rss?geo=PT não está bloqueado, logo a leitura automática do RSS não viola a cláusula de 3.1.

### 3.3 Ajuda do Google Trends: "Export, embed, and cite Trends data"

URL: https://support.google.com/trends/answer/4365538?hl=en. Secção "How to use and cite Trends data", citação exata:

> "You can use any information from Google Trends, subject to the Google Terms of Service. If you reuse Trends data, attribute the information to Google with a citation."
> "Example: To use a screenshot of Trends data, add "Data source: Google Trends (https://www.google.com/trends).""
> "Note: Google Trends is a different data source from Google Ads. Please do not cite these sources together."

Tradução de trabalho: "Pode utilizar qualquer informação do Google Trends, sujeito aos Termos de Utilização da Google. Se reutilizar dados do Trends, atribua a informação à Google com uma citação."

Leitura: autorização expressa de reutilização, com obrigação de atribuição. A XPLENDOR deve mostrar "Fonte: Google Trends" junto do sinal "está a subir".

### 3.4 Ajuda do Google Trends: "Explore the searches that are Trending now"

URL: https://support.google.com/trends/answer/3076011?hl=en. Citações exatas:

> "See fresh trends data, being refreshed on average every ten minutes."
> "Export: To select from data export methods below, click Export. Copy to clipboard / Download CSV / RSS feed"
> "Search volume: Bucketed traffic for the trend within the selected timeframe, with % growth vs. predicted baseline."
> ""Trending now" displays Google Search queries that are experiencing a recent surge in search interest among all recent searches and that are related to a news story (sometimes referred to as a grouping, or a cluster, and can be about a person, event or other newsworthy story)."

Leitura: o RSS é um método de exportação oficial e documentado; os dados são atualizados em média a cada dez minutos; o volume é apresentado por escalões ("bucketed"). A ajuda não descreve a estrutura do RSS, nem limites de pedidos, nem condições específicas para o RSS: omisso.

### 3.5 Classificação final dos termos

- Leitura automática do RSS: permitida (cláusula de meios automáticos condicionada ao robots.txt; robots.txt não bloqueia).
- Reutilização dos dados do Trends (título da tendência, volume, hora): permitida com atribuição à Google.
- Conteúdo de terceiros (títulos, trechos, imagens das notícias): não reutilizar como conteúdo próprio; ligação com nome da fonte é o limite seguro. As imagens (ht:picture, encrypted-tbn0.gstatic.com) não devem ser guardadas nem exibidas.
- Frequência, limites de taxa, licença específica, retenção de 30 dias: omisso.
- Licença exigida: não. Proibição de leitura automática: não.

## 4. A amostra

- URL: https://trends.google.com/trending/rss?geo=PT (pedido 5, 2026-10-12T18:16:14Z, HTTP 200).
- Ficheiro: (scratchpad)/radar/amostras/google-trends/trending-rss-PT-2026-10-12.xml (19 683 bytes).
- Formato: RSS 2.0, UTF-8, com dois espaços de nomes: atom (http://www.w3.org/2005/Atom) e ht (https://trends.google.com/trending/rss).
- Canal: title "Daily Search Trends"; description "Recent searches"; link e atom:link apontam para o próprio feed. Sem lastBuildDate, sem ttl, sem language.
- Número de itens: 10.

Campos por item (presentes nos 10 itens, salvo indicação):

| Campo | Presente | Observações |
|---|---|---|
| title | 10/10 | A consulta de pesquisa em minúsculas (por exemplo "navio", "linha do douro"). |
| ht:approx_traffic | 10/10 | Volume aproximado por escalão: "100+" (5 itens), "200+" (1), "500+" (3), "1000+" (1). Vem no feed. |
| description | 10/10 | Sempre vazio. |
| link | 10/10 | Aponta sempre para o próprio feed (https://trends.google.com/trending/rss?geo=PT), não para uma página da tendência. A "ligação para a fonte" útil é a ht:news_item_url. |
| pubDate | 10/10 | RFC 822 com fuso -0700 (hora do Pacífico). Valores em múltiplos de 10 minutos. Converter para Europe/Lisbon. |
| ht:picture | 10/10 | URL de miniatura em encrypted-tbn0.gstatic.com; num item vem vazio. |
| ht:picture_source | 10/10 | Nome do órgão de onde vem a imagem. |
| ht:news_item | 26 no total | 1 a 3 por item (média 2,6). Subcampos: ht:news_item_title, ht:news_item_snippet (sempre vazio), ht:news_item_url, ht:news_item_picture, ht:news_item_source. |

Janela temporal: pubDate mais antigo 2026-10-12 09:30 -0700 (17:30 em Lisboa), mais recente 11:00 -0700 (19:00 em Lisboa); amplitude 1h30. O item mais recente antecedia a leitura em cerca de 16 minutos. O feed parece ser uma janela deslizante com as 10 tendências mais recentes, não um histórico do dia.

Fontes de notícias (21 distintas em 26 referências): SAPO (4), Expresso (2), TVI (2) e, com uma referência cada, Público, RTP, Jornal de Negócios, Diário de Notícias, Novum Canal, Canal N, A Bola, CNN Portugal, JM Madeira, dnoticias.pt, Jornal de Notícias, mais fontes estrangeiras (ANSA, Touchpoint News, sítio oficial de um clube italiano, CNN, The Times of Israel, Breakingthenews.net) e um blogue de televisão. Em 10 itens, 2 têm apenas fontes estrangeiras.

## 5. Classificação dos itens (sem nomes de pessoas)

| N.º | Tendência (ou descrição) | Volume | Categoria | Filtro |
|---|---|---|---|---|
| 1 | "rússia": declarações de chefes de Estado sobre um cessar-fogo energético na guerra da Ucrânia | 1000+ | política (sobrepõe-se a conflito) | retira |
| 2 | "benfica b": jogo de futebol da equipa B | 100+ | desporto | só a pedido |
| 3 | "thyssenkrupp": investimento industrial em Gaia (hidrogénio verde) | 100+ | economia ou consumo | mantém |
| 4 | "linha do douro": suspensão da circulação ferroviária para obras | 100+ | outro (transportes e infraestrutura) | mantém |
| 5 | "atalanta - venezia": jogo de futebol italiano | 100+ | desporto | só a pedido |
| 6 | nome de um futebolista português a jogar em Itália; desempenho num jogo | 100+ | desporto (nome de pessoa, evento público) | só a pedido |
| 7 | "israel": explosão que matou um adolescente numa visita escolar | 500+ | tragédia, morte, acidente, crime, doença ou conflito | retira |
| 8 | "navio": escala de um navio de expedição no Funchal | 500+ | outro (turismo e eventos locais) | mantém |
| 9 | nome de um concorrente de um programa de telerrealidade; revelações sobre a vida e profissão | 200+ | nome de pessoa (vida pessoal) | retira |
| 10 | nome de um músico português; anúncio de pausa na carreira e concerto, motivado pela morte de dois amigos | 500+ | nome de pessoa (evento público) | mantém, com reserva |

Reserva no item 10: as manchetes mencionam a morte de amigos do artista; um filtro lexical por "morte" retirá-lo-ia. Reserva no item 6: é nome de pessoa num contexto desportivo público; contou como desporto porque a regra "desporto só a pedido" é a mais conservadora.

## 6. Contagem por categoria e fração retirada

| Categoria | Itens |
|---|---|
| desporto | 3 |
| política | 1 |
| tragédia, morte, acidente, crime, doença ou conflito | 1 |
| religião | 0 |
| nome de pessoa (vida pessoal) | 1 |
| nome de pessoa (evento público) | 1 |
| entretenimento ou cultura | 0 |
| economia ou consumo | 1 |
| meteorologia | 0 |
| tecnologia | 0 |
| outro | 2 |
| Total | 10 |

Filtro de segurança (mantém entretenimento, economia, meteorologia, tecnologia, evento público e outro; desporto só a pedido):
- Retirados por segurança (política, tragédia/conflito, vida pessoal): 3 de 10 (30%).
- Retirados por desporto não pedido: 3 de 10 (30%).
- Total retirado com desporto desligado: 6 de 10 (60%). Mantidos: 4 de 10 (40%).
- Com desporto ligado a pedido do cliente: retirados 3 de 10 (30%), mantidos 7 de 10 (70%).

Estimativa de ruído: cerca de 30% de sinal inutilizável por segurança e 30% adicional de desporto, numa amostra de 10 itens a uma tarde de segunda-feira. Amostra pequena; a proporção varia com o dia (jornadas de futebol, acontecimentos internacionais). Note-se que 3 das 10 tendências são nomes de pessoas, o que confirma a necessidade de um classificador de "nome de pessoa" antes da exibição.

## 7. Frequência de atualização e volume

- A ajuda oficial declara atualização "em média a cada dez minutos".
- Na amostra, os pubDate caem em múltiplos de 10 minutos (09:30, 09:40, 09:40, 09:50, 10:20, 10:30, 10:40, 10:50, 11:00, 11:00 -0700), o que é consistente com ciclos de 10 minutos.
- 10 itens cobrem 90 minutos, isto é, cerca de 1 tendência nova a cada 9 minutos nesta amostra. Com leitura de 15 em 15 minutos, a janela de 10 itens chega em condições normais, mas em períodos de muitas tendências (jogos, eleições, catástrofes) podem perder-se itens entre leituras. Opção: ler de 10 em 10 minutos, ou aceitar a perda ocasional.
- O volume aproximado vem no feed (ht:approx_traffic), por escalões "100+", "200+", "500+", "1000+", sem valor exato nem percentagem de crescimento (a percentagem existe na página, não no RSS).
- O feed não traz cabeçalhos próprios de cache no corpo (sem ttl nem lastBuildDate); os cabeçalhos HTTP de resposta não foram guardados nesta leitura.

## 8. Recomendações para a implementação

1. Fonte do item: usar ht:news_item_url e ht:news_item_source como "ligação para a fonte" (o campo link do item aponta para o próprio feed).
2. Guardar: title (consulta), ht:approx_traffic, pubDate convertido para UTC/Lisboa, hora de leitura, e, por notícia, apenas URL e nome da fonte. Não guardar ht:news_item_title como texto exibível (conteúdo de terceiros), nem imagens.
3. Desduplicar por (title, pubDate); a mesma tendência reaparece em leituras sucessivas.
4. Mostrar "Fonte: Google Trends" junto de cada sinal (atribuição exigida pela ajuda do Trends); nunca citar juntamente com dados do Google Ads.
5. Voltar a ler https://trends.google.com/robots.txt periodicamente (por exemplo, uma vez por dia) e suspender a leitura se /trending passar a Disallow; é a condição dos Termos.
6. Manter User-Agent identificável com contacto; sem cookies nem autenticação.
7. Classificador de categoria e de nome de pessoa antes da exibição; o filtro retirou 60% nesta amostra com desporto desligado.
8. Retenção de 30 dias: omissa nos termos; sem obstáculo identificado para metadados sem conteúdo de terceiros.

## 9. Ficheiros guardados (todos no scratchpad)

- Amostra RSS: .../scratchpad/radar/amostras/google-trends/trending-rss-PT-2026-10-12.xml
- Termos (HTML e texto extraído): .../scratchpad/radar/termos/policies-terms-pt-PT.html e .txt
- Ajuda 3076011 (HTML e texto): .../scratchpad/radar/termos/support-trends-3076011-en.html e .txt
- Ajuda 4365538 (HTML e texto): .../scratchpad/radar/termos/support-trends-4365538-en.html e .txt
- robots.txt: .../scratchpad/radar/termos/trends-robots.txt
- Scripts de análise: .../scratchpad/radar/scripts/html2txt.py, rss_analyse.py, rss_titles.py
- Prefixo de caminho: /private/tmp/claude-501/-Users-simonfrtd-Projects-xplendor/a6e0e182-6c27-419c-9f8e-77bff817f205/

## 10. O que ficou por confirmar

- Cabeçalhos HTTP da resposta do feed (Cache-Control, ETag, Last-Modified), não guardados; úteis para leitura condicional. Confirmar na próxima ronda com uma leitura com -D.
- Comportamento do feed em horas de pico (se a janela de 10 itens perde tendências) e variação da proporção de categorias ao longo da semana; exige várias leituras ao longo de dias.
- O URL alternativo (trendingsearches/daily/rss) não foi testado; não foi necessário.
- Existência de termos adicionais específicos do Google Trends para além da ajuda consultada: não foram encontrados nas páginas lidas; classificação "omisso".
