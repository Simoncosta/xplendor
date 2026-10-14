# Bússola Master: relatório do fecho

14 de outubro de 2026. O pedido está em `documents/bussola-master/PEDIDO-FECHO.md`; a noite anterior em `RELATORIO-MANHA.md`; o desenho em `DESENHO.md`; as respostas às perguntas da noite em `NOITE-PERGUNTAS.md`.

**Estado:** P1, P2, R2, R3 e R4 feitos, cada um com o seu commit na `main` local, todos com a suite completa (as 24 falhas conhecidas, nenhuma nova). Os dois testes de caracterização (restauração e stand) continuam verdes em todos os commits.

Garantias:
- Sem push nem deploy. Nada contra produção.
- Nenhuma chamada real a serviços externos além das três leituras autorizadas ao INE (condições da API, calendário ICS e lista oficial das freguesias, dentro de 4 pedidos por hora). Nenhuma recolha nova do Radar: a verificação no ecrã usou as tendências já guardadas.
- A IA foi sempre simulada: nos testes (respostas falsas dos fornecedores) e no ecrã (`AI_FAKE=true` só durante a verificação). Os 18 pedidos de IA da verificação ficaram registados como simulados e foram apagados com as empresas de demonstração.
- Trabalho por gravar de outra pessoa: nenhum; a árvore estava limpa no início e está limpa no fim.

## 1. O que ficou feito, por parte

| Parte | Commit | O que ficou |
|---|---|---|
| Pedido | `051980a` | O pedido em `PEDIDO-FECHO.md` e as respostas registadas no `NOITE-PERGUNTAS.md` |
| P1 e P2 | `641c5f3` | `RADAR_ENABLED` (falso por omissão em todos os ambientes) e as tarefas agendadas com IA como externas |
| R2 | `bb84df3` | Relevância, encaixe e cartão do Radar |
| R3 | `aa42d8b` | Imobiliário, DICOFRE e calendário oficial |
| R4 | `7dab10c` | Verificação no ecrã, capturas e as correções que saíram dela |
| Fecho | (este commit) | A permissão `imoveis` nos perfis que já existem, a leitura do INE só com imóveis (`--if-used`), este relatório, a lista das freguesias e a lista de deploy |

### P1. Radar desligado por omissão
- `RADAR_ENABLED` em `config/radar.php`, falso por omissão. Desligado: a recolha não faz nenhum pedido (nem a tarefa de 15 em 15 minutos, nem `radar:collect` sem `--force`), a relevância e o encaixe não correm, e Administração › Radar diz "Radar desligado". O módulo `radar` de cada empresa continua desligado até o root o ligar.
- Teste: `RadarSwitchTest` (5 testes).

### P2. Tarefas agendadas que chamam a IA
- A lista `ai_job_names` em `config/scheduler.php` diz quais chegam à IA; o teste confirma que todas estão em `external_job_names` e não correm com o `SCHEDULE_EXTERNAL_JOBS` desligado (o dev).
- As quatro tarefas com IA, todas externas:

| Tarefa | Como chega à IA | Antes |
|---|---|---|
| `restaurant-daily-sync` | as frases da Bússola da restauração, depois da sincronização | já era externa |
| `bussola-stand-nightly` | as frases da Bússola do stand, depois do recálculo | interna (chamou a IA real em dev a 12 de outubro) |
| `bussola-imoveis-nightly` | as frases da Bússola do imobiliário | nova, externa |
| `radar-collect-feeds` | a relevância e o "porque lhe interessa", a seguir à recolha | externa (e desligada também por `RADAR_ENABLED`) |

- Além disso, o `AI_FAKE` (R2) faz todas as funções de IA responderem sem rede fora de produção.
- Em produção nada muda: `SCHEDULE_EXTERNAL_JOBS` está ligado por omissão em produção.

### R2. Relevância, encaixe e cartão
- **IA:** o Haiku 5.5 no catálogo de modelos; as funções `radar_relevancia` (Haiku 5.5, esforço baixo) e `radar_porque` (Opus 5.5, médio), com modelo inicial próprio; ficam fora do "Aplicar a todas as funções" e do teste às cegas. `AI_FAKE` com respostas simuladas por função, ignorado em produção.
- **Relevância:** uma vez por tendência e por ramo, em lotes de 20, só nos ramos com o Radar ligado nalguma empresa; uma tendência retirada pelo filtro nunca entra num pedido. O custo fica em `radar_relevances`.
- **Encaixe:** desporto só com a opção (e só os clubes escolhidos), temas silenciados, temas e palavras a evitar do perfil da marca; ordem pela relevância, mais a zona e os pilares; no máximo 3 sinais por empresa e por dia (contam os ignorados). O "porque lhe interessa" pela IA, com o limite mensal de 100 por empresa; se a frase trouxer uma causa ou números, fica o modelo de frase.
- **Ecrãs:** o bloco "Radar" na Bússola, a seguir às jogadas, em todos os ramos (e sozinho numa empresa só com o Radar); o painel "Agora" na Linha Editorial; "Reagir" (ideia na etapa Ideia com o gancho, as fontes e o prazo, sem texto), "Ignorar", "Não mostrar temas como este" e as definições (desporto, clubes, temas silenciados); a caixa "Gancho e fontes" no Planeamento; o "Sugerir texto" recebe o gancho. Administração › Radar com a relevância por ramo, os sinais por empresa e o interruptor de cada empresa.
- **Testes:** `RadarSignalsTest` (17), `AiFakeTest` (4), Jest `RadarCard.test.tsx` (5) e os testes da Bússola e do painel do root.

