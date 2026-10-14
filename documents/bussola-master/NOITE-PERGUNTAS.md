# Bússola Master: perguntas da noite

Decisões de produto que não estavam no pedido. O trabalho seguiu com o valor indicado em "Até decidir", que se muda sem obras grandes. As decisões técnicas por omissão estão no `DESENHO.md` (secção 10).

## 1. Empresa sem ramo na Linha Editorial

A relevância é por ramo (`companies.content_sector_id`). Uma empresa sem ramo escolhido não tem ramo para avaliar.

**Até decidir:** sem ramo não há sinais do Radar; o bloco diz "Escolha o ramo da sua marca na Linha Editorial para o Radar saber o que lhe interessa."

**Alternativa:** avaliar essas empresas com o ramo "Universal" (mais ruído, mais custo).

**Resposta:** confirmada como proposta (14 de outubro).

## 2. O Radar sozinho na página da Bússola

Numa empresa cuja Bússola do ramo está desligada (ou que não tem fornecedor da camada 1, como uma empresa de software), o Radar aparece sozinho na página da Bússola?

**Até decidir:** sim. A página mostra o cartão "desligada" do ramo (quando há ramo) e, por baixo, o Radar; sem fornecedor, só o cabeçalho e o Radar. O menu e a rota da Bússola abrem também com o módulo `radar`.

**Resposta:** confirmada como proposta.

## 3. O interruptor do Radar

**Até decidir:** um módulo `radar` no registo dos módulos, fora dos presets, que o root liga no ecrã dos módulos da empresa ou no painel Administração › Radar. Em dev fica ligado na XPLENDOR e nas empresas de demonstração.

**Alternativa:** uma coluna na empresa e um comando (`radar:switch`), como a Bússola do stand. O comando existe na mesma, por comodidade.

**Resposta:** confirmada como proposta.

## 4. As definições do Radar (desporto, clubes, temas silenciados)

**Até decidir:** uma janela "Definições do Radar" aberta a partir do próprio cartão (o menu "Mais ações" do bloco), para quem tem `bussola.editar`. Os clubes escrevem-se como etiquetas livres (por exemplo "Benfica", "Sporting", "FC Porto").

**Alternativa:** um cartão no perfil da empresa (separador "Linha Editorial"), ou uma lista fixa de clubes.

**Resposta:** confirmada como proposta.

## 5. "Reagir" com o mês fechado na Linha Editorial

**Até decidir:** como na Bússola: o servidor recusa com a mensagem e o ecrã mostra a ligação "Abrir a Linha Editorial". O prazo nunca é uma data passada.

**Resposta:** confirmada como proposta.

## 6. O custo da relevância em "Modelos de IA"

A relevância é por ramo, não por empresa, e a tabela `ai_requests` exige uma empresa. O custo fica em `radar_relevances` e soma-se em Administração › Radar; não aparece em "Custo este mês" de Modelos de IA.

**Até decidir:** fica assim, com a nota no ecrã de Modelos de IA a dizer onde está o custo.

**Alternativa:** registar os pedidos da relevância em nome da XPLENDOR (a empresa da plataforma), para aparecerem em Modelos de IA.

**Resposta:** confirmada como proposta.

## 7. O ramo "Imobiliário" e o módulo `imoveis`

**Até decidir:** o ramo "Imobiliário" é uma folha de "Universal"; o módulo `imoveis` (a carteira e a Bússola do imobiliário) nasce desligado e tem um preset `real_estate` (marketing, suporte, Linha Editorial e imóveis) para as empresas criadas com esse ramo. A área de permissão `imoveis` segue o padrão dos Ficheiros: o Administrador tudo; o Utilizador ver, criar e editar; a agência convidada ver, criar e editar, sem apagar.

**Alternativa:** a área `imoveis` igual à `automovel` (tudo para todos).

**Resposta:** confirmada como proposta.

## 8. O interesse nos imóveis

Não há site nem portal ligado aos imóveis.

**Até decidir:** dois contadores no formulário do imóvel, atualizados à mão ("contactos recebidos nos últimos 30 dias" e "visitas marcadas"), com a data da última atualização à vista. A Bússola diz sempre que são contagens manuais.

**Alternativa:** sem interesse na primeira versão (a jogada "Aproveitar o interesse" não existe no imobiliário).

**Resposta:** confirmada como proposta.

## 9. Os limiares do imobiliário

**Até decidir:** "parado" a partir de 90 dias (venda) e 45 (arrendamento); face à referência do INE, "acima" a partir de 10% acima do valor mediano da freguesia; sem valor da freguesia, não se compara. Nunca se sugere um preço.

**Resposta:** confirmada como proposta.

## 10. A ligação entre as freguesias e a referência do INE

A tabela das freguesias não tem o código oficial (DICOFRE).

**Até decidir:** a ligação faz-se pelo nome normalizado da freguesia dentro do concelho; as que não casam ficam "sem referência" e o painel do root lista-as.

**Alternativa:** acrescentar o código DICOFRE à tabela das freguesias (uma migração de dados com a tabela oficial).

