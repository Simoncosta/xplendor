# Fonte RSS: jornais portugueses (ronda 0)

Data da verificação: 12 de outubro de 2026, 19:14 a 19:25 WEST (segunda-feira, fim de tarde).
Fim previsto: o XPLENDOR Radar lê o feed de 15 em 15 minutos, guarda apenas título, ligação, nome da fonte e hora durante 30 dias (nunca o artigo nem a descrição), mostra aos clientes "está a subir X" com ligação para a fonte e nunca republica.
Método: por jornal, primeiro os termos de uso na própria fonte (1 pedido), só depois uma única amostra do feed geral, e apenas quando os termos permitem ou são omissos. Limite de 3 pedidos HTTP por domínio, contando redirecionamentos (curl com --max-redirs 1 e registo de num_redirects). Nenhum domínio chegou a 3 pedidos. Registo completo em `r0/registo-pedidos-jornais.txt`.
Agente usado: `XPLENDOR-Radar/0.1 (leitura de feed; contacto no site da XPLENDOR)`. Sem chaves, cookies nem autenticação.

Critério de veredicto (não é parecer jurídico):
- **permite** / **permite com condições**: cláusula que admite expressamente a leitura automática ou a agregação de títulos com ligação (nenhum caso encontrado).
- **não permite (sem autorização)**: cláusula contratual específica que cobre o uso pretendido: uso "exclusivamente pessoal", proibição de uso ou exploração comercial, proibição de qualquer uso total ou parcial, ou restrição a hiperligações. Não se buscou amostra.
- **omisso**: apenas reserva genérica de direitos de autor, sem cláusula sobre leitura automática, feeds, agregação ou ligações, e com feed RSS público anunciado pelo próprio jornal. Buscou-se amostra, com a ressalva de que a reserva genérica aconselha autorização escrita antes do lançamento comercial.
- **não verificável**: o sítio bloqueou o leitor automático (403) na própria página de termos. Não se buscou amostra.

Classificação dos títulos: por título, uma categoria principal entre: desporto; política; tragédia, morte, acidente, crime, doença, conflito; religião; nome de pessoa, vida pessoal; nome de pessoa, evento público; entretenimento ou cultura; economia ou consumo; meteorologia; tecnologia; outro. Os títulos com nome de pessoa foram contados à parte (nunca transcritos). "Fração que o filtro tiraria" assume que o filtro remove desporto, política, tragédia e afins, religião e vida pessoal; a variante estrita remove também qualquer título com nome de pessoa.

## 1. Público (publico.pt)

- Termos: https://www.publico.pt/nos/termos-e-condicoes (última actualização: 11 de janeiro de 2023). Pedido 1: 200, sem redirecionamentos.
- Veredicto: **omisso** (reserva genérica remetendo para as exceções legais).
- Citação: "Todos os conteúdos são protegidos por Direitos de Autor ao abrigo da legislação Portuguesa, da União Europeia e dos Tratados Internacionais, não podendo ser utilizados fora das condições de uso livre permitidas por lei, sem o consentimento expresso e escrito do Público – Comunicação Social, S.A.". Nada sobre feeds, agregação, leitura automática ou ligações. O rodapé e a etiqueta `<link rel="alternate" type="application/rss+xml">` apontam para o feed público.
- Feed geral: https://feeds.feedburner.com/PublicoRSS (alojado no FeedBurner, Google; o pedido conta-se como pedido 2 do Público). Amostra gravada em `amostras/jornais/publico.xml`: 200, 8,2 kB, `text/xml`.
- Amostra: 10 itens (o feed está limitado a 10). Janela: 16:34:27 a 17:51:49 UTC (1,29 h), ou seja, cerca de 7 itens por hora ao fim da tarde. Campos por item: title, link, description (resumo curto, média de 177 caracteres), author, category (uma por item), guid, pubDate. Sem content:encoded, sem media, sem ttl. O RSS tem `copyright: PÚBLICO 2026`.
- Feeds de secção: não verificados. O rodapé liga apenas ao feed geral; diretórios de terceiros referem feeds de secção em publico.pt (política, mundo), sem URL confirmada.
- Classificação dos 10 títulos: política 4; entretenimento ou cultura 2; outro 2 (ciência e ambiente; viagens); economia ou consumo 1; tragédia, crime 1. Religião 0, desporto 0, meteorologia 0, tecnologia 0. Títulos com nome de pessoa: 3, todos em contexto de evento público (nenhum de vida pessoal).
- Filtro: tiraria 5 em 10 (50%); variante estrita 6 em 10 (60%).

