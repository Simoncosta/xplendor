# Fonte IPMA (dados abertos): avisos e previsões por distrito

Radar r0, XPLENDOR. Leitura efetuada em 2026-10-12 entre as 19:15 e as 19:17 (hora de Lisboa, WEST). Só leitura, sem autenticação, sem dados pessoais.

## 1. Registo dos pedidos HTTP (limite: 4 por domínio)

| N | Domínio | Hora (local) | Pedido | Código | Redirecionamentos | Bytes |
|---|---------|--------------|--------|--------|-------------------|-------|
| 1 | api.ipma.pt | 2026-10-12T19:15:14+0100 | GET https://api.ipma.pt/ (página índice com os termos; curl sem -L) | 200 | 0 | 87 235 |
| 2 | api.ipma.pt | 2026-10-12T19:16:30+0100 | GET https://api.ipma.pt/open-data/forecast/warnings/warnings_www.json | 200 | 0 | 34 525 |
| 3 | api.ipma.pt | 2026-10-12T19:16:30+0100 | GET https://api.ipma.pt/open-data/forecast/meteorology/cities/daily/hp-daily-forecast-day0.json | 200 | 0 | 6 349 |
| 4 | api.ipma.pt | 2026-10-12T19:16:31+0100 | GET https://api.ipma.pt/open-data/distrits-islands.json | 200 | 0 | 10 071 |

Total: 4 pedidos a api.ipma.pt (limite atingido, nenhum excedido), 0 pedidos a www.ipma.pt. Não foram usados WebFetch nem outros domínios. As pesquisas web (WebSearch) não contam e serviram apenas para localizar a página de termos.

Ficheiros guardados (todos dentro do scratchpad):

- Termos: `scratchpad/radar/termos/ipma-api-index.html` (e versão em texto `ipma-api-index.txt`).
- Amostras: `scratchpad/radar/amostras/ipma/warnings_www.json`, `hp-daily-forecast-day0.json`, `distrits-islands.json`.
- Registo: `scratchpad/radar/amostras/ipma/registo-pedidos.log`.
- Somas SHA-256: warnings b0d7a5a0..., day0 2e01c514..., distrits fe67afbd... (completas no registo da sessão).

Ordem cumprida: os termos foram lidos e avaliados antes de qualquer amostra.

## 2. Termos de utilização (lidos em https://api.ipma.pt/, secção "termos e condições")

A página da API não tem uma licença nomeada (não refere Creative Commons nem outra). O que diz, por ordem de relevância para a XPLENDOR:

1. Atribuição obrigatória. Texto exato: "O Utilizador deve referir, sempre, a fonte de informação."
2. Logótipo e hiperligação em páginas web. Texto exato: "O logo-símbolo do sítio de internet do IPMA deve ser utilizado sempre que um Utilizador pretenda incluir informação disponibilizada pelo sítio de internet em páginas de hiper-documentos, devendo referenciar a hiper-ligação ao site do IPMA."
3. Pedido de notificação. Texto exato: "O IPMA solicita ainda que seja enviado um email para o endereço electrónico webmaster@ipma.pt a informar da sua utilização deste serviço e do seu propósito para efeitos de estatística e melhoria do serviço."
4. Cláusula de direitos de autor com restrição de fins lucrativos. Texto exato: "O Utilizador pode copiar, importar ou utilizar gratuitamente informações ou símbolos nacionais existentes neste site para uso pessoal ou público desde que dessa utilização não decorram finalidades lucrativas ou ofensivas."
5. Sem garantias e sem continuidade garantida: o IPMA "não pode garantir a não interrupção ou a não perturbação do serviço", não garante "rigor" nem "atualidade", e "reserva-se no direito de recusar ou retirar o acesso a este serviço, em qualquer momento e sem aviso prévio".
6. Página datada: "Copyright IPMA 2017"; a documentação usa exemplos de 2018 e 2021.

Avaliação para o fim pretendido (SaaS pago que mostra avisos e previsões descritivas por distrito, com fonte e hora visíveis):