### R3. Imobiliário, DICOFRE e calendário
- **Ramo e marca:** "Imobiliário" (folha de Universal) com o modelo do perfil da marca (versão `brand-templates-v3`).
- **Módulo e permissões:** `imoveis` com o preset `real_estate` para as empresas criadas com o ramo; a área `imoveis` pelo padrão dos Ficheiros.
- **Carteira:** a página "Imóveis" (lista e ficha), só com o que a Bússola usa; sem morada nem dados de pessoas; a classe energética marcada "em falta" quando não existe; o interesse em dois contadores manuais com a data.
- **Bússola do imobiliário:** imóvel novo; rever o preço do imóvel parado (90 dias na venda, 45 no arrendamento), com a referência do INE da freguesia quando existe (acima a partir de 10%; o concelho só como nota, sem comparar; nunca um preço sugerido); testemunho de quem fechou; a zona; o interesse; a decisão do BCE no próprio dia, com a ligação ao comunicado quando o Radar já o tiver.
- **Referência do INE:** o indicador 0012234 (valor mediano das vendas por m², últimos 12 meses), pelo comando `ine:housing-prices`; a frase de atribuição do INE tal e qual.
- **DICOFRE:** a lista oficial do INE (3092 freguesias, 308 concelhos) na tabela nova `official_parishes`; o código nas colunas novas `parishes.dicofre` e `municipalities.dico`, preenchidas pela migração de dados. Ver a secção 6.
- **Classe energética:** o aviso no separador Conteúdo e na aba da publicação, quando a publicação está ligada a um imóvel sem classe ou a legenda não a refere (Decreto-Lei 101-D/2020, artigo 18.º); não bloqueia.
- **Calendário (camada 2):** o BCE (reuniões de 2026 e 2027), o IMI, o IRS (só stand e imobiliário), o São João do Porto (só empresas do Porto) e as publicações de habitação do INE lidas no ICS (preços da habitação ao nível local a 23 de outubro; taxas de juro implícitas no crédito à habitação a 21 de outubro e 19 de novembro; avaliação bancária na habitação a 27 de outubro e 25 de novembro). "Reagir" a uma data cria a ideia sem texto, uma por data.
- **Testes:** `ImoveisCompassTest` (11), Jest `ImoveisBussola.test.tsx` e `ImoveisPage.test.tsx`.

### R4. Verificação no ecrã
- Empresas de demonstração claramente fictícias ("Tasca Demonstração", "Stand Demonstração", "Consultor Imobiliário Demonstração", todas com "(fictício)" no nome fiscal) e a XPLENDOR de dev; pessoas temporárias com e-mails `.invalid` (administradores, agência convidada e root).
- `AI_FAKE=true` e `RADAR_ENABLED=true` só durante a verificação; a amostra completa do INE (lida a 12 de outubro) carregada em dev.
- **Correções que saíram do ecrã:**
  1. O encaixe rebentava nas empresas com perfil da marca preenchido: os pilares são objetos (nome e descrição), não texto. Corrigido, com teste.
  2. Os botões "Ignorar" e "Não mostrar temas como este" (e os da carteira) quase não se viam no modo claro. Passaram a contorno cinzento e vermelho.
  3. A resposta simulada da relevância marcava só metade das tendências, e todas eram de desporto: com o desporto desligado não aparecia nenhum cartão. A simulação passou a marcar todas como relevantes (o filtro, o desporto e os temas da marca continuam a decidir).
- **No fim:** as empresas e as pessoas apagadas (`demo-limpar.php`), o Radar desligado na XPLENDOR de dev, os sinais, as relevâncias e os pedidos de IA simulados da XPLENDOR apagados, o `.env` reposto (cópia idêntica) e o worker reiniciado. `demo-confirmar.sh`: zero empresas fictícias, zero pessoas temporárias, zero órfãos (o único token órfão é antigo). Nenhum trabalho falhado na fila.
- **Ficou em dev:** a referência do INE (892 registos do 1.º trimestre de 2026) e a lista oficial das freguesias com os códigos. São dados de referência, não de demonstração.

