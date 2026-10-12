# Fonte INE: valor mediano das vendas por m² por freguesia (Radar r0)

Data da recolha: 2026-10-12, 19:23 a 19:26 WEST. Exceção de leitura de dados públicos reais, só leitura, sem autenticação, sem dados pessoais. Limite rígido: 4 pedidos HTTP ao domínio www.ine.pt, todos gastos, todos com HTTP 200 e zero redirecionamentos. User-Agent usado em todos: `XPLENDOR-Radar/0.1 (leitura de dados abertos; contacto no site da XPLENDOR)`.

## 1. Registo dos pedidos ao INE (4 de 4)

| N.º | Hora (WEST) | URL | HTTP | Redir. | Bytes | Ficheiro guardado |
|---|---|---|---|---|---|---|
| R1 | 2026-10-12 19:23:26 | https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_pufs_termos&contexto=up&xlang=pt | 200 | 0 | 65 279 | termos/ine/termos-condicoes.html (+ .txt) |
| R2 | 2026-10-12 19:23:57 | https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_api | 200 | 0 | 46 210 | termos/ine/api-docs-ine_api.html (+ .txt) |
| R3 | 2026-10-12 19:25:05 | https://www.ine.pt/ine/json_indicador/pindicaMeta.jsp?varcd=0012234&lang=PT | 200 | 0 | 228 193 | amostras/ine/pindicaMeta-0012234.json |
| R4 | 2026-10-12 19:25:35 | https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0012234&Dim1=S5A20261&lang=PT | 200 | 0 | 573 226 (1,33 s) | amostras/ine/pindica-0012234-S5A20261.json |

Registo bruto: `amostras/ine/pedidos-ine.log`. Todos os caminhos são relativos a `(scratchpad)/radar/`. Não foi feito nenhum outro pedido a ine.pt (nem WebFetch). As pesquisas Web e os pedidos a dados.gov.pt, github.com, letras.up.pt, mra-bio.me e idee.es não contam e estão listados na secção 7.

## 2. Condições de utilização dos dados do INE (R1, lido na íntegra)

Página: "Termos e Condições" dos Ficheiros de Uso Público, que reproduz os "TERMOS E CONDIÇÕES DE UTILIZAÇÃO DO PORTAL DO INE". Pontos relevantes, por ordem do texto:

- "O acesso ao portal do INE é um serviço gratuito."
- "Os direitos de propriedade intelectual de todos os conteúdos presentes neste portal são propriedade do INE."
- Licença: "A informação estatística disponível neste Portal é gratuita e pode ser usada e reutilizada pelos utilizadores de acordo com a licença Creative Commons CC BY Atribuição 4.0 devendo contudo ser claramente identificada a fonte da informação nos seguintes termos:"
- **Frase de atribuição exigida (verbatim):**
  `Fonte: Instituto Nacional de Estatística, IP – Portugal`
  `(identificação da informação estatística referida, período de referência da informação)`
- Uso comercial: "O utilizador pode descarregar ou copiar informação estatística para uso pessoal ou comercial, mantendo-se o INE titular dos respetivos direitos de propriedade."
- Modificação: "Se a informação for modificada pelo utilizador, tal facto deve ser explicitamente mencionado."
- O INE "reserva-se o direito de adicionar ou alterar os termos e condições de utilização sem aviso prévio"; pode suspender o serviço; exclusão de responsabilidade; lei portuguesa, foro da Comarca de Lisboa.

Aplicação ao XPLENDOR (proposta de texto a mostrar junto do valor, em cada ecrã e exportação):
`Fonte: Instituto Nacional de Estatística, IP – Portugal, Estatísticas de preços da habitação ao nível local, valor mediano das vendas de alojamentos familiares nos últimos 12 meses (€/m²), 1.º Trimestre de 2026.`
Se o XPLENDOR calcular algo a partir do valor (variação, média de freguesias, arredondamento), tem de o dizer explicitamente ("cálculo XPLENDOR a partir de dados INE").

Pendente: a página da API (ine_api_v2) afirma, segundo o excerto do motor de pesquisa, que "a API do INE está sujeita a condições de utilização próprias". Essa página não foi lida por limite de pedidos; é a primeira leitura a fazer na próxima janela.

## 3. Documentação da API (R2 e pesquisa)