- A leitura é permitida: a API é pública, sem autenticação, descrita pelo próprio IPMA como "dados abertos" ("open-data" no caminho) e destinada a ser "integrada aplicacionalmente". As amostras desta noite são uma leitura de avaliação, não um uso comercial, pelo que foram recolhidas.
- A exibição a clientes pagantes tem um risco contratual: a cláusula 4 restringe o uso gratuito a fins "sem finalidades lucrativas". Essa cláusula fala de "informações ou símbolos nacionais existentes neste site" e é de 2017. Contexto não verificado nesta ronda (conhecimento próprio, a confirmar por quem de direito): o Regulamento de Execução (UE) 2023/138 (conjuntos de dados de elevado valor, aplicável desde 9 de junho de 2024) inclui a categoria meteorológica, com avisos e dados de previsão, e exige disponibilização gratuita para reutilização, incluindo comercial, sob CC BY 4.0 ou licença equivalente. A pesquisa web confirmou apenas a existência e a data de aplicação do regulamento, não a sua aplicação formal pelo IPMA a estes ficheiros.
- Conclusão prática: antes de mostrar dados do IPMA a clientes, (a) enviar o email a webmaster@ipma.pt a descrever o uso (serviço SaaS XPLENDOR, leitura de 15 em 15 minutos, exibição descritiva por distrito com fonte, hora e logótipo), pedindo confirmação expressa do uso comercial; (b) guardar a resposta; (c) cumprir sempre a atribuição, o logótipo e a hiperligação. Sem a confirmação, limitar a funcionalidade a ambiente interno ou de demonstração.

### Frase de atribuição obrigatória (proposta, conforme os termos)

Os termos obrigam a referir a fonte, a usar o logótipo do IPMA e a hiperligação ao sítio do IPMA, mas não impõem uma frase fixa. Proposta a usar junto de cada aviso ou previsão exibidos:

> Fonte: IPMA, Instituto Português do Mar e da Atmosfera (www.ipma.pt). Dados obtidos em 12/10/2026 às 19:16; última atualização indicada pelo IPMA: 12/10/2026 17:31.

Acompanhada do logótipo do IPMA com hiperligação para https://www.ipma.pt. O campo `owner: "IPMA"` presente nos ficheiros JSON serve para preencher a fonte de forma automática. Os avisos de nível amarelo, laranja ou vermelho trazem um campo `text` com a redação do IPMA; quando se mostrar, deve ser citado tal e qual, identificado como texto do IPMA.

## 3. Endpoints, formato e campos

Base: https://api.ipma.pt/open-data/ . Formato JSON (UTF-8, `Content-Type: application/json`), ficheiros estáticos regenerados periodicamente, sem parâmetros de consulta, sem paginação, sem chave de API, sem CORS declarado (a leitura deve ser feita pelo servidor da XPLENDOR, nunca pelo browser do cliente).

### 3.1 Avisos meteorológicos até 3 dias

- URL: https://api.ipma.pt/open-data/forecast/warnings/warnings_www.json
- Formato observado: lista JSON de 200 objetos = 25 áreas de aviso x 8 tipos de aviso. O ficheiro inclui as linhas "green" (sem aviso), pelo que a presença de uma linha não significa aviso ativo; só contam `yellow`, `orange` e `red`.
- Campos (documentação e amostra coincidem):
  - `idAreaAviso` (string, 3 letras): área do aviso, por exemplo "LSB", "PTO", "FAR".
  - `awarenessTypeName` (string): tipo. Valores observados: "Agitação Marítima", "Nevoeiro", "Tempo Quente", "Tempo Frio", "Precipitação", "Neve", "Trovoada", "Vento".
  - `awarenessLevelID` (string): "green", "yellow", "orange", "red".
  - `startTime`, `endTime` (string "AAAA-MM-DDTHH:MM:SS", sem fuso): início e fim da validade.
  - `text` (string): redação do aviso, preenchida apenas para amarelo, laranja ou vermelho; vazia no verde.
- Amostra desta noite: 200 linhas, todas "green" (sem avisos ativos no país); `startTime` entre 2026-10-12T10:32 e 13:51; `endTime` entre 2026-10-15T10:00 e 13:00 (horizonte de 3 dias). Nenhum `text` preenchido, logo a redação real de um aviso ativo não foi observada.
- Não há campo de data de atualização neste ficheiro. A hora de emissão pode ser inferida do `startTime` mais recente; o fetcher deve guardar os cabeçalhos `Last-Modified` e `ETag` (não registados nesta ronda) e usar pedidos condicionais.
- Frequência declarada: a página não declara uma periodicidade para os avisos; declara apenas o horizonte ("até 3 dias") e remete para o guia do SAM em https://www.ipma.pt/pt/enciclopedia/otempo/sam/ (não lido, fora do orçamento de pedidos).