## 2. Os testes

| Momento | Testes PHP | Falhas | Jest |
|---|---|---|---|
| Início do fecho (depois do R1) | 1929 | 24 conhecidas | 92 |
| P1 e P2 | 1935 | 24, nenhuma nova | 93 |
| R2 | 1955 | 24, nenhuma nova | 100 |
| R3 | 1966 | 24, nenhuma nova | 105 |
| R4 e fecho | 1967 | 24, nenhuma nova | 105 |

- As 24 falhas são as de sempre (10 `CarControllerTest`, 2 `AuthTest`, 9 `StockPromotionControllerTest`, 3 `Tests\Unit`).
- Jest: só a falha conhecida do `App.test.tsx`. TypeScript e ESLint limpos nos ficheiros alterados.

## 3. As capturas

36 ficheiros em `documents/bussola-master/capturas/`, cada um em claro e escuro, a 1440 por 900 (computador) e 390 por 844 (telemóvel). Só dados fictícios; da XPLENDOR de dev só cortes do bloco do Radar e do painel do root (nunca o menu lateral, que lista empresas reais, nem o separador "Empresas"). As tendências mostradas são títulos públicos das fontes; o sinal com nome de pessoa foi tirado antes das capturas.

| N.º | Assunto |
|---|---|
| 01 | Imobiliário: a página completa (topo, jogadas, Radar, "Vem aí", blocos) |
| 02 | Imobiliário: as jogadas (rever o preço com a referência do INE, testemunho, zona) |
| 03 | Imobiliário: o bloco do Radar ("Está a subir" e "Vem aí" com as fontes oficiais) |
| 04 | Imobiliário: o preço por m² face à referência do INE, com a atribuição |
| 05 | Imóveis: a carteira |
| 06 | Imóveis: a ficha de um imóvel |
| 07 | Linha Editorial: o aviso da classe energética em falta |
| 08 | Linha Editorial: o painel "Agora" |
| 09 | Stand: o bloco do Radar a seguir às jogadas |
| 10 | Linha Editorial: "Gancho e fontes" numa ideia do Radar |
| 11 | Restauração: a Bússola desligada e o Radar por baixo |
| 12 e 13 | Agência convidada: as jogadas e o Radar do imobiliário |
| 14 | Root na XPLENDOR: o bloco do Radar |
| 15 | Root: Administração › Radar, a relevância por ramo |
| 16 | Root: Administração › Radar, as freguesias e o INE |
| 17 e 18 | Telemóvel: a Bússola do imobiliário e a carteira (sem deslocamento horizontal) |

As ferramentas para repetir a verificação estão em `documents/bussola-master/ferramentas/` (`demo-criar.php`, `demo-limpar.php`, `demo-confirmar.sh`, `correr.sh`, `capturas.cjs`).

## 4. As decisões tomadas por omissão, para confirmar

IA e Radar:
1. As funções do Radar formam um grupo próprio: ficam fora do "Aplicar a todas as funções" (a relevância não passa ao Opus sem querer) e do teste às cegas.
2. O limite mensal do "porque lhe interessa" é 100 pedidos por empresa (`RADAR_PORQUE_AI_MONTHLY_CAP`).
3. Os sinais e as relevâncias apagam-se com a tendência, aos 30 dias.
4. **As ideias criadas pelo "Reagir" guardam o gancho e as fontes (títulos e ligações) na publicação, sem prazo de apagamento**, porque passam a ser conteúdo da empresa. Ver a pergunta 4.
5. Do Google Trends, o cartão mostra a consulta, o volume e as fontes pelo nome e pela ligação; o título das notícias de terceiros não se mostra.
6. "Não mostrar temas como este" guarda o tema que a IA deu à tendência para o ramo da empresa e o título normalizado.
7. A área "Bússola" vale também com o módulo `radar` ou `imoveis`; o menu e a rota da Bússola abrem com qualquer um.

Imobiliário:
8. Só a sugestão "Agência convidada" ganha a área `imoveis` (ver, criar, editar); as sugestões "Marketing", "Só leitura" e "Gestor de clientes" não (o administrador acrescenta). Ver a pergunta 5.
9. "Rever o preço" aparece para os imóveis disponíveis (não os reservados) a partir dos limiares; a comparação com o INE só na venda (o INE publica preços de venda) e só com a área útil preenchida.
10. "Rever o preço" não cria publicação (é uma conversa com o proprietário): só "Ver imóvel".
11. A ordem das jogadas: BCE, imóvel novo, preço, testemunho, zona, interesse.
12. O testemunho conta como feito quando há uma publicação ligada ao imóvel com data igual ou posterior ao fecho.
13. "Aproveitar o interesse" com 3 ou mais contactos, contados há 30 dias ou menos.
14. O aviso da classe energética só nas publicações ligadas a um imóvel pela Bússola do imobiliário. Ver a pergunta 3.
15. A legenda conta como referindo a classe quando tem "classe energética" seguida da classe.

