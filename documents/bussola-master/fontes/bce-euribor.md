# Radar XPLENDOR: fontes para decisões do BCE e taxas Euribor

Leitura efetuada a 12 de outubro de 2026, entre as 18:17 e as 18:23 UTC (19:17 a 19:23, hora de Lisboa). Regras cumpridas: no máximo 3 pedidos HTTP por domínio, redirecionamentos contados, termos antes das amostras. Contagem final: www.ecb.europa.eu 3 (inclui 1 redirecionamento), data-api.ecb.europa.eu 3 (inclui 1 resposta 404), www.emmi-benchmarks.eu 3, bpstat.bportugal.pt 3. As pesquisas web (WebSearch) não contam e estão assinaladas como tal; tudo o que vem de pesquisa e não de leitura direta está marcado "por pesquisa".

## 1. Conclusões e recomendação

1. Decisões do BCE: fonte oficial recomendada é o RSS de imprensa do BCE, https://www.ecb.europa.eu/rss/press.html (RSS 2.0, sem autenticação, 15 itens, ligações para as páginas oficiais). Os termos do BCE permitem a utilização livre com citação da fonte. A decisão de política monetária é publicada em comunicado às 14:15 CET (13:15 em Lisboa) no segundo dia da reunião; a conferência de imprensa começa às 14:45 CET (13:45 em Lisboa).
2. Euribor, valores diários: o administrador é a EMMI e os termos não permitem redistribuição comercial sem contrato de licença; a publicação gratuita no sítio da EMMI tem atraso de 24 horas, exige registo, cobre só os últimos 25 dias e é exclusivamente para uso não comercial; os termos proíbem ainda leitura automatizada do sítio. Por isso não foram pedidas taxas à EMMI e não devem ser lidas pelo serviço sem licença.
3. Euribor, caminho recomendado sem licença da EMMI: médias mensais a 3, 6 e 12 meses no BPstat do Banco de Portugal (API pública, JSON-stat, sem autenticação), publicadas no início do mês seguinte, com citação da fonte. O Portal de Dados do BCE tem as mesmas médias mensais (e anuais e trimestrais), mas não encontrei série diária. Em ambos os casos a Euribor é um dado de terceiros (EMMI; no BCE "provided by Refinitiv"), e a política de reutilização do SEBC exclui dados de terceiros sem autorização do originador. Recomendo confirmação escrita junto do Banco de Portugal (e, se se quiserem valores diários, contacto com subscriptions@emmi-benchmarks.eu).
4. Calendário das reuniões: página HTML oficial https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html (não lida diretamente, limite esgotado; datas abaixo por pesquisa). Não encontrei versão ICS nem RSS oficial do calendário; existem feeds ICS de terceiros (por exemplo smartcalendars.ai), que não são oficiais e não devem ser usados como fonte.

## 2. Registo dos pedidos HTTP (hora UTC; hora de Lisboa entre parênteses)