## 2. Expresso (expresso.pt)

- Termos: https://expresso.pt/sobre/termos-de-utilizacao/2019-08-09-Termos-e-Condicoes-de-Utilizacao-5c0443a7 (URL obtida por pesquisa; versão de 2019).
- Pedido 1 (curl, 19:17:10): **403**, corpo de 771 bytes do DataDome ("Please enable JS and disable any ad blocker", host geo.captcha-delivery.com). Pedido 2 (WebFetch): recusado pela própria ferramenta ("unable to fetch from expresso.pt"); contado por prudência.
- Veredicto: **não verificável**. O sítio bloqueia leitores automáticos na página de termos; os termos não puderam ser lidos na fonte. Sem amostra. Feed: diretórios de terceiros referem um endereço FeedBurner truncado e feeds em rss.impresa.pt (Tribuna Expresso); nada confirmado.
- Próximo passo: ler os termos num navegador e pedir autorização escrita à Impresa.

## 3. Observador (observador.pt)

- Termos: https://observador.pt/termos-e-condicoes/ (sem data visível). Pedido 1: 200.
- Veredicto: **omisso** (reserva genérica; só excetua a utilização privada permitida por lei; nada sobre feeds, ligações ou leitura automática). Ressalva: a reserva é mais estreita do que a do Público, pelo que convém autorização escrita antes do lançamento comercial.
- Citação: "Todo este conteúdo é propriedade do Observador, é protegido por direitos de autor ao abrigo das leis portuguesas e da União Europeia – pelo que não pode ser utilizado sem o consentimento, expressamente definido como tal, do Observador, exceto para os fins de utilização privada permitidos pela lei." O HTML da página anuncia o feed público (`https://observador.pt/feed/`).
- Feed geral: https://observador.pt/feed/ (canal "Observador - Últimas"). Amostra em `amostras/jornais/observador.xml`: 200, 71 kB, `text/xml`.
- Amostra: 51 itens. Janela: 16:18:30 a 19:13:04 (hora de Lisboa), 2,91 h, cerca de 17 itens por hora. Campos: guid, link, media:content (imagem com crédito), pubDate, title, description (média de 175 caracteres), dc:creator, category (várias por item; 304 no total). Sem content:encoded. lastBuildDate presente. Inclui itens de rádio, noticiários horários, programas e fact checks, o que acrescenta ruído.
- Feeds de secção: o sítio é WordPress, pelo que o padrão esperado é `https://observador.pt/seccao/<secção>/feed/` (economia, país, cultura, lifestyle, auto, tecnologia); não verificado.
- Classificação dos 51 títulos: política 22; economia ou consumo 7; desporto 6; outro 6 (transportes, educação, trabalho, família, pobreza); tragédia, acidente, crime, conflito 5; entretenimento ou cultura 2; meteorologia 2; tecnologia 1; religião 0; vida pessoal 0 (um título de desporto toca a família de um atleta). Títulos com nome de pessoa: 18, todos em contexto de evento público e todos já dentro das categorias política, desporto ou conflito.
- Filtro: tiraria 33 em 51 (65%); variante estrita igual (65%).

## 4. RTP Notícias (rtp.pt)