R2 (URL indicada no pedido) devolveu apenas uma página de menu, na versão inglesa do portal, com as entradas "Autocomplete API", "Services and Feeds (RSS, ICS)", "API (Data Base)" e "API (Autocomplete)". A documentação da API da base de dados vive hoje em:
- https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_api_v2&xlang=pt (entrada "API - Dados")
- https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_api_db&xlang=pt (extração de dados)
- https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_api_meta&xlang=pt (metainformação)
- https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_api_catalogo&xlang=pt (catálogo)
- Manual em PDF: https://www.ine.pt/ngt_server/attachfileu.jsp?look_parentBoui=322788851&att_display=n&att_download=y ("API - Base de Dados de Difusão")
Nenhuma destas foi descarregada (limite). O que se segue vem dos excertos das pesquisas e foi confirmado na prática por R3 e R4.

Endpoints:
- Dados: `https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd={7 dígitos}&Dim1={período}&Dim2={geografia}&Dim3=...&lang=PT|EN`
- Metainformação: `https://www.ine.pt/ine/json_indicador/pindicaMeta.jsp?varcd={código}&lang=PT`
- Catálogo (XML): `https://www.ine.pt/ine/xml_indic.jsp?opc=1&varcd={código}&lang=PT`
- Ligação permanente ao indicador no portal: `https://www.ine.pt/xurl/indx/{código}/PT`; metainformação humana: `https://www.ine.pt/bddXplorer/htdocs/minfo.jsp?var_cd={código}&lingua=PT`

Parâmetros de período (Dim1): a documentação exemplifica só o anual (`S7A2015`), aceita `Dim1=T` (todos os períodos) e listas separadas por vírgula (`S7A2019,S7A2020`). Confirmado em R3: nos indicadores trimestrais o código é `S5A` + ano + trimestre, por exemplo `S5A20194` (4.º Trimestre de 2019) e `S5A20261` (1.º Trimestre de 2026). Mensal: não verificado.

Limite de volume: o pacote R ineptR (terceiro, não oficial) afirma que cada chamada está limitada a 40 000 células. Para o 0012234, pedir todos os períodos daria 26 × 2 793 = 72 618 células, acima desse limite; pedir um período (Dim1) dá 2 793. Conclusão: pedir sempre com Dim1.

Como descobrir códigos no portal: Base de Dados, separador "Alterar condições de seleção", mudar "Árvore" para "Códigos".

## 4. Indicador alvo (tarefa a)

Nome oficial atual (R3): **"Valor mediano das vendas de alojamentos familiares nos últimos 12 meses (Metodologia 2022 - €/ m²) por Localização geográfica (NUTS - 2024) e Categoria do alojamento familiar; Trimestral - INE, Estatísticas de preços da habitação ao nível local (Metodologia 2022)"**. O nome pedido ("Valor mediano das vendas por m² de alojamentos familiares (€) por Localização geográfica (Freguesia)") é a designação de 2017 a 2021 (Metodologia 2018), hoje descontinuada; o sucessor direto é este.

- **varcd: 0012234**
- Periodicidade: Trimestral. Unidade: Euro/ Metro quadrado (€/ m²), 0 casas decimais.
- Primeiro período: 4.º Trimestre de 2019. **Último período disponível: 1.º Trimestre de 2026** (vendas de abril de 2025 a março de 2026). Data da última atualização: 2026-07-17.
- Dimensão 1, "Período de referência dos dados (Trimestre)": 26 categorias, códigos `S5A20194` a `S5A20261`.
- Dimensão 2, "Localização geográfica (NUTS - 2024)", versão 05431: 931 categorias em 6 níveis: nível 1 Portugal (`PT`), nível 2 Continente/RAA/RAM (`1`, `2`, `3`), nível 3 NUTS II (9, ex. `11` Norte), nível 4 NUTS III (26, ex. `11D` Douro), **nível 5 municípios (308, código de 7 carateres = NUTS III + DICO, ex. `1A01106` Lisboa)**, **nível 6 freguesias (584, código de 9 carateres = NUTS III + DICOFRE, ex. `1A0110601` Ajuda, DICOFRE 110601)**. Os 6 últimos carateres do código de freguesia são o DICOFRE, o que permite cruzar com a CAOP.
  Nota oficial da dimensão: "A desagregação geográfica ao nível da freguesia ocorre apenas nos municípios da área metropolitana do Porto, regiões da Grande Lisboa, Península de Setúbal, Algarve e outros municípios com mais de 100 mil habitantes (Censos 2021)." Na prática: 58 municípios têm freguesias na dimensão; Lisboa tem as 24.