**Resposta:** a ligação faz-se pelo código oficial DICOFRE. Acrescenta-se o código à tabela das freguesias com uma migração de dados a partir da tabela oficial; o nome normalizado só ajuda a casar durante a migração. As freguesias que não casarem e as diferenças com a tabela oficial (uniões e desagregações) ficam listadas no painel do root e no relatório. Nada é apagado.

## 11. "Explicar a decisão do BCE no próprio dia"

**Até decidir:** a jogada aparece só no ramo imobiliário, no dia de cada reunião de política monetária do BCE (calendário oficial), com a ligação para o comunicado quando o feed o trouxer. Não explica a decisão: dá a data e a ligação.

**Resposta:** confirmada como proposta.

## 12. Quantas tendências por dia e de que fontes

Pelas amostras (`DESENHO.md`, secção 3): o Google Trends dá 100 a 150 tendências novas por dia, de que o filtro tira cerca de 60% com o desporto desligado; os três jornais dão 300 a 400 títulos por dia, de que resultam 10 a 20 tendências com dois jornais; o BCE dá 8 comunicados de decisão por ano. Chegam à relevância cerca de 50 tendências por dia.

**Até decidir:** as fontes ligadas são o Google Trends, o ECO, o Público, o Observador e o RSS do BCE. Os feeds de secção dos jornais (Economia, Cultura) não foram verificados esta noite; quando forem, podem substituir os feeds gerais e baixar o ruído.

**Resposta:** confirmada como proposta.

## 13. O IPMA e a cláusula de fins não lucrativos

Os termos da API do IPMA (2017) restringem o uso gratuito a fins "sem finalidades lucrativas" e pedem um email a webmaster@ipma.pt a informar do uso. O Regulamento (UE) 2023/138 (dados de elevado valor, meteorologia) aponta para reutilização livre, mas a aplicação pelo IPMA a estes ficheiros não foi confirmada.

**Até decidir:** a fonte fica no código, desligada; nenhum aviso do IPMA chega a nenhuma empresa. Quer que se envie o email ao IPMA a descrever o uso (leitura de 15 em 15 minutos, exibição descritiva por distrito com fonte, hora e logótipo) e a pedir a confirmação do uso comercial? A fonte liga-se quando a resposta chegar.

**Resposta:** trata o Simon (o email ao IPMA). O IPMA continua desligado.

## 14. Os jornais: autorização escrita antes do lançamento

O ECO, o Público e o Observador têm termos omissos quanto a feeds e leitura automática, mas com reservas genéricas de direitos. O Radar só guarda e mostra o título com a ligação direta e o nome do jornal.

**Até decidir:** os três entram em dev e na calibração; antes de qualquer cliente ver o Radar, pede-se autorização escrita aos três (e, se quiser, à RTP e à Medialivre, cujos feeds de Economia e Cultura seriam os de menos ruído). Quer que a lista dos pedidos a enviar fique no relatório da manhã?

**Resposta:** trata o Simon (a autorização escrita dos três jornais).

## 15. A Euribor fica de fora

A EMMI só permite os valores diários para uso não comercial e proíbe leitores automáticos; as médias mensais do BPstat e do BCE são dados de terceiros que a política do SEBC exclui sem autorização do originador.

**Até decidir:** a Euribor não aparece no Radar nem no imobiliário. A jogada do BCE dá a data e a ligação ao comunicado, sem taxas.

**Alternativa:** pedir ao Banco de Portugal a confirmação escrita para as médias mensais (com fonte, mês e hora visíveis) ou licenciar a EMMI.

**Resposta:** confirmada como proposta.

## 16. A referência do INE só por freguesia

O INE publica o valor por freguesia só na área metropolitana do Porto, na Grande Lisboa, na Península de Setúbal, no Algarve e nos municípios com mais de 100 mil habitantes (58 municípios; 350 freguesias com valor no 1.º trimestre de 2026). Nos restantes municípios há só o valor do município (304 de 308).

**Até decidir:** como o pedido manda, a comparação do preço usa só a freguesia; sem valor da freguesia, a jogada diz só os dias e mostra o valor do município como nota, sem comparar. Quer permitir a comparação com o município onde não há freguesia, com a confiança marcada como baixa?

**Resposta:** confirmada como proposta: o valor do município fica só como nota, sem comparação.

## 17. O IRS no calendário

O período de entrega do IRS (1 de abril a 30 de junho, artigo 60.º do CIRS) tem fonte oficial e vale para os três ramos, mas não estava nos exemplos do pedido.

**Até decidir:** entra no calendário dos três ramos, como lembrete com fonte. Tira-se com uma linha.

**Resposta:** o IRS entra só no stand e no imobiliário, fora da restauração.

## 18. Santo António de Lisboa e as publicações do INE

`lisboa.pt` recusa leitores automáticos (403), por isso a data de Santo António (12 e 13 de junho) não ficou confirmada por máquina; a data da próxima publicação das estatísticas de preços da habitação do INE também não (o ICS do INE não foi lido por limite de pedidos).

**Até decidir:** as duas ficam fora do calendário. Quer confirmar Santo António no browser (uma vez por ano, com a data da confirmação registada no código)?

**Resposta:** Santo António fica de fora; as datas de publicação do INE entram depois de ler o ICS do INE, com a fonte.