| # | Hora | Domínio | URL pedida | Código | Observações |
|---|------|---------|------------|--------|-------------|
| 1 | 18:17:44 (19:17:44) | www.ecb.europa.eu | https://www.ecb.europa.eu/home/disclaimer/html/index.en.html | 200 | 1 redirecionamento para https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html (conta como 2 pedidos); termos |
| 2 | 18:17:45 (19:17:45) | www.emmi-benchmarks.eu | https://www.emmi-benchmarks.eu/benchmarks/euribor/rate/ | 200 | termos de utilização dos dados (sem valores de taxas, exigem registo) |
| 3 | 18:17:46 (19:17:46) | bpstat.bportugal.pt | https://bpstat.bportugal.pt/ | 200 | tentativa de localizar os termos; aplicação JavaScript sem ligações legais no HTML |
| 4 | 18:19:53 (19:19:53) | www.ecb.europa.eu | https://www.ecb.europa.eu/rss/press.html | 200 | amostra do RSS (5790 bytes) |
| 5 | 18:19:53 (19:19:53) | data-api.ecb.europa.eu | https://data-api.ecb.europa.eu/service/data/FM/D.U2.EUR.RT.MM.EURIBOR3MD_+EURIBOR6MD_+EURIBOR1YD_.HSTA?lastNObservations=3&format=jsondata | 404 | "No Series was returned": não existem séries diárias com estas chaves |
| 6 | 18:19:54 (19:19:54) | data-api.ecb.europa.eu | https://data-api.ecb.europa.eu/service/data/FM/M.U2.EUR.RT.MM.EURIBOR3MD_.HSTA?lastNObservations=3&format=csvdata | 200 | amostra mensal (3 observações) |
| 7 | 18:19:54 (19:19:54) | www.emmi-benchmarks.eu | https://www.emmi-benchmarks.eu/terms-and-conditions/ | 200 | termos e condições do sítio |
| 8 | 18:19:55 (19:19:55) | www.emmi-benchmarks.eu | https://www.emmi-benchmarks.eu/faq/subscriptions/ | 200 | FAQ de subscrições (redistribuição, atraso) |
| 9 | 18:22:03 (19:22:03) | data-api.ecb.europa.eu | https://data-api.ecb.europa.eu/service/data/FM/.U2.EUR.RT.MM.EURIBOR3MD_.?lastNObservations=1&format=csvdata | 200 | frequências disponíveis para a Euribor 3M (fornecedor RT) |
| 10 | 18:22:04 (19:22:04) | bpstat.bportugal.pt | https://bpstat.bportugal.pt/data/v1/series/?lang=PT&series_ids=13168436,13168438,13168437 | 200 | metadados das 3 séries |
| 11 | 18:23:02 (19:23:02) | bpstat.bportugal.pt | https://bpstat.bportugal.pt/data/v1/domains/22/datasets/2829cb9155cb4f6ba6906db6b204c4bc/?lang=PT&series_ids=13168436,13168438,13168437 | 200 | valores das 3 séries (JSON-stat 2.0, 21 042 bytes) |

Registo bruto: (scratchpad)/radar/r0/pedidos-http-bce-euribor.log. Nota: o ficheiro pedidos-http.log na mesma pasta é partilhado com outro agente (entradas IPMA); as minhas entradas estão no ficheiro dedicado. Agente HTTP usado: XPLENDOR-Radar/0.1 (leitura de feed; contacto no site da XPLENDOR). Amostras guardadas em .../scratchpad/radar/amostras/{bce,emmi,bpstat}/.

## 3. BCE: comunicados (RSS) e calendário

### 3.1 Termos (lidos diretamente, pedido 1)

Página "Disclaimer & copyright", URL efetiva https://www.ecb.europa.eu/services/using-our-site/disclaimer/html/index.en.html. Pontos relevantes para a XPLENDOR:

- Direitos de autor do BCE sobre todo o conteúdo do sítio; "users of this website may make free use of the information obtained directly from it", com condições.
- Quando a informação é distribuída ou reproduzida, "it must appear accurately and the ECB must be cited as the source".
- Quando a informação é incorporada em produtos vendidos (qualquer suporte), quem publica deve informar os compradores, antes de pagarem e em cada acesso, de que a informação está disponível gratuitamente no sítio do BCE. Para um SaaS pago isto implica uma nota junto do aviso: "disponível gratuitamente em ecb.europa.eu".
- Se a informação for modificada, isso deve ser dito explicitamente (o serviço só mostra título, ligação e hora, sem modificação).
- Ligações a partir de sítios comerciais devem abrir o sítio do BCE na janela inteira do browser (sem iframes).
- Exceção: documentos com nome de autor (Working Papers, Occasional Papers) não podem ser republicados sem autorização; não é o caso dos comunicados.
- O BCE declina responsabilidade pela utilização da informação; só os textos no Jornal Oficial e os documentos e dados publicados pelo BCE são autênticos.

### 3.2 Amostra do RSS (pedido 4, 18:19:53 UTC)