- Dimensão 3, "Categoria do alojamento familiar": na metainformação `H.1` Total, `H.1.1` Novos, `H.1.2` Existentes; **nos dados os códigos vêm sem pontos** (`H1`, `H11`, `H12`). O parser deve normalizar ou usar `dim_3_t`.
- Tipologia: não existe neste indicador. Existe no irmão **0012241** ("... por Localização geográfica (NUTS - 2024) e Tipologia do fogo; Trimestral", mesma dimensão geográfica, atualizado a 2026-07-17); não foi descarregado.

Indicador por município: é o **mesmo 0012234**, nível 5 da dimensão geográfica (308 municípios; no 1.º T 2026, 304 têm valor Total). Não há indicador separado "por Município" na família NUTS 2024 listada no dados.gov.pt. Códigos relacionados, para referência:
- 0012246 (NUTS 2024 × Domicílio fiscal do comprador), 0012236 (× Setor institucional do comprador), ambos "últimos 12 meses", trimestrais.
- 0012239 e 0012240: valor do trimestre (não 12 meses), NUTS 2024 × Domicílio fiscal / Setor institucional.
- 0012227 a 0012231: versões "Cidade" e "Cidades com mais de 100 000 habitantes".
- Antecessores: 0011364 (Metodologia 2022, NUTS 2013 × Categoria, última atualização 2024-04-23), 0011369 (Metodologia 2022, concelhos, segundo letras.up.pt, não verificado), 0009484 (Metodologia 2018, freguesias de AML/AMP/Algarve, descontinuado 2022-02-01), 0009490 (Metodologia 2018, NUTS 2013 e municípios).
- Anuais: 0012255 (NUTS 2024 × Categoria), 0012254 (NUTS 2024 × Tipologia).

## 5. Amostra obtida (R4)

URL: `https://www.ine.pt/ine/json_indicador/pindica.jsp?op=2&varcd=0012234&Dim1=S5A20261&lang=PT`
Dimensão da resposta: **573 226 bytes** (JSON, UTF-8; 37 688 bytes em gzip), 1,33 s. A metainformação (R3) tem 228 193 bytes.

Estrutura (lista com um objeto):
```
[{"IndicadorCod":"0012234","IndicadorDsg":"...","MetaInfUrl":"https://www.ine.pt/bddXplorer/htdocs/minfo.jsp?var_cd=0012234&lingua=PT",
  "DataExtracao":"2026-10-12T19:25:35.363+01:00","DataUltimoAtualizacao":"2026-07-17","UltimoPref":"1.º Trimestre de 2026",
  "Dados":{"1.º Trimestre de 2026":[
     {"geocod":"11D1818","geodsg":"Sernancelhe","dim_3":"H12","dim_3_t":"Existentes","ind_string":"260","valor":"260"},
     {"geocod":"1111603","geodsg":"Melgaço","dim_3":"H11","dim_3_t":"Novos","sinal_conv":"-","sinal_conv_desc":"Dado nulo ou não aplicável","ind_string":"-"} ]},
  "Sucesso":{"Verdadeiro":[{"Msg":"OK"}]}}]
```
Pontos a reter para o parser:
- A chave de `Dados` é a designação do período ("1.º Trimestre de 2026"), não o código Dim1.
- 2 793 registos = 931 geografias × 3 categorias. 1 092 registos não têm o campo `valor`; trazem `sinal_conv` "-" e `sinal_conv_desc` "Dado nulo ou não aplicável" (abaixo do limiar de difusão). O campo `valor` é string.
- Cobertura no 1.º T 2026, categoria Total: 350 das 584 freguesias com valor; 304 dos 308 municípios; todos os níveis superiores.
- Valores de controlo (Total, €/m²): Portugal 2 168; Lisboa 5 082; Cascais 4 687; Oeiras 4 297; Porto 3 510. Freguesias de Lisboa: Santo António 6 986 (máximo nacional), Parque das Nações 6 407, Avenidas Novas 6 000, Santa Clara 3 733 (mínimo em Lisboa). Mínimo nacional de freguesia: Almalaguês (Coimbra) 667. Batem certo com o destaque do INE de 17-07-2026 (Portugal 2 168, Lisboa 5 082).