### 3.2 Previsão diária agregada (hoje, amanhã, depois de amanhã), uma linha por local

- URL: https://api.ipma.pt/open-data/forecast/meteorology/cities/daily/hp-daily-forecast-day{idDay}.json com `idDay` = 0 (hoje), 1 (amanhã), 2 (depois de amanhã). Lida a amostra `day0`.
- Formato observado: objeto com `owner` ("IPMA"), `country` ("PT"), `forecastDate` ("2026-10-12"), `dataUpdate` ("2026-10-12T17:31:03", sem fuso) e `data` (lista de 27 locais).
- Campos por local (amostra):
  - `globalIdLocal` (inteiro): identificador do local, chave de ligação à lista de locais.
  - `tMin`, `tMax`: temperaturas mínima e máxima do dia. Atenção ao tipo: inteiros na amostra, strings com decimais no exemplo da documentação; converter sempre para número.
  - `precipitaProb` (string, por exemplo "0.0"): probabilidade de precipitação em percentagem. A documentação lista o nome "probPrecipita", mas o ficheiro real usa "precipitaProb".
  - `idWeatherType` (inteiro): código do tipo de tempo; descrição em https://api.ipma.pt/open-data/weather-type-classe.json (não lido; a documentação confirma apenas o exemplo 2 = "Céu pouco nublado").
  - `classWindSpeed` (inteiro): classe de intensidade do vento; descrição em https://api.ipma.pt/open-data/wind-speed-daily-classe.json (exemplo documentado: 1 = "Fraco").
  - `predWindDir` (string): rumo predominante (N, NE, E, SE, S, SW, W, NW).
  - `classPrecInt` (inteiro, opcional): classe de intensidade da precipitação; presente só em 4 dos 27 locais; descrição em https://api.ipma.pt/open-data/precipitation-classe.json (exemplo documentado: 1 = "Fraco").
  - `latitude`, `longitude` (strings).
- Frequência declarada: os dados de previsão "são atualizados duas vezes por dia", corrida das 00 UTC "disponibilizada em regra a partir das 10:00" e corrida das 12 UTC "a partir das 20:00"; o ficheiro tem "taxa de atualização horária" (`dataUpdate`). Horas em UTC segundo a página ("As previsões são apresentadas em horas UTC"); no continente e na Madeira a hora legal é UTC+1 no verão; nos Açores UTC-1 no inverno e UTC no verão. O fuso de `dataUpdate` e de `startTime`/`endTime` não está explícito; convém confirmar comparando com `Last-Modified` numa próxima leitura.
- Existe também o ficheiro por local com 5 dias: https://api.ipma.pt/open-data/forecast/meteorology/cities/daily/{globalIdLocal}.json (mesmos campos, uma linha por dia; não lido).

### 3.3 Lista de locais (capitais de distrito e ilhas)

- URL: https://api.ipma.pt/open-data/distrits-islands.json
- Formato observado: objeto com `owner`, `country` e `data` com 35 locais: 24 no continente (18 capitais de distrito mais Guimarães, Loulé, Portimão, Sagres, Penhas Douradas e Sines), 2 na Madeira (Funchal, Porto Santo), 9 nos Açores (uma localidade por ilha).
- Campos: `globalIdLocal` (inteiro), `local` (nome), `idRegiao` (1 Continente, 2 Madeira, 3 Açores), `idDistrito` e `idConcelho` (códigos DICO), `idAreaAviso` (código da área de aviso), `latitude`, `longitude` (strings, graus decimais).
- Lista praticamente estática: basta ler uma vez por dia (ou por semana) e guardar.

## 4. Como ligar ao distrito e ao concelho

Chaves observadas:

