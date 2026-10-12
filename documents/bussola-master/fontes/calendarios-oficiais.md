# R0: calendários oficiais para a Bússola (datas com fonte oficial)

Data da recolha: 12 de outubro de 2026, 19:19 a 19:23 WEST. Leitura pública, sem autenticação, sem dados pessoais.
Limite: 3 pedidos HTTP por domínio, contando redirecionamentos, páginas de termos, curl e WebFetch. Total: 15 pedidos, nenhum domínio acima de 3.
Agente: `XPLENDOR-Radar/0.1 (leitura; contacto no site da XPLENDOR)`. Amostras em `scratchpad/radar/amostras/calendarios/` (HTML, cabeçalhos, texto extraído, um PDF).
Pesquisas prévias (WebSearch, não contam) serviram só para fixar os URL antes de gastar pedidos.

## 1. Registo de pedidos (um por salto)

| Hora (WEST) | URL pedido | Resultado |
|---|---|---|
| 19:19:03 | https://info.portaldasfinancas.gov.pt/pt/quem_somos/privacidade/Pages/privacidade.aspx | 200 (política de privacidade e cookies da AT) |
| 19:19:04 | https://diariodarepublica.pt/dr/geral/avisos-legais | 200 (casca OutSystems: "JavaScript is required") |
| 19:19:04 | https://www.dgaep.gov.pt/ | 200 (página inicial; serviu para achar a ligação dos termos) |
| 19:19:08 | https://www.cm-porto.pt/legal/politica-de-privacidade | 200 |
| 19:19:09 | https://www.lisboa.pt/ | 403 (Cloudflare, `cf-mitigated: challenge`) |
| 19:20:31 | https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Paginas/default.aspx | 404 (caminho antigo; o atual usa `Pages`) |
| 19:20:33 | https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34546475 | WebFetch: conteúdo vazio (casca JavaScript) |
| 19:20:33 | https://www.lisboa.pt/ | WebFetch: 403 |
| 19:20:35 | https://porto.pt/en/news/sao-joao-is-back-as-it-always-was-traditional-and-celebrated-throughout-the-city | 302 para www.porto.pt |
| 19:20:35 | https://www.porto.pt/en/news/sao-joao-is-back-as-it-always-was-traditional-and-celebrated-throughout-the-city | 200 |
| 19:21:19 | https://www.dgaep.gov.pt/index.cfm?OBJID=9ee9c655-8254-4786-9b61-edface9e4b44 | 200 (Privacidade e termos de utilização) |
| 19:21:20 | https://www.cm-lisboa.pt/ | 403 (Cloudflare) |
| 19:22:06 | https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx | 200 (Agenda Fiscal 2026) |
| 19:22:07 | https://files.diariodarepublica.pt/1s/2009/02/03000/0092601029.pdf | 200 (PDF da 1.ª série, Lei n.º 7/2009) |
| 19:22:34 | https://www.dgaep.gov.pt/index.cfm?OBJID=C3D90263-E275-4B47-A59B-E956A5007C89 | 200 (tema "tempo de trabalho") |

Contagem: info.portaldasfinancas.gov.pt 3; diariodarepublica.pt 2 + files.diariodarepublica.pt 1; www.dgaep.gov.pt 3; cm-porto.pt 1 + porto.pt 1 + www.porto.pt 1; www.lisboa.pt 2 + www.cm-lisboa.pt 1.
Ordem por domínio: termos primeiro na AT, no DRE e no Porto. Na DGAEP e em Lisboa o URL dos termos não apareceu em nenhuma pesquisa, pelo que o primeiro pedido foi a página inicial só para o descobrir (DGAEP: conseguido; Lisboa: bloqueado antes de chegar aos termos).

## 2. Fonte a fonte

### 2.1 Autoridade Tributária: Agenda Fiscal