Calendário, INE e freguesias:
16. O IMI aparece como o mês inteiro do prazo (maio, agosto, novembro); o "Vem aí" mostra os próximos 30 dias.
17. As publicações do INE entram só no imobiliário e só as de habitação (três séries). O ICS do INE cobre cerca de seis semanas: a lista em código tem de ser revista todos os meses. Ver a pergunta 6.
18. A tarefa do INE corre no dia 25 de cada mês às 04:50, só com alguma empresa com imóveis (`--if-used`); não repete um período já gravado.
19. A lista oficial das freguesias é a do INE (Censos 2021, 3092 freguesias). Ver a pergunta 1.

## 5. A diferença na fotografia da ACL

Registada no `documents/acl/F3-DECISOES.md`, secções 6e e 6f, e na tabela da secção 7 (corrigida com a coluna do commit `f869922`, que a tabela não contava).

| Momento | Rotas por ator | O que muda |
|---|---|---|
| Antes (com o F2d de `f869922`) | 399 | `sem_modulos_admin` com 253 proibidas (a tabela dizia 249) |
| R2 | 406 | 7 rotas novas do Radar; só o administrador sem módulos recebe 403 nelas (253 para 260) |
| R3 | 418 | 12 rotas novas (Bússola do imobiliário e carteira); o administrador sem módulos recebe 403 nas 12 (260 para 272); quem não tem `imoveis.apagar` recebe 403 só em apagar um imóvel (utilizador, aprovador, os três atores da agência e o root a impersonar um utilizador, mais 1 cada) |

**Nenhum ator ganha nem perde acesso a rotas que já existiam:** em cada ator, o conjunto das rotas proibidas que já existiam é igual antes e depois (verificado com a comparação das fotografias). Nenhuma alteração mudou o acesso a uma rota antiga.

## 6. As freguesias sem DICOFRE

A migração casou os 306 concelhos e 2861 das 2873 freguesias da plataforma (dev), sem códigos repetidos. A lista completa está em `documents/bussola-master/FREGUESIAS-DICOFRE.md`.

**Sem código (12):** São Tiago dos Velhos (Arruda dos Vinhos); Norte Pequeno, Santo Antão e Topo (Nossa Senhora do Rosário) (Calheta, Açores); Água de Pau, Cabouco, Lagoa (Nossa Senhora do Rosário), Lagoa (Santa Cruz) e Ribeira Chã (Lagoa, Açores); Soito (Sabugal); Foz do Douro (Vila Nova de Gaia na tabela da plataforma, mas é do Porto); União das freguesias de Faíl e Vila Chã de Sá (Viseu). São grafias diferentes, freguesias que entraram numa união em 2013 ou uma freguesia no concelho errado.

**Na lista oficial e não na plataforma (231):** sobretudo uniões e freguesias que a tabela da plataforma não tem. Nada foi apagado nem acrescentado.

Em produção, os números serão os da tabela de produção; o painel do root mostra-os depois da migração.

## 7. As perguntas pendentes

1. **A lista oficial das freguesias:** usei a do INE dos Censos 2021 (3092 freguesias). As freguesias repostas em 2025 ainda não estão nas geografias do INE que li. Quando o INE publicar a geografia nova, refaz-se a ligação com uma migração nova?
2. **As 12 freguesias sem código:** corrigem-se à mão (o nome ou o concelho, com o código oficial), ou ficam assim até haver um imóvel nelas?
3. **O aviso da classe energética** só vê as publicações criadas pela Bússola do imobiliário (ligadas ao imóvel). Uma publicação criada à mão na Linha Editorial sobre um imóvel não é detetada. Quer uma forma de ligar uma publicação a um imóvel no painel da publicação?
4. **As ideias do "Reagir"** guardam os títulos e as ligações das fontes na publicação, sem apagamento aos 30 dias (são conteúdo da empresa a partir do momento em que alguém reage). Confirma?
5. **As sugestões de perfil** "Marketing" e "Gestor de clientes" devem ver e editar os imóveis?
6. **O calendário do INE:** a lista em código precisa de revisão mensal (o ICS só cobre seis semanas). Quer que o ICS do INE passe a ser lido por uma tarefa (uma vez por semana), com as mesmas regras de fonte?
7. **O filtro do Radar:** "Grupo Lufthansa vence processo de privatização da TAP" passou o filtro (não tem nenhuma palavra das listas de política). Acrescenta-se "privatização" à política, ou fica como economia?
8. **O IPMA e os três jornais:** continuam à espera do email ao IPMA e da autorização escrita dos jornais (perguntas 13 e 14, ficaram consigo). O IPMA continua desligado.