- `idDistrito` segue o DICO: 1 a 18 no continente (1 Aveiro, 2 Beja, 3 Braga, 4 Bragança, 5 Castelo Branco, 6 Coimbra, 7 Évora, 8 Faro, 9 Guarda, 10 Leiria, 11 Lisboa, 12 Portalegre, 13 Porto, 14 Santarém, 15 Setúbal, 16 Viana do Castelo, 17 Vila Real, 18 Viseu); 31 Madeira, 32 Porto Santo; 41 a 49 Açores (uma ilha por código). `idConcelho` é o código do concelho dentro do distrito (DICO).
- `globalIdLocal` tem o padrão R DD CC xx (região, distrito, concelho, sufixo): 1110600 = região 1, distrito 11 (Lisboa), concelho 06 (Lisboa); 1081505 = Sagres (distrito 08, concelho 15, sufixo 05). Permite derivar distrito e concelho sem tabela auxiliar, mas a tabela deve mandar.
- `idAreaAviso` por distrito no continente (uma área por distrito): AVR, BJA, BRG, BGC, CBO, CBR, EVR, FAR, GDA, LRA, LSB, PTG, PTO, STM, STB, VCT, VRL, VIS. Madeira: MCS (Funchal) e MPS (Porto Santo) na lista; o ficheiro de avisos tem ainda MCN e MRM, que não aparecem na lista de locais (pelo padrão, costa norte e regiões montanhosas da Madeira; a confirmar). Açores: AOR (grupo oriental: Vila do Porto, Ponta Delgada), ACE (grupo central: Angra, Graciosa, Velas, Madalena, Horta), AOC (grupo ocidental: Santa Cruz das Flores, Vila do Corvo).
- Cadeia proposta para um cliente XPLENDOR: concelho do cliente (DICO) -> `idDistrito` -> `idAreaAviso` (via distrits-islands.json) -> filtrar `warnings_www.json` por `idAreaAviso` e nível diferente de "green". Para a previsão: `idDistrito` -> `globalIdLocal` da capital de distrito -> linha correspondente em `hp-daily-forecast-day{0,1,2}.json`.
- Limites honestos: os avisos são por área (distrito inteiro ou grupo de ilhas), não por concelho; a previsão agregada é para a capital de distrito (27 dos 35 locais tinham linha em day0: faltavam Guimarães, Loulé, Portimão, Vila do Porto, Santa Cruz da Graciosa, Velas, Madalena e Vila do Corvo). Na interface, rotular sempre "aviso para o distrito de X" e "previsão para X (capital de distrito)", nunca "previsão para o seu concelho".

## 5. Leitura de 15 em 15 minutos: plano técnico

- Um único fetcher no servidor (job agendado), nunca no browser do cliente. 3 ficheiros dinâmicos (avisos, day0, day1 ou day2) de 15 em 15 minutos = cerca de 290 pedidos por dia, bem abaixo de qualquer carga relevante, mas usar `If-None-Match`/`If-Modified-Since` e o User-Agent identificado "XPLENDOR-Radar/... (contacto no site da XPLENDOR)".
- Listas auxiliares (locais, tipos de tempo, classes de vento e de precipitação): ler uma vez por dia e guardar.
- Guardar sempre: hora do pedido, código HTTP, `Last-Modified`, `ETag`, `dataUpdate`, soma SHA-256 do corpo. Mostrar ao cliente a hora de obtenção e o `dataUpdate` (ou `startTime` no caso dos avisos).
- Tolerância a falhas: em 4xx/5xx ou JSON inválido, manter a última cópia boa e mostrar "dados de HH:MM, sem atualização desde então"; nunca inventar um estado "sem avisos" quando o ficheiro não foi lido.
- Validação de esquema a cada leitura: nomes dos campos (precipitaProb e não probPrecipita), tipos numéricos variáveis (strings e inteiros), `classPrecInt` opcional, novos valores de `awarenessTypeName` ou `idAreaAviso`.
- Horas: tratar os instantes como UTC (declaração da página) e converter para Europe/Lisbon ou Atlantic/Azores conforme `idRegiao`, até confirmação em contrário.
- Compliance: logótipo do IPMA com hiperligação para www.ipma.pt junto de cada bloco; email prévio a webmaster@ipma.pt; registo da resposta.

## 6. Ganchos descritivos por tipo de cliente

Regra fixa: o gancho descreve um facto meteorológico publicado pelo IPMA (nível, tipo, área, período) com fonte e hora. Nunca afirma uma causa nem um efeito sobre o negócio ("as vendas caíram por causa do calor" está proibido). A ligação ao negócio fica a cargo do cliente ou de uma sugestão separada e claramente marcada como sugestão.

Modelo de frase: "[Aviso NÍVEL de TIPO] no distrito de DISTRITO, de DIA às HORA até DIA às HORA. Fonte: IPMA, obtido às HH:MM." e "Previsão para hoje em CAPITAL (capital de distrito): TIPO DE TEMPO, mínima X graus, máxima Y graus, vento CLASSE de RUMO, probabilidade de precipitação P por cento. Fonte: IPMA, atualizado às HH:MM."