- Formato: RSS 2.0 com espaço de nomes Atom; canal "ECB - European Central Bank", descrição "Latest releases on the ECB website - Press releases, speeches and interviews, press conferences."; elemento copyright "Copyright 2026, European Central Bank"; lastBuildDate "Fri, 09 Oct 2026 14:26:54 +0200".
- 15 itens, do mais recente ao mais antigo, cobrindo cerca de duas semanas (8 de outubro a 30 de setembro). Campos por item: title, link, guid (igual à ligação), pubDate (RFC 822, fuso +0200). Não há description, category nem autor.
- Conteúdo misto: comunicados (/press/pr/date/AAAA/html/ecb.pr...), discursos (/press/key/date/...), entrevistas (/press/inter/date/...), atas da reunião de política monetária (/press/accounts/AAAA/html/ecb.mg...), outras decisões do Conselho (/press/govcdec/otherdec/...). Alguns itens apontam para PDF.
- Exemplo de item: "Meeting of 9-10 September 2026", pubDate "Thu, 08 Oct 2026 13:30:00 +0200", ligação https://www.ecb.europa.eu//press/accounts/2026/html/ecb.mg261008~a10153d090.en.html.
- Particularidades: as ligações trazem barra dupla após o domínio ("//press/"), a normalizar; os títulos têm um espaço inicial; não registei cabeçalhos ETag ou Last-Modified (não há pedidos sobrantes para verificar), pelo que o suporte a pedidos condicionais fica por confirmar na primeira leitura em produção.
- Não encontrei (por pesquisa) um feed só de comunicados de política monetária; a página de índice oficial das decisões é https://www.ecb.europa.eu/press/govcdec/mopo/html/index.en.html e a dos comunicados de política monetária é https://www.ecb.europa.eu/press/pr/activities/mopo/html/index.en.html (ambas não lidas).

### 3.3 Calendário das reuniões de política monetária (por pesquisa, 18:22 UTC; página não lida diretamente)

Fonte oficial: https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html ("Schedules for the meetings of the Governing Council and General Council of the ECB and related press conferences"). Datas obtidas através dos excertos da própria página do BCE devolvidos pela pesquisa, cruzados com fontes secundárias (eco3min.fr, fgeerolf.com, financecalendar.com). Decisão às 14:15 CET no segundo dia, conferência de imprensa às 14:45 CET; em Lisboa, 13:15 e 13:45.

Próximas reuniões (2026): 28 e 29 de outubro de 2026 (decisão quinta-feira 29); 16 e 17 de dezembro de 2026 (decisão quinta-feira 17). Reuniões anteriores de 2026, para referência: 4-5 fev., 18-19 mar., 29-30 abr., 10-11 jun., 22-23 jul., 9-10 set. (Berlim, acolhida pelo Bundesbank).

2027 (Frankfurt): 3-4 de fevereiro; 17-18 de março; 28-29 de abril; 9-10 de junho; 21-22 de julho; 8-9 de setembro; 27-28 de outubro; 15-16 de dezembro. As datas de fevereiro a junho de 2027 constam dos excertos da página do BCE; julho a dezembro de 2027 vêm de um excerto posterior da mesma página e de fontes secundárias, a confirmar na primeira leitura direta da página.

ICS ou RSS do calendário: não encontrei versão oficial (por pesquisa). Recomendação: tabela local de datas, revista manualmente quando o BCE publica o calendário do ano seguinte, com leitura semestral da página oficial para detetar alterações.

## 4. EMMI (Euribor): termos lidos diretamente (pedidos 2, 7 e 8)