Plano de leitura trimestral para o XPLENDOR (2 pedidos por trimestre): (1) `pindicaMeta.jsp?varcd=0012234` para ler `UltimoPeriodo` e o `categ_cod` correspondente; (2) `pindica.jsp?op=2&varcd=0012234&Dim1={código}` e guardar, por geocod de 9 carateres (freguesia) e de 7 (município), o `valor` da categoria `H1`, o período (`UltimoPref`), `DataUltimoAtualizacao` e a frase de atribuição. Guardar também `sinal_conv` para mostrar "sem valor publicado" em vez de zero.

## 6. Calendário de difusão e RSS (tarefa b)

Nenhum destes URL foi pedido nesta janela (limite); vêm de pesquisa Web com resultados do próprio ine.pt. Formato a confirmar com um pedido cada na próxima janela.
- Calendário de difusão (HTML): https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_calendarios&xlang=pt . Regra geral de publicação: 11h00; destaques com difusão simultânea europeia: 9h30; o INE pode publicar destaques fora do calendário.
- Calendário em iCalendar (ICS): http://www.ine.pt/ine/calendario_ics.jsp?lang=PT ("ICS - Calendário Destaques do INE").
- RSS dos destaques: https://www.ine.pt/ine/rssfeed_dst.jsp?lang=PT (título do canal: "Portal do INE - Canal Rss - Notas de Imprensa"; itens com data em formato GMT). Existe também RSS de publicações (URL não confirmado).
- Página índice dos feeds (RSS, ICS e JSON): https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ics_feeds&xlang=pt . Lista "Json Feed - Destaques", "Json Feed - Últimos Destaques", "Json Feed - Publicações", "Json Feed - Calendário" e "Json Feed - Calendário Previsto" (ficheiros originalmente usados pela app "Ine Mobile"); os URL JSON não foram capturados.
- Página "Calendário - Disponibilidade de Informação Estatística": https://www.ine.pt/xportal/xmain?xpid=INE&xpgid=ine_dispinfest&xlang=pt .
- Próxima publicação das Estatísticas de Preços da Habitação ao nível local (2.º Trimestre de 2026): não confirmada; o 1.º T 2026 saiu a 17-07-2026 e o 4.º T 2025 em abril de 2026, pelo que a data provável é o final de outubro de 2026. Confirmar no calendário ou no ICS.

## 7. Fontes auxiliares (fora de ine.pt, não contam para o limite)

- dados.gov.pt, API `https://dados.gov.pt/api/1/datasets/?q=...` (3 pedidos, 200): 40 conjuntos INE da família "valor mediano das vendas", todos com licença `cc-by`, com os URL pindica/pindicaMeta/xurl por código. Guardado em amostras/dadosgov/.
- github.com/c-matos/ineptR (README, vinheta, R/utils.R, R/get_ine_data.R): URL base, limite de 40 000 células, Dim1 anual S7A.
- web2.letras.up.pt/portalhabitacional/PrecosVenda.html: 0009484 = freguesias, 0011369 = concelhos (séries antigas).
- mra-bio.me (estudo AML, atualizado 18-07-2026): mostra freguesia, concelho, tipologia e domicílio fiscal para o 1.º T 2026, coerente com os indicadores 0012234/0012241/0012246.
- idee.es, apresentação INE JIIDE 2017 (PDF guardado em amostras/externas/): estrutura original dos indicadores e limiares de difusão (15 transações nos 4 trimestres; 7 ao nível de secção e GRID). O destaque do 1.º T 2026 refere hoje um mínimo de 33 vendas por unidade territorial.

## 8. Pendências para a próxima janela de pedidos

1. Ler ine_api_v2 (condições próprias da API) e a página ics_feeds (URL dos JSON).
2. Um pedido ao RSS e um ao ICS para fixar o formato.
3. Metainformação do 0012241 (tipologia) se o produto precisar de T0 a T4+.
4. Confirmar a data de difusão do 2.º T 2026 e testar a leitura com o novo `UltimoPeriodo`.