Restaurante (esplanada, reservas, entregas):
- "Aviso laranja de tempo quente no distrito de Lisboa até sexta às 18h. Fonte: IPMA, obtido às 10:15."
- "Aviso amarelo de precipitação no distrito do Porto, de terça às 18h a quarta às 9h. Fonte: IPMA."
- "Previsão para hoje em Faro (capital de distrito): céu pouco nublado, máxima de 26 graus, vento fraco de noroeste, probabilidade de precipitação 0 por cento. Fonte: IPMA, atualizado às 17:31."
- "Aviso amarelo de trovoada no distrito de Évora esta tarde até às 21h. Fonte: IPMA."

Stand automóvel (exposição exterior, test drives, entregas, lavagem):
- "Aviso laranja de vento no distrito de Setúbal até quinta às 12h. Fonte: IPMA, obtido às 08:00."
- "Aviso amarelo de nevoeiro no distrito de Viseu, hoje das 6h às 11h. Fonte: IPMA."
- "Previsão para amanhã em Braga (capital de distrito): chuva, probabilidade de precipitação 90 por cento, máxima de 18 graus. Fonte: IPMA, atualizado às 20:05."
- "Aviso amarelo de neve no distrito da Guarda, de sábado às 0h a domingo às 12h. Fonte: IPMA."

Consultor imobiliário (visitas, sessões de fotografia, obras, condomínios):
- "Aviso vermelho de agitação marítima para a área de Lisboa até sábado às 6h. Fonte: IPMA, obtido às 07:30."
- "Aviso laranja de precipitação no distrito de Coimbra, de quarta às 12h a quinta às 12h. Fonte: IPMA."
- "Previsão para o fim de semana em Évora (capital de distrito): céu limpo, máxima de 34 graus. Fonte: IPMA, atualizado às 10:02."
- "Aviso amarelo de tempo frio no distrito de Bragança até terça às 10h. Fonte: IPMA."

Nota de redação: os nomes dos tipos de aviso devem ser os do IPMA ("Tempo Quente", "Agitação Marítima", "Precipitação"), as cores devem ser as do IPMA (amarelo, laranja, vermelho) e o texto do campo `text`, quando existir, deve ser mostrado como citação do IPMA, sem reescrita.

## 7. Pendências e riscos

1. Confirmação do uso comercial pelo IPMA (email a webmaster@ipma.pt) antes de qualquer exibição a clientes pagantes; a cláusula "sem finalidades lucrativas" é o ponto crítico.
2. Não foi observado nenhum aviso ativo (todos "green"), pelo que a forma real do campo `text` e eventuais variações de `startTime` por aviso ficam por validar numa noite com avisos.
3. Listas de descrição (tipos de tempo, classes de vento e de precipitação) e o guia dos avisos (SAM) não foram lidos por limite de pedidos; a ler numa próxima ronda.
4. Fuso horário de `dataUpdate`, `startTime` e `endTime` não explícito no JSON; a confirmar.
5. Códigos MCN e MRM (Madeira) sem correspondência na lista de locais; o mapeamento da Madeira precisa de tabela manual ou do guia do SAM.
6. Cobertura por concelho não documentada na página lida; a API documentada cobre capitais de distrito e ilhas.

## 8. Fontes consultadas

- Página índice e termos da API do IPMA: https://api.ipma.pt/ (lida integralmente, pedido 1).
- Amostras: https://api.ipma.pt/open-data/forecast/warnings/warnings_www.json, https://api.ipma.pt/open-data/forecast/meteorology/cities/daily/hp-daily-forecast-day0.json, https://api.ipma.pt/open-data/distrits-islands.json (pedidos 2 a 4).
- Páginas referenciadas pela pesquisa web mas não lidas (sem pedidos): condições gerais do sítio em https://www.ipma.pt/opencms/pt/siteinfo/index.html e https://www.ipma.pt/resources.www/anpc/info_avisoslegais.html; guia dos avisos em https://www.ipma.pt/pt/enciclopedia/otempo/sam/; política de dados de outra plataforma do IPMA (agroclima, CC BY 4.0, não aplicável automaticamente à API) em https://agroclima.ipma.pt/pt/o-projeto/pol%C3%ADtica-de-dados-e-privacidade/; Regulamento de Execução (UE) 2023/138 em https://snig.dgterritorio.gov.pt/sites/default/files/documentos/860/Reg_Exec_2023_138.pdf.