- Termos: https://media.rtp.pt/rgpd/termos-e-condicoes/ (última atualização: 14 de maio de 2024). Pedido 1: 200. Pedido 2: página oficial de RSS https://www.rtp.pt/noticias/rss/feeds (200), para verificar termos próprios dos feeds: não os tem; dirige-se a leitores pessoais ("receber todas as novidades em tempo real no seu computador/dispositivo"; "basta instalar um RSS Reader").
- Veredicto: **não permite sem autorização escrita**.
- Citações: "A RTP autoriza os interessados a utilizar, visualizar, imprimir, descarregar e armazenar os elementos inseridos nas plataformas digitais exclusivamente para seu uso pessoal (...). É expressamente proibida a utilização de tais elementos, a sua reprodução, comunicação e distribuição com fins comerciais". E, sobre ligações: "Qualquer utilizador que pretenda estabelecer uma hiperligação desde as suas plataformas digitais às plataformas digitais da RTP deverá cumprir as condições a seguir estabelecidas: A hiperligação apenas se poderá dirigir à Página Principal (Home Page) das plataformas digitais da RTP, salvo autorização expressa e escrita da RTP em contrário." Também: "A RTP reserva-se o direito de proibir em qualquer momento qualquer hiperligação".
- Sem amostra. Feeds anunciados na página oficial (para quando houver autorização): geral https://www.rtp.pt/noticias/rss ; secções /noticias/rss/pais, /mundo, /desporto, /economia, /cultura, /videos, /audios.
- Próximo passo: pedir autorização escrita à RTP (serviço público; feeds de secção bem definidos e sem desporto no feed de economia ou cultura).

## 5. JN, Jornal de Notícias (jn.pt)

- Termos: https://www.jn.pt/termos-e-privacidade/ ("Termos de Uso e Política de Privacidade", Notícias Ilimitadas, NI; sem data visível). Pedido 1: 200.
- Veredicto: **não permite sem autorização expressa**.
- Citações: "sendo expressamente interdita qualquer cópia, reprodução, difusão ou transmissão, utilização, modificação, venda, publicação, distribuição ou qualquer outro uso, total ou parcial, comercial ou não comercial, quaisquer que sejam os meios utilizados, salvo com autorização expressa"; "O Utilizador poderá descarregar ou copiar material estritamente para uso pessoal".
- Sem amostra. O novo sítio (Next.js) não anuncia RSS em lado nenhum da página; os antigos endereços feeds.jn.pt não foram verificados.

## 6. DN, Diário de Notícias (dn.pt)

- Termos: https://www.dn.pt/termos-de-utilizacao (Global Media Group, 11 de setembro de 2024). Pedido 1: 200.
- Veredicto: **não permite uso comercial sem autorização prévia**.
- Citação: "Todos os direitos de autor, direitos conexos e propriedade industrial relacionados com o conteúdo são detidos pelo GMG ou pelos seus licenciadores, pelo que o utilizador não pode copiar, modificar, distribuir ou utilizar o conteúdo para fins comerciais sem autorização prévia." O "conteúdo" inclui "notícias, imagens, design gráfico, software".
- Sem amostra. O sítio corre em Quintype; o HTML expõe os padrões `https://www.dn.pt/api/v1/collections/<coleção>.rss` (vê-se `últimas-dv.rss`, do Dinheiro Vivo) e `/topico/<tópico>/feed/`; nada verificado.

## 7. SIC Notícias (sicnoticias.pt)

- Termos: https://sicnoticias.pt/institucional/2013-12-27-termos-e-condicoes.
- Pedido 1 (curl): **403** DataDome, igual ao Expresso (mesmo grupo, Impresa). Pedido 2 (WebFetch): recusado pela ferramenta; contado por prudência.
- Veredicto: **não verificável**. Sem amostra. Feed desconhecido (nenhum diretório dá URL).
- Próximo passo: o mesmo pedido à Impresa cobre Expresso e SIC Notícias.

## 8. ECO (eco.sapo.pt)

