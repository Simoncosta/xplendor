# Noite: Bússola Master (Radar de oportunidade e imobiliário)

O pedido, tal como foi recebido a 11 de outubro de 2026. Se o contexto for resumido a meio da noite, volta a ler-se este ficheiro.

---

MODO NOITE: BÚSSOLA MASTER (Radar de oportunidade e imobiliário). Corre sozinho até ao fim; eu revejo de manhã. No MAIN. Ignora CLAUDE.md antigo. NÃO registes em memória. Responde-me sempre em português de Portugal. Sem push nem deploy. Nada contra produção.

PRIMEIRO: grava este pedido em documents/NOITE-BUSSOLA-MASTER.md e faz commit. Se o contexto for resumido a meio da noite, volta a lê-lo. Lê também o desenho da Bússola comum, da restauração e do stand (documents/bussola-stand/DESENHO.md e o que ele referir).

REGRAS (não negociáveis):
- EXCEÇÃO DESTA NOITE: podes LER feeds e dados públicos reais (Google Trends PT, RSS de jornais, IPMA, INE, BCE, Euribor de fonte oficial): só leitura, sem autenticação, sem dados pessoais, no máximo 4 pedidos por fonte e por hora, e só depois de confirmares os termos de uso de cada fonte. Fonte cujos termos não permitam este uso fica de fora (regista-a no desenho).
- Todas as outras chamadas reais continuam proibidas: IA (simulada nos testes e no ecrã), Meta, GA4, PingWin, CoverManager, Standvirtual, Idealista, Cloudflare.
- Os testes nunca vão à rede: usam amostras gravadas dos feeds (fixtures).
- Migrações só aditivas. Uma só sessão a construir: alterações por gravar de outra pessoa não se tocam nem se incluem; adiciona sempre os ficheiros pelo caminho.
- Agentes em paralelo só para pesquisar e ler, ou em ficheiros diferentes; os commits são sequenciais. Se a tua versão permitir, os agentes de construção (R1 a R3) usam o Opus 5.5; o desenho e a revisão final ficam no modelo da sessão.
- Um commit por parte, com a suite completa (as 24 falhas conhecidas sem aumento). Se uma parte não ficar verde, NÃO avança: para e documenta.
- A Bússola da restauração e a do stand NÃO mudam de comportamento: os testes de caracterização delas continuam verdes (acrescenta um para o stand, se não existir).
- ACL: as rotas novas entram na fotografia do varrimento, e nenhum ator ganha nem perde acesso a rotas que já existiam. Documenta a diferença no F3-DECISOES.md.
- Nunca inventar uma decisão de produto. Na dúvida, escreve a pergunta em documents/bussola-master/NOITE-PERGUNTAS.md e segue no que não depende dela. As decisões técnicas por omissão ficam documentadas para eu confirmar.
- Honestidade de sempre: frases descritivas, nunca causas; fonte e hora sempre visíveis; nada inventado.
- Dados pessoais: nada guardado nem mostrado. Dos feeds guarda-se só o título, a ligação, a fonte, a hora e o volume aproximado, nunca o artigo; tudo apagado ao fim de 30 dias.
- Português de Portugal formal, sem travessões. Ecrãs: documents/design-system.md e componentes do Velzon, claro e escuro, computador e telemóvel.