- Página das taxas (https://www.emmi-benchmarks.eu/benchmarks/euribor/rate/): "The European Money Markets Institute, through the Calculation Agent, is the sole official source of publication of the Euribor rates." As taxas Live são distribuídas aos Authorised Vendors "at or shortly after 11:00 CET". "Provided prior registration, Delayed Euribor rates can be consulted for free on our website with a 24-hour delay, on a backward rolling period of 25 publication days. These rates may solely be used for non-commercial purposes. Subscription is mandatory to access Euribor rates and for any commercial use thereof."
- Terms of Use dos dados (na mesma página): acesso condicionado ao compromisso de não copiar, modificar, reproduzir, reformatar, divulgar, transmitir, distribuir ou disseminar os dados "to any person or entity for any commercial reasons without prior signature of a License Agreement with The European Money Markets Institute"; os dados "constitutes valuable property owned by The European Money Markets Institute and/or other relevant third parties"; a EMMI reserva-se o direito de bloquear endereços IP.
- Terms and Conditions do sítio (https://www.emmi-benchmarks.eu/terms-and-conditions/): toda a informação é "for your information and personal use only"; uso comercial exige "a license agreement"; cláusula anti-automatização: o utilizador compromete-se a não usar "deep-link, scraper, robot, bot, spider, data-mining" ou outro processo automatizado. Logo, um leitor automático do sítio da EMMI viola os termos mesmo para os dados atrasados.
- FAQ de subscrições (https://www.emmi-benchmarks.eu/faq/subscriptions/): "A Subscription plan does not allow you to redistribute our data to a third party"; licenças de distribuição através de subscriptions@emmi-benchmarks.eu; a EMMI não distribui dados Live diretamente a utilizadores finais, apenas através de Information Vendors autorizados (lista na página; inclui, para dados atrasados, o Deutsche Bundesbank e o Banco da Finlândia).
- Conclusão: não foram pedidas taxas à EMMI. Para mostrar a Euribor diária a clientes de um serviço pago, é necessária licença da EMMI ou contrato com um vendedor autorizado.

## 5. BPstat (Banco de Portugal)

### 5.1 Termos

- No domínio bpstat.bportugal.pt não localizei página de termos: a página inicial é uma aplicação JavaScript sem ligações legais no HTML (pedido 3) e as pesquisas restritas ao domínio não devolveram nenhuma. A documentação da API está em https://bpstat.bportugal.pt/data/docs (não lida; limite reservado para a amostra).
- Quadro aplicável (por pesquisa): a "Policy regarding the reuse of ESCB statistics" do BCE, https://www.ecb.europa.eu/stats/ecb_statistics/governance_and_quality_framework/html/usage_policy.en.html, cobre as estatísticas dos bancos centrais nacionais: "All publicly available ESCB statistics may be reused free of charge on the condition that the source is quoted (e.g. 'Source: ECB statistics.') and that the statistics (including metadata) are not modified." e "The right of free reuse does not apply to third-party data without a prior permission from the originator." O aviso legal do Banco de Portugal no Portal do Cliente Bancário (https://clientebancario.bportugal.pt/en/disclaimer-and-copyright, por pesquisa) segue o mesmo modelo: uso livre com citação da fonte e indicação de alterações.
- Recomendação: pedir confirmação escrita ao Banco de Portugal (info@bportugal.pt) de que a reprodução das médias mensais da Euribor com citação da fonte é admitida num serviço comercial, dado o carácter de dado de terceiros.

### 5.2 API e séries (pedidos 10 e 11)

- Base: https://bpstat.bportugal.pt/data/v1. Metadados: GET /series/?lang=PT&series_ids=ID1,ID2 (devolve id, label, description, obs_updated_at, dataset_id, domain_ids). Valores: GET /domains/{domain_id}/datasets/{dataset_id}/?lang=PT&series_ids=... (JSON-stat 2.0, "source": "Banco de Portugal", com extension.obs_updated_at). Sem autenticação.
- Séries Euribor (médias mensais, percentagem, desde 1994-01): 3 meses 13168436; 6 meses 13168438; 1 ano 13168437. Domínio 22, dataset 2829cb9155cb4f6ba6906db6b204c4bc (40 séries, dimensão "Fonte: Banco Central Europeu"). Páginas web: https://bpstat.bportugal.pt/serie/13168436, .../13168438, .../13168437; quadro "Taxas Euribor por prazo": https://bpstat.bportugal.pt/conteudos/quadros/2599.
- Séries diárias: não encontrei (por pesquisa) Euribor diária no BPstat; existe apenas uma série diária derivada ("TBA calculada a partir das EURIBOR", série 12504601), que não é a Euribor.
- Atraso: a média de setembro de 2026 foi carregada em 2026-10-02T16:00:00Z (obs_updated_at), ou seja, no segundo dia útil do mês seguinte, às 17:00 de Lisboa. Leitura diária às 18:00 UTC chega para apanhar a atualização mensal no próprio dia.
- Amostra (leitura 18:23:02 UTC): 3 meses jul./ago./set. 2026: 2,4254 / 2,5131 / 2,6350; 6 meses: 2,6467 / 2,7133 / 2,9216; 1 ano: 2,8551 / 2,9537 / 3,2470. Os valores mensais coincidem com os do Portal de Dados do BCE (3 meses, set. 2026: 2,6350455).

## 6. Portal de Dados do BCE (data-api.ecb.europa.eu)

- Termos: o domínio da API não tem página própria; aplicam-se o aviso legal do BCE (secção 3.1, lido) e a política de reutilização das estatísticas do SEBC (secção 5.1, por pesquisa): reutilização gratuita com citação da fonte e sem modificação, exceto dados de terceiros sem autorização do originador.
- Séries Euribor existentes (pedido 9, chave com frequência e tipo de dados em aberto): FM.A.U2.EUR.RT.MM.EURIBOR3MD_.HSTA (anual, 2025: 2,1769843), FM.M.U2.EUR.RT.MM.EURIBOR3MD_.HSTA (mensal, 2026-09: 2,6350455) e FM.Q.U2.EUR.RT.MM.EURIBOR3MD_.HSTA (trimestral, 2026-T3: 2,523197). As chaves diárias FM.D.U2.EUR.RT.MM.EURIBOR{3MD_,6MD_,1YD_}.HSTA devolveram 404 "No Series was returned" (pedido 5). Não testei outros fornecedores além de RT.
- Amostra mensal (pedido 6): 2026-07 2,4253913; 2026-08 2,5131429; 2026-09 2,6350455. TITLE_COMPL: "Euro area (changing composition) - Money Market - Euribor 3-month - Historical close, average of observations through period - Euro, provided by Refinitiv". Chaves equivalentes a 6 e 12 meses: EURIBOR6MD_ e EURIBOR1YD_ (não lidas nesta sessão).
- Sintaxe: GET https://data-api.ecb.europa.eu/service/data/FM/{chave}?lastNObservations=N&format=csvdata (ou jsondata); o operador "+" para várias chaves funciona (a resposta 404 mostra o OR_QUERY montado); sem autenticação.

## 7. Ganchos descritivos para o serviço (sem explicar causas)

- Deteção da decisão: ler o RSS de 15 em 15 minutos; comparar lastBuildDate e guid com o último estado; aceitar item cujo título comece por "Monetary policy decisions" ou cuja ligação contenha "/press/pr/date/AAAA/html/ecb.mp" (padrão habitual dos comunicados de decisão, a confirmar na primeira decisão captada: a próxima é a 29 de outubro de 2026). Reforço: nos dias de reunião, entre as 13:10 e as 13:40 de Lisboa, reduzir o intervalo para 5 minutos.
- Texto ao consultor: "O BCE anunciou hoje a decisão sobre as taxas de juro. Fonte: Banco Central Europeu, comunicado de 29/10/2026, 13:15 (hora de Lisboa), disponível gratuitamente em ecb.europa.eu. Ler o comunicado (ligação)." A ligação deve abrir em janela ou separador completo, nunca em iframe.
- Antecipação: "Reunião de política monetária do BCE: decisão prevista para quinta-feira, 29 de outubro de 2026, às 13:15 (hora de Lisboa); conferência de imprensa às 13:45. Fonte: calendário do BCE (ligação)."
- Euribor: "Euribor a 6 meses, média de setembro de 2026: 2,922%. Fonte: Banco de Portugal (BPstat, série 13168438), atualizado a 2 de outubro de 2026, 17:00." Mostrar sempre o mês de referência, a fonte e a hora de atualização; nunca comentar a variação.
- Horários úteis: Euribor publicada pela EMMI às 11:00 CET em cada dia útil (não ler sem licença); médias mensais no BPstat e no BCE no início do mês seguinte; decisão do BCE às 14:15 CET.

## 8. Limitações e pontos em aberto

- A página do calendário do BCE e a política de reutilização do SEBC não foram lidas diretamente (limite por domínio); as datas de julho a dezembro de 2027 e o padrão de URL "ecb.mp" devem ser confirmados na primeira leitura em produção.
- O 404 do pedido 5 e o redirecionamento do pedido 1 consumiram dois pedidos; a existência de Euribor diária no Portal de Dados do BCE com outro fornecedor que não RT ficou por testar.
- Os termos específicos do BPstat não foram localizados; a base legal apresentada é a política do SEBC e o aviso legal do Banco de Portugal noutro portal. Pedir confirmação ao Banco de Portugal antes do lançamento.
- A Euribor é, em todas as fontes públicas, um dado de terceiros (EMMI); a exibição de valores diários num serviço comercial requer licença da EMMI.