- Termos: https://eco.sapo.pt/termos-e-condicoes/ (Swipe News, S.A.; sem data visível; refere o sítio como www.eco.pt). Pedido 1: 200.
- Veredicto: **omisso** (reserva genérica, sem exceção explícita; nada sobre feeds, ligações ou leitura automática; o sítio é WordPress com feed público padrão).
- Citação: "O conteúdo do Website e da App é propriedade do ECO e encontra-se protegido por direitos de propriedade intelectual ao abrigo das leis portuguesas e da União Europeia – pelo que não pode ser utilizado sem o consentimento prévio do ECO." Também: "Os Utilizadores podem aceder livremente ao Website e à App, não podendo, todavia, (...) fazer qualquer tipo de uso indevido."
- Feed geral: https://eco.sapo.pt/feed/ . Amostra em `amostras/jornais/eco.xml`: 200, 164 kB, `application/rss+xml`.
- Amostra: 22 itens. Janela: 14:00:26 a 17:53:22 UTC (3,88 h), cerca de 5 itens por hora. Campos: title, link, comments, dc:creator, pubDate, category (64 no total), guid, description (média de 479 caracteres), **content:encoded com o artigo completo** (daí os 164 kB; o Radar tem de o descartar à entrada e nunca o guardar), wfw:commentRss, slash:comments. Gerador WordPress 6.8.11.
- Feeds de secção: padrão WordPress `https://eco.sapo.pt/<categoria>/feed/`; categorias vistas na amostra: Economia, Empresas, Mercados, Energia, Automóveis, Capital Verde, ECOseguros, Local Online, Política, Defesa, Turismo; não verificado.
- Classificação dos 22 títulos: economia ou consumo 13 (inclui o lançamento de um automóvel elétrico de preço baixo e o impacto económico de um evento de motociclismo); política 7 (orçamento da UE, governo, defesa, financiamento dos media); tecnologia 1 (ciberataque); outro 1 (opinião). Desporto 0, tragédia 0, religião 0, meteorologia 0, cultura 0. Títulos com nome de pessoa: 3, todos de governantes em contexto público.
- Filtro: tiraria 7 em 22 (32%); variante estrita 8 em 22 (36%).

## 9. Jornal de Negócios (jornaldenegocios.pt)

- Termos: https://www.jornaldenegocios.pt/institucional/detalhe/20150902-1659-33jn-1-3-disclaimer ("Disclaimer", Cofina Media, hoje Medialivre; página de 2015). Pedido 1: 200. Pedido 2: página oficial "RSS Feeds" https://www.jornaldenegocios.pt/institucional/detalhe/2012_11_23_rss_feeds (200): oferece o feed "para que os leitores tenham acesso à informação (...) da forma como mais lhes convier", sem termos próprios.
- Veredicto: **não permite sem autorização escrita**.
- Citações: "O Utilizador deverá utilizar os materiais, elementos e informação a que aceda através da utilização da Página unicamente para as suas necessidades pessoais, obrigando-se a não realizar, directa ou indirectamente, uma exploração comercial dos materiais, elementos e informação obtidos através dos mesmos."; "A informação financeira presente no Jornal de Negócios destina-se apenas para uso pessoal e não pode ser utilizada para fins comerciais"; rodapé: "É expressamente proibida a reprodução na totalidade ou em parte, em qualquer tipo de suporte, sem prévia permissão por escrito da Medialivre S.A."
- Sem amostra. Feed anunciado: https://www.jornaldenegocios.pt/rss (só "últimas"; a página não lista feeds de secção).

## 10. TSF (tsf.pt)

- Termos: https://www.tsf.pt/termos-e-condicoes/ (texto idêntico ao do JN, Notícias Ilimitadas; sem data visível). Pedido 1: 200.
- Veredicto: **não permite sem autorização expressa** (mesmas cláusulas do JN: qualquer uso total ou parcial interdito salvo autorização; cópia "estritamente para uso pessoal").
- Sem amostra. O sítio não anuncia RSS; feeds.tsf.pt continua a servir podcasts, mas o feed de notícias não foi verificado.

## 11. Renascença (rr.pt; o domínio rr.sapo.pt indicado na tarefa aparece nas pesquisas como espelho do atual rr.pt)

- Termos: https://rr.pt/termos-e-condicoes . Pedido 1 (curl): **403**, página "Service unavailable / The request is blocked" (WAF). Pedido 2 (WebFetch): 403 Forbidden.
- Veredicto: **não verificável**. Sem amostra. Por via indireta (excerto de pesquisa, não confirmado na fonte), os termos da Renascença dirão que a reprodução ou exploração comercial dos conteúdos é proibida sem autorização expressa. Feed referido por diretórios: https://rr.pt/rssfeed-ultimas (não verificado); página oficial https://rr.pt/rss.

## Tabela resumo