- Página oficial (lida, 200): https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx, título "Agenda Fiscal - 2026". É a ligação "Agenda Fiscal" da navegação global do sítio da AT (SharePoint). O caminho antigo com `Paginas/default.aspx` devolve 404, logo o URL já mudou pelo menos uma vez.
- Formato: página HTML quase vazia que aponta para quatro recursos: dois PDF com nome fixo (sem ano), `/pt/apoio_contribuinte/calendario_fiscal/Documents/Obrigacoes_declarativas.pdf` e `/pt/apoio_contribuinte/calendario_fiscal/Documents/Obrigacoes_pagamento.pdf`, e dois quadros-resumo HTML com o ano no nome, `/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Decl_2026.aspx` e `/pt/apoio_contribuinte/calendario_fiscal/Pages/Quadro_res_Pag_2026.aspx`. Sem ICS, sem RSS, sem JSON no HTML. (Um sítio de contabilidade descreve filtros por tipo de contribuinte e exportação para calendário pessoal; não verificado, os quadros não foram lidos por falta de pedidos.)
- Termos (lidos): a página "Segurança e Privacidade" é uma política de privacidade e cookies (RGPD, Decreto-Lei n.º 118/2011; regista data, IP, navegador e páginas por 180 dias; cookie de sessão). Não há cláusula sobre leitura automática nem sobre reutilização do conteúdo.
- Estabilidade: a página e os PDF mudam todos os anos (o nome dos PDF é fixo, o conteúdo é substituído; os quadros levam o ano no URL). As datas concretas também mudam por despacho do SEAF quando há ajustamentos (há vários despachos de "ajustamento do calendário fiscal" no próprio sítio). O que é estável está na lei:
  - IMI: Código do IMI, artigo 120.º. Uma prestação em maio (até 100 euros), maio e novembro (mais de 100 até 500 euros), maio, agosto e novembro (acima de 500 euros); o pagamento é até ao fim de cada um desses meses. Redação em vigor desde o Orçamento do Estado para 2019 (Lei n.º 71/2018; antes era abril, julho e novembro). Interessa a consultores imobiliários (lembrar proprietários) e a restaurantes e stands donos do imóvel.
  - IRS: Código do IRS, artigo 60.º: entrega de 1 de abril a 30 de junho, todas as categorias, desde o OE 2017 (Lei n.º 42/2016). Interessa aos três ramos (trabalhadores independentes, sócios-gerentes, agentes imobiliários em recibos verdes).
  - IUC: Código do IUC, artigo 17.º: pago até ao fim do mês em que se torna exigível, que é o mês do aniversário da matrícula. Não é uma data de calendário mas uma regra por veículo: para um stand vale como lembrete pós-venda ao cliente ("o IUC do seu carro vence em <mês>"); as tabelas de taxas mudam a 1 de janeiro com a Lei do OE.
  - Os artigos dos códigos estão no mesmo sítio (`/pt/informacao_fiscal/codigos_tributarios/...`), não lidos nesta sessão por falta de pedidos.
- O que guardaríamos: a regra legal (artigo, meses) com fonte "Código do IMI, art. 120.º" e "Código do IRS, art. 60.º", mais o URL da Agenda Fiscal como página de confirmação anual (janeiro, quando a AT publica a agenda, e de novo depois de qualquer despacho de ajustamento). Nunca guardar o dia exato do mês sem confirmar nos PDF do ano.

### 2.2 Feriados nacionais: Código do Trabalho, artigo 234.º