DECISÕES JÁ TOMADAS:
1. A Bússola passa a ter três camadas: 1) dados próprios (o que já existe); 2) calendário (o que vem aí, com data marcada e fonte oficial); 3) Radar (o que está a acontecer agora). Tudo desagua na Linha Editorial como ideias com prazo.
2. O Radar NUNCA publica e NUNCA escreve o texto nem a piada: dá o gancho a tempo, o dedo é da pessoa.
3. FILTRO DE SEGURANÇA (antes de qualquer IA, não se desliga): fora tragédias, mortes, acidentes, crimes, doenças, política, religião e conflitos, mais os "temas a evitar" do perfil da marca da empresa. Tendências que são nomes de pessoas só passam se o tema for um evento público (jogo, concerto), nunca a vida pessoal, e o cartão lembra que não se usa a imagem nem o nome para promover. Eventos com marcas protegidas (Champions, Mundial, Jogos Olímpicos): o cartão avisa para não usar nomes nem logótipos oficiais.
4. DESPORTO: só para as empresas que o pedirem, nas definições do Radar ("Desporto: não / sim, com os clubes escolhidos"). Por omissão, não.
5. RELEVÂNCIA: uma vez por tendência e por ramo (não por empresa), com a função de IA nova "radar_relevancia" em Modelos de IA (por omissão claude-haiku-5-5). O "porque lhe interessa" usa a função nova "radar_porque" (por omissão claude-opus-5-5, médio), só para os escolhidos.
6. ENCAIXE NA EMPRESA: o ramo, a zona, o perfil da marca e os dados da camada 1. No máximo 3 sinais por empresa e por dia.
7. O CARTÃO: "Está a subir: X" (há quanto tempo, volume aproximado), porque lhe interessa, até quando vale (janela), as fontes com ligação. Botões: "Reagir" (cria uma ideia na Linha Editorial com o gancho, as fontes e o prazo, SEM texto; o "Sugerir texto" da Linha Editorial continua disponível, só a pedido), "Ignorar", "Não mostrar temas como este" (fica nas definições, reversível).
8. ONDE APARECE: um bloco "Radar" na página da Bússola (em todos os ramos) e um painel lateral "Agora" na Linha Editorial. Segue as permissões da área Bússola. Não há valores em euros no Radar.
9. INTERRUPTOR: o Radar nasce desligado em cada empresa até o root o ligar (em dev, liga-o só na XPLENDOR e nas empresas de demonstração). Painel do root (Admin › Radar) com tudo o que entrou, o que o filtro tirou e porquê.
10. IMOBILIÁRIO (consultor): ramo novo "Imobiliário", com modelo do ramo no perfil da marca. Reaproveita a estrutura do stand (peças únicas em carteira, dias no mercado, preço face ao mercado, interesse), trocando os veículos por imóveis, sem mudar nada do stand. Preço face ao mercado só com a referência oficial do INE (valores medianos por m² da freguesia), nunca sugere um preço, e sem recolha de portais (Idealista e outros). Jogadas: publicar o imóvel novo; rever o preço do imóvel parado (com a referência do INE quando existir); explicar a decisão do BCE no próprio dia; mostrar a zona; pedir o testemunho a quem fechou. Conformidade: nas publicações de imóveis, a Linha Editorial avisa quando falta a classe energética (obrigatória em qualquer anúncio de venda ou arrendamento, Decreto-Lei 101-D/2020, art. 18.º).
11. CALENDÁRIO POR RAMO (camada 2): só datas com fonte oficial confirmada (ex.: reuniões de política monetária do BCE, publicações do INE, prazos do IMI, São João no Porto). Data sem fonte confirmada fica de fora.

PARTES:
R0 DESENHO (documents/bussola-master/DESENHO.md; não paras, segues para o R1):
   - as três camadas e como se ligam à Bússola comum e à Linha Editorial;
   - cada fonte: URL, formato, frequência, termos de uso, o que se guarda e porquê; as que ficam de fora e porquê;
   - amostras reais (ver a regra da exceção) e o que mostram (ex.: quanto ruído traz o Trends);
   - o modelo de dados dos sinais, comum às três camadas;
   - os custos de IA estimados por dia, com os limites por empresa;
   - o imobiliário: o que se reaproveita do stand, o que é novo, de onde vêm os dados;
   - o que pode correr mal.
R1 RECOLHA E FILTRO (commit): comandos de recolha por fonte, armazenamento, apagamento aos 30 dias, filtro de segurança, painel do root. Tarefa agendada de 15 em 15 minutos (externa: desligada em dev pelo SCHEDULE_EXTERNAL_JOBS). Testes com fixtures, incluindo cada regra do filtro. Depois do R1, corre a recolha à mão entre as partes (respeitando o limite por hora), para teres uma amostra real da noite; regista quantos itens entraram e quantos o filtro tirou, por motivo.
R2 RELEVÂNCIA, ENCAIXE E CARTÃO (commit): as duas funções de IA (simuladas), o encaixe, o limite de 3 por dia, as definições do Radar (interruptor do root, desporto, temas silenciados), o bloco na Bússola, o painel "Agora" na Linha Editorial, "Reagir" para ideia com prazo, "Ignorar" e "Não mostrar temas como este". Testes: filtro antes da IA, limite diário, janela (nunca um prazo passado), desporto só com opção, temas silenciados, interruptor, tenancy, nenhum dado pessoal, "Reagir" sem texto.
R3 IMOBILIÁRIO E CALENDÁRIO (commit): o ramo e o modelo do ramo, os imóveis, o fornecedor da camada 1, a referência do INE, as jogadas, o aviso da classe energética, e o calendário por ramo com fontes. Teste de caracterização: o stand responde igual antes e depois.
R4 VERIFICAÇÃO NO ECRÃ (commit): empresas de demonstração claramente fictícias (um restaurante, um stand e um consultor imobiliário) e a XPLENDOR de dev; pessoas temporárias (administrador, agência convidada, root); tudo apagado no fim. Capturas em documents/bussola-master/capturas/, sem dados pessoais.

NO FIM: documents/bussola-master/RELATORIO-MANHA.md com:
1. O que ficou feito em cada parte, com o commit.
2. Os testes e as capturas.
3. A amostra real da noite: itens por fonte, o que o filtro tirou e porquê, exemplos (sem dados pessoais).
4. As decisões tomadas por omissão, para eu confirmar.
5. A diferença na fotografia da ACL.
6. As perguntas pendentes.
7. O plano da calibração com IA real (amostra, custo estimado, o que medir), para fazermos acompanhados.
8. A lista de deploy (migrações, comandos, tarefas agendadas, interruptores, funções de IA novas).