| Jornal | Veredicto | URL do feed (verificada se marcada com V) | Itens por hora (estimativa) | Fração que o filtro tiraria |
|---|---|---|---|---|
| Público | omisso | https://feeds.feedburner.com/PublicoRSS (V) | cerca de 7 (feed limitado a 10 itens) | 50% (estrita 60%) |
| Expresso | não verificável (403 DataDome) | desconhecida | sem amostra | sem amostra |
| Observador | omisso (reserva: só uso privado) | https://observador.pt/feed/ (V) | cerca de 17 | 65% |
| RTP Notícias | não permite sem autorização escrita (ligações só para a página principal; uso pessoal) | https://www.rtp.pt/noticias/rss e secções /pais /mundo /desporto /economia /cultura (lista oficial, não descarregada) | sem amostra | sem amostra |
| JN | não permite sem autorização expressa | nenhuma anunciada | sem amostra | sem amostra |
| DN | não permite uso comercial sem autorização | padrão Quintype /api/v1/collections/<x>.rss (não verificado) | sem amostra | sem amostra |
| SIC Notícias | não verificável (403 DataDome) | desconhecida | sem amostra | sem amostra |
| ECO | omisso | https://eco.sapo.pt/feed/ (V) | cerca de 5 | 32% (estrita 36%) |
| Jornal de Negócios | não permite sem autorização escrita | https://www.jornaldenegocios.pt/rss (anunciado, não descarregado) | sem amostra | sem amostra |
| TSF | não permite sem autorização expressa | nenhuma anunciada | sem amostra | sem amostra |
| Renascença | não verificável (403 WAF) | https://rr.pt/rssfeed-ultimas (diretório, não verificado) | sem amostra | sem amostra |

Estimativas de ritmo medidas numa segunda-feira ao fim da tarde; de noite e ao fim de semana serão menores. Nenhum dos três feeds lidos tem `ttl`; o Observador e a ECO têm `lastBuildDate`. Poll de 15 em 15 minutos é folgado para todos (o Público, limitado a 10 itens, cobre cerca de 1,3 h; o Observador, 51 itens, cerca de 3 h).

## Recomendação para começar (3 a 5 feeds)

1. **ECO, feed geral** (https://eco.sapo.pt/feed/): omisso, pouco ruído (32% filtrado), economia e consumo, incluindo automóvel; obrigatório descartar `content:encoded` e `description` à entrada. Quando houver verificação, preferir os feeds de categoria Empresas, Automóveis e Consumo.
2. **Público, feed geral** (https://feeds.feedburner.com/PublicoRSS): omisso com remissão para as exceções legais, só 10 itens por leitura, 50% filtrado; apenas título, ligação, categoria e data úteis. Verificar depois a existência de feeds de secção (Economia, Cultura, Local).
3. **Observador, feed Últimas** (https://observador.pt/feed/): omisso com reserva mais estreita; o mais rápido (17 por hora) e o mais ruidoso (65% filtrado, com rádio, noticiários horários e fact checks). Compensa trocar pelo padrão de secção `/seccao/<economia|lifestyle|auto|tecnologia>/feed/` assim que verificado, e pedir autorização escrita.
4. **RTP Notícias, secções Economia e Cultura** (https://www.rtp.pt/noticias/rss/economia, /cultura): só depois de autorização escrita da RTP; serviço público, lista oficial de secções, sem desporto nem política nesses dois feeds.
5. **Jornal de Negócios** (https://www.jornaldenegocios.pt/rss): só depois de autorização escrita da Medialivre; economia, ruído baixo previsível.

Para todos, antes do lançamento comercial: pedir autorização escrita (os três "omissos" têm reservas genéricas de direitos), manter atribuição (nome da fonte) e ligação direta ao artigo, respeitar robots.txt e cabeçalhos de cache (If-Modified-Since, ETag), identificar o agente com contacto, e nunca guardar description ou content:encoded. Expresso, SIC Notícias e Renascença: ler os termos num navegador e contactar antes de qualquer leitura automática; os sítios bloqueiam robôs.

## Registo de pedidos HTTP

Ver `r0/registo-pedidos-jornais.txt` (hora, tipo, jornal, URL, código, redirecionamentos, bytes). Contagem por domínio: publico.pt 1 (+1 a feeds.feedburner.com); expresso.pt 2; observador.pt 2; rtp.pt 2 (media.rtp.pt e www.rtp.pt); jn.pt 1; dn.pt 1; sicnoticias.pt 2; eco.sapo.pt 2; jornaldenegocios.pt 2; tsf.pt 1; rr.pt 2. Nenhum redirecionamento ocorreu.