- Página oficial que se cita: Código do Trabalho (Lei n.º 7/2009, de 12 de fevereiro) em versão consolidada, https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34546475. Não é legível por máquina: o DRE é uma aplicação OutSystems em React; o curl recebe só "JavaScript is required" e o WebFetch devolve vazio. Os avisos legais (https://diariodarepublica.pt/dr/geral/avisos-legais) têm o mesmo problema; o que se sabe (por pesquisa) é que remetem para a política de privacidade da INCM.
- Fonte oficial legível por máquina (lida, 200, `application/pdf`, 766 KB, servida de S3): https://files.diariodarepublica.pt/1s/2009/02/03000/0092601029.pdf, Diário da República, 1.ª série, n.º 30, 12 de fevereiro de 2009, páginas 926 a 1029. O artigo 234.º está nas páginas 974 e 975. Texto extraído com `pdftotext` (pontuação e ortografia do original de 2009):
  > "Artigo 234.º Feriados obrigatórios. 1 — São feriados obrigatórios os dias 1 de Janeiro, de Sexta-Feira Santa, de Domingo de Páscoa, 25 de Abril, 1 de Maio, de Corpo de Deus, 10 de Junho, 15 de Agosto, 5 de Outubro, 1 de Novembro, 1, 8 e 25 de Dezembro. 2 — O feriado de Sexta-Feira Santa pode ser observado em outro dia com significado local no período da Páscoa. 3 — Mediante legislação específica, determinados feriados obrigatórios podem ser observados na segunda-feira da semana subsequente."
  > "Artigo 235.º Feriados facultativos. 1 — Além dos feriados obrigatórios, podem ser observados a título de feriado, mediante instrumento de regulamentação colectiva de trabalho ou contrato de trabalho, a terça-feira de Carnaval e o feriado municipal da localidade."
- Atenção à redação: o PDF é o texto original de 2009. A Lei n.º 23/2012 suspendeu quatro feriados (Corpo de Deus, 5 de outubro, 1 de novembro, 1 de dezembro) entre 2013 e 2015 e a Lei n.º 8/2016, de 1 de abril, repô-los; a lista em vigor é a mesma do PDF (13 feriados). A confirmação da redação consolidada tem de ser feita num navegador (DRE) por uma pessoa; a ligação é estável.
- A lista (13): 1 de janeiro; Sexta-feira Santa; Domingo de Páscoa; 25 de abril; 1 de maio; Corpo de Deus; 10 de junho (Dia de Portugal); 15 de agosto; 5 de outubro; 1 de novembro; 1 de dezembro; 8 de dezembro; 25 de dezembro. Os três móveis calculam-se a partir da Páscoa (algoritmo de cálculo, sem fonte anual): 2027: Sexta-feira Santa 26 de março, Páscoa 28 de março, Corpo de Deus 27 de maio; terça-feira de Carnaval 9 de fevereiro de 2027 (facultativa).
- DGAEP (3 pedidos): página inicial, "Privacidade e termos de utilização" (política de privacidade RGPD e cookies; sem cláusula sobre leitura automática) e o tema "tempo de trabalho" (não fala de feriados). Não se encontrou nenhuma página "Feriados" no menu da DGAEP (quase 200 títulos de ligação analisados). A DGAEP só interessa para a tolerância de ponto na administração pública (despacho anual do Primeiro-Ministro), que não vincula restaurantes, stands nem consultores. Fonte primária: o Código do Trabalho.
- Estabilidade: alta. A lista só muda por lei (última mudança em abril de 2016).
- O que guardaríamos: os 13 feriados com fonte "Código do Trabalho, art. 234.º (Lei n.º 7/2009; redação da Lei n.º 8/2016)", ligação ao DRE consolidado e ao PDF da 1.ª série, os móveis como regra calculada, e a terça-feira de Carnaval e o feriado municipal marcados como facultativos (art. 235.º).

### 2.3 Câmara Municipal do Porto: São João (23 e 24 de junho)

- Termos (lidos, 200): https://www.cm-porto.pt/legal/politica-de-privacidade (política RGPD do Município; cookies em `/legal/politica-de-cookies`; refere "Termos e Condições" por sítio sem os ligar; nada sobre leitura automática).
- Página oficial: o porto.pt é o portal de notícias do Município (aplicação Next.js). Lida uma notícia de São João (2022, em inglês; `porto.pt` redireciona 302 para `www.porto.pt`): https://www.porto.pt/en/news/sao-joao-is-back-as-it-always-was-traditional-and-celebrated-throughout-the-city/. O corpo é renderizado no navegador: por curl só vêm as meta tags (título, descrição, `article:published_time` 2022-06-08). As pesquisas não devolveram nenhuma página do porto.pt para 2026; o programa de 2026 está publicado pela Ágora (empresa municipal de cultura e desporto) em agenda-porto.pt (por exemplo https://www.agenda-porto.pt/en/page/festas-sao-joao-2026-1779981091893/), domínio fora da lista autorizada, não lido.
- Feed: existe. O `<head>` da notícia declara feeds RSS por categoria ("Notícias" `/en/rss/1`, "Cultura" `/en/rss/7`, e outras até `/en/rss/18`), mas com um anfitrião interno mal configurado (`http://192.168.250.11:8080/...`). O caminho público é presumivelmente `https://www.porto.pt/pt/rss/1` (e `/pt/rss/7` para Cultura); fica por confirmar noutra sessão, com um pedido.
- Datas: noite de 23 para 24 de junho (fogo à meia-noite sobre o Douro); 24 de junho é o feriado municipal do Porto (facultativo pelo art. 235.º do Código do Trabalho). O mês de junho tem programação (cascatas, rusgas, arruadas) com datas que mudam todos os anos.
- Estabilidade: a data da noite de São João é fixa por tradição e o feriado municipal é fixo; o URL do programa muda todos os anos (identificador numérico e ano no caminho). O porto.pt é a fonte oficial estável; a página concreta não.
- O que guardaríamos: "23 de junho (noite) e 24 de junho (feriado municipal do Porto)", fonte "Município do Porto, porto.pt (notícia anual) e Ágora, agenda-porto.pt (programa)", confirmação em maio ou junho de cada ano, e o feed RSS de Cultura como gatilho depois de confirmado o caminho público.

### 2.4 Câmara Municipal de Lisboa: Santo António (12 e 13 de junho) e Festas de Lisboa

- lisboa.pt e cm-lisboa.pt devolvem 403 com desafio Cloudflare (`cf-mitigated: challenge`, "Enable JavaScript and cookies to continue") tanto ao curl como ao WebFetch. Não foi possível ler os termos nem qualquer página. O radar não deve ler lisboa.pt por máquina; a confirmação é humana, num navegador.
- Página oficial do programa (por pesquisa, não lida): as Festas de Lisboa são organizadas pela EGEAC (empresa municipal), em festasdelisboa.com e egeac.pt (por exemplo a revista das Marchas 2026 em https://egeac.pt/wp-content/uploads/2026/06/web-fdl26-marchaspopulares-revista.pdf). Em 2026 as Festas decorreram de 1 a 26 de junho, com as Marchas Populares na Avenida da Liberdade na noite de 12 de junho e os Casamentos de Santo António no dia 12.
- Datas: 12 de junho (noite das marchas e arraiais) e 13 de junho (Santo António, feriado municipal de Lisboa, facultativo pelo art. 235.º). O resto do programa muda todos os anos.
- O que guardaríamos: "12 de junho (noite) e 13 de junho (feriado municipal de Lisboa)" com fonte "Município de Lisboa, lisboa.pt; programa EGEAC, festasdelisboa.com", marcadas como "confirmação humana anual" e sem leitura automática.

## 3. Tabela consolidada

| Data ou prazo | Ramo | Fonte oficial (URL) | Formato | Estabilidade | O que guardaríamos |
|---|---|---|---|---|---|
| IMI: fim de maio, agosto e novembro (consoante o valor: até 100 euros só maio; 100 a 500 maio e novembro; acima de 500 as três) | Imobiliário; restaurantes e stands com imóvel próprio | Código do IMI, art. 120.º (AT, códigos tributários); Agenda Fiscal 2026: https://info.portaldasfinancas.gov.pt/pt/apoio_contribuinte/calendario_fiscal/Pages/obrigacoes.aspx e `Documents/Obrigacoes_pagamento.pdf` | HTML com ligação a PDF; quadro HTML anual | Regra estável desde 2019; dia exato confirmado por ano | Regra (meses e escalões) com artigo; verificação anual em janeiro |
| IRS: entrega de 1 de abril a 30 de junho | Os três ramos | Código do IRS, art. 60.º; Agenda Fiscal (acima), `Documents/Obrigacoes_declarativas.pdf` | HTML com ligação a PDF | Estável desde 2017; prorrogações por despacho em anos excecionais | Regra com artigo; verificação anual em março |
| IUC: até ao fim do mês do aniversário da matrícula | Stands (lembrete ao cliente) | Código do IUC, art. 17.º; Agenda Fiscal | Regra por veículo, não data | Estável; tabelas de taxas mudam a 1 de janeiro (Lei do OE) | A regra, não uma data; o stand calcula por matrícula |
| 13 feriados nacionais (lista na secção 2.2) | Os três ramos | Código do Trabalho, art. 234.º: https://diariodarepublica.pt/dr/legislacao-consolidada/lei/2009-34546475 (navegador) e PDF https://files.diariodarepublica.pt/1s/2009/02/03000/0092601029.pdf (pp. 974 e 975) | Aplicação JavaScript (não legível por máquina) e PDF | Alta; só muda por lei (última: Lei n.º 8/2016) | As 13 datas, 3 delas calculadas pela Páscoa, com artigo e ligação |
| 10 de junho, Dia de Portugal | Os três ramos | Idem, art. 234.º | Idem | Alta | Feriado fixo |
| Terça-feira de Carnaval (facultativo) | Restaurantes | Código do Trabalho, art. 235.º (idem) | Idem | Alta como regra; data calculada | Data calculada, marcada "facultativo" |
| 23 e 24 de junho, São João do Porto (24 é feriado municipal, facultativo) | Restaurantes do Porto; stands e consultores da região (dia parado) | Município do Porto: https://www.porto.pt (notícias; feed RSS por categoria, caminho público a confirmar); programa anual em agenda-porto.pt (Ágora) | HTML (Next.js, corpo por JavaScript) e RSS | Data fixa; página do programa muda de ano para ano | Datas fixas com fonte porto.pt; confirmação anual em maio ou junho |
| 12 e 13 de junho, Santo António e Festas de Lisboa (13 é feriado municipal, facultativo) | Restaurantes de Lisboa; stands e consultores da região | Município de Lisboa: https://www.lisboa.pt (bloqueado a máquinas, 403 Cloudflare); programa anual EGEAC, festasdelisboa.com | HTML atrás de desafio Cloudflare | Data fixa; programa anual | Datas fixas; confirmação humana anual; nunca ler por máquina |

## 4. Outras datas úteis (sem pedidos; a partir do que é conhecido)

### 4.1 Com fonte oficial clara (a guardar com a fonte)

- Os 13 feriados do art. 234.º, incluindo 1 de maio (Dia do Trabalhador), 15 de agosto, 25 de dezembro e 1 de janeiro: ver secção 2.2.
- Terça-feira de Carnaval e feriado municipal: facultativos, art. 235.º do Código do Trabalho. Para a administração pública a tolerância de ponto sai por despacho anual do Primeiro-Ministro (DRE, 2.ª série): fonte oficial mas anual e sem efeito nos três ramos.
- Mudança da hora: último domingo de março e último domingo de outubro, Diretiva 2000/84/CE e Decreto-Lei n.º 17/96 (DRE). Interessa marginalmente a restaurantes (horários, reservas).
- Inspeção periódica obrigatória (stands, lembrete pós-venda): ligeiros aos 4 anos da primeira matrícula, depois de 2 em 2 anos até aos 8, depois anual. Decreto-Lei n.º 144/2012 (DRE) e IMT. Regra por veículo, não data.
- ISV e IUC: tabelas atualizadas a 1 de janeiro pela Lei do Orçamento do Estado (DRE). Interessa a stands (preço de importados, comunicação de "antes da subida").
- Calendário escolar (início e interrupções): despacho anual do Ministério da Educação (DRE, 2.ª série). Padrão estável (arranque em meados de setembro), datas anuais. Interessa a restaurantes (famílias) e ao imobiliário (mudanças antes do ano letivo).
- Dias internacionais com fonte oficial internacional, não portuguesa: Dia Mundial dos Direitos do Consumidor (15 de março, Consumers International e ONU), Dia Mundial do Turismo (27 de setembro, ONU Turismo), Dia Mundial da Alimentação (16 de outubro, FAO). Guardar com a fonte internacional e sem o rótulo "oficial em Portugal".

### 4.2 Sem fonte oficial (quem as fixa, se alguém)

- Dia do Pai, 19 de março: calendário litúrgico católico (São José). Tradição e comércio; nenhum organismo português a fixa.
- Dia da Mãe, primeiro domingo de maio: tradição e comércio (até aos anos 1970 celebrava-se a 8 de dezembro). Nenhum organismo a fixa; a regra "primeiro domingo de maio" é convenção.
- Dia dos Namorados, 14 de fevereiro: calendário litúrgico (São Valentim) e comércio. Nenhum organismo português.
- Black Friday: sexta-feira a seguir ao Thanksgiving dos Estados Unidos. O Thanksgiving tem fonte oficial (lei federal dos EUA: quarta quinta-feira de novembro); a Black Friday é convenção do retalho, sem organismo que a fixe, e em Portugal cada loja decide a duração ("Black Week"). 2026: 27 de novembro. Cyber Monday: segunda-feira seguinte, convenção da National Retail Federation (EUA).
- Dia dos Avós, 26 de julho; Dia da Criança, 1 de junho (a data da ONU é 20 de novembro); Halloween, 31 de outubro; São Martinho, 11 de novembro (magusto; calendário litúrgico): tradição, sem lei portuguesa.
- Véspera de Natal (24 de dezembro) e 31 de dezembro: não são feriados; a administração pública costuma ter tolerância de ponto por despacho anual; no setor privado decide o empregador.
- Saldos: deixaram de ter épocas fixas (Decreto-Lei n.º 70/2007 na redação do Decreto-Lei n.º 109/2019); não há data a guardar.

Regra prática: as datas de 4.2 guardam-se com `fonte: null` e `tipo: "tradicao"`, com a justificação curta ("convenção comercial", "calendário litúrgico") para que a Bússola nunca as apresente como oficiais.

## 5. O que guardar em código (proposta de registo)

Cada entrada: `chave`, `nome`, `ramos` (restaurante, stand, imobiliario), `tipo` (fixa | movel_calculada | regra_legal | anual_por_despacho | tradicao), `data_ou_regra`, `fonte` {`nome`, `url`, `formato` (lei | pagina | pdf | feed | nenhum), `confirmado_em` (data), `confirmado_por` (humano | maquina)}, `estabilidade` (alta | media | baixa), `verificar_em` (mês da verificação anual).

Exemplos:
- `feriado.dia_de_portugal`: fixa, 10 de junho; fonte "Código do Trabalho, art. 234.º", URL do DRE consolidado, formato lei, confirmado em 2026-10-12 por máquina (PDF da 1.ª série) e a confirmar por humano no DRE; estabilidade alta.
- `fiscal.imi.prestacoes`: regra_legal, "maio, agosto, novembro por escalões (art. 120.º CIMI)"; fonte "AT, Agenda Fiscal", URL `.../calendario_fiscal/Pages/obrigacoes.aspx`, formato pagina (com PDF), confirmado em 2026-10-12 (página lida, PDF não lido); verificar em janeiro.
- `local.porto.sao_joao`: fixa, 23 e 24 de junho; fonte "Município do Porto, porto.pt", formato pagina (feed RSS a confirmar); estabilidade alta na data, baixa no URL do programa; verificar em maio.
- `local.lisboa.santo_antonio`: fixa, 12 e 13 de junho; fonte "Município de Lisboa, lisboa.pt", formato pagina, `confirmado_por: humano` obrigatório (bloqueio Cloudflare); verificar em maio.
- `comercial.black_friday`: tradicao, "sexta-feira depois da quarta quinta-feira de novembro"; fonte null; nunca rotulada de oficial.

Recomendações para o radar:
1. Não ler lisboa.pt nem cm-lisboa.pt por máquina (403 Cloudflare); registo manual.
2. Não ler o DRE por página (aplicação JavaScript); para citação usar o URL consolidado, e para leitura por máquina o PDF da 1.ª série em files.diariodarepublica.pt.
3. A Agenda Fiscal da AT é a página de confirmação anual, não a fonte da regra; a regra vem do artigo do código. Guardar o nome fixo dos PDF e ler o quadro-resumo do ano (URL com o ano) em janeiro.
4. No Porto, confirmar o caminho público do RSS (`/pt/rss/1` e `/pt/rss/7`) com um pedido noutra sessão antes de o pôr no `config/radar.php`.
5. Guardar sempre a data da confirmação e quem confirmou; o que não tem fonte fica marcado como tradição.

## 6. Amostras guardadas

`scratchpad/radar/amostras/calendarios/`: `registo-pedidos.log` (todos os pedidos), `at-privacidade.*`, `at-agenda-fiscal.*` (o 404), `at-agenda-fiscal-obrigacoes.*`, `dre-avisos-legais.*`, `dre-lei-7-2009.pdf` e `.txt` (texto extraído, 7302 linhas), `dgaep-inicio.*`, `dgaep-termos.*`, `dgaep-tempo-de-trabalho.*`, `porto-privacidade.*`, `porto-noticia-sao-joao.*`, `lisboa-inicio.*`, `lisboa-cm-inicio.*`. Invólucro de pedidos com registo por salto: `scratchpad/radar/scripts/pedido.sh`.
