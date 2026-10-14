# Bússola Master: terminar (R2, R3, R4) e preparar a produção

Pedido recebido a 14 de outubro de 2026, gravado tal como chegou.

---

BÚSSOLA MASTER: TERMINAR (R2, R3, R4) E PREPARAR A PRODUÇÃO. Corre até ao fim; eu revejo no fim. No MAIN. Ignora CLAUDE.md antigo. NÃO registes em memória. Responde-me sempre em português de Portugal. Sem push nem deploy. Nada contra produção.

PRIMEIRO: lê documents/NOITE-BUSSOLA-MASTER.md e, em documents/bussola-master/, o DESENHO.md, o NOITE-PERGUNTAS.md, o RELATORIO-MANHA.md e as especificações em especificacoes/. Grava este pedido em documents/bussola-master/PEDIDO-FECHO.md e faz commit. Se o contexto for resumido a meio, volta a lê-lo.

REGRAS (não negociáveis):
- Trabalha sozinho, um passo de cada vez, sem vários agentes em paralelo (para poupar o limite da conta). Se o limite estiver perto, termina o passo em curso, grava e escreve no relatório onde ficaste.
- Nenhuma chamada real a serviços externos, com uma exceção: podes LER (só leitura, sem autenticação, no máximo 4 pedidos por fonte e por hora, depois de confirmares os termos) a tabela oficial das freguesias com o código DICOFRE (de fonte oficial, por exemplo a Carta Administrativa Oficial de Portugal da DGT ou o INE), a página das condições da API do INE (ine_api_v2) e o calendário de difusão do INE (ICS).
- IA simulada nos testes e no ecrã (AI_FAKE=true em dev durante a verificação; nunca em produção).
- Migrações só aditivas. Uma só sessão a construir: alterações por gravar de outra pessoa não se tocam nem se incluem; adiciona sempre os ficheiros pelo caminho.
- Um commit por parte, com a suite completa (as 24 falhas conhecidas sem aumento). Se uma parte não ficar verde, NÃO avança: para e documenta.
- A Bússola da restauração e a do stand NÃO mudam: os dois testes de caracterização continuam verdes.
- ACL: as rotas novas entram na fotografia do varrimento, sem mudar acessos que já existem; documenta a diferença no F3-DECISOES.md.
- Nunca inventar uma decisão de produto: na dúvida, pergunta no relatório e segue no que não depende dela.
- Honestidade de sempre: frases descritivas, nunca causas; fonte e hora visíveis; nunca sugere um preço.
- Dados pessoais: nunca guardados nem mostrados; nas capturas, só dados fictícios.
- Português de Portugal formal, sem travessões. Ecrãs: documents/design-system.md e componentes do Velzon, claro e escuro, computador e telemóvel.

DECISÕES DAS PERGUNTAS DA NOITE (regista-as no NOITE-PERGUNTAS.md):
- Confirmadas como propuseste: 1 a 9, 11, 12, 15 e 16 (na 16, o valor do município fica só como nota, sem comparação).
- 10: a ligação entre as freguesias da plataforma e a referência do INE faz-se pelo código oficial DICOFRE. Acrescenta o código à tabela das freguesias com uma migração de dados a partir da tabela oficial (o nome normalizado só ajuda a casar durante a migração). As freguesias que não casarem, e as diferenças com a tabela oficial (uniões e desagregações de freguesias), ficam listadas no painel do root e no relatório. Nada é apagado.
- 13 e 14: trato eu (o email ao IPMA e a autorização escrita dos três jornais). O IPMA continua desligado.
- 17: o IRS entra só no stand e no imobiliário, fora da restauração.
- 18: Santo António fica de fora; as datas de publicação do INE entram depois de leres o ICS do INE, com a fonte.

P1. RADAR DESLIGADO POR OMISSÃO: variável RADAR_ENABLED, por omissão false em todos os ambientes. Desligada: a recolha não corre (nem pela tarefa, nem pelo comando sem --force), a relevância e o encaixe não correm, e o painel do root mostra "Radar desligado". O módulo radar continua desligado por empresa. Com teste.

P2. TAREFAS AGENDADAS QUE CHAMAM A IA: a bussola-stand-nightly chamou a IA real em dev. Ela e qualquer outra tarefa agendada que chame a IA passam a contar como externas para o SCHEDULE_EXTERNAL_JOBS (desligadas em dev), ou respeitam o AI_FAKE. Lista-as no relatório. Com teste.

R2: conforme especificacoes/r2-spec.md (as funções de IA simuladas, a relevância por ramo, o encaixe com o limite de 3 por dia, as definições, o módulo radar, o bloco na Bússola, o painel "Agora", "Reagir", "Ignorar", "Não mostrar temas como este", a fotografia da ACL, a secção 6e do F3-DECISOES e a correção da tabela da secção 7, que não contava as rotas do commit f869922).

R3: conforme especificacoes/r3-spec.md, com as decisões acima (DICOFRE; IRS só no stand e no imobiliário), e a secção 6f do F3-DECISOES.

R4: verificação no ecrã com as ferramentas de documents/bussola-master/ferramentas/: empresas de demonstração claramente fictícias (um restaurante, um stand e um consultor imobiliário) e a XPLENDOR de dev; pessoas temporárias (administrador, agência convidada e root); AI_FAKE=true e RADAR_ENABLED=true só durante a verificação. No fim, tudo apagado e o .env de dev reposto. Capturas em documents/bussola-master/capturas/, sem dados pessoais.

NO FIM:
1. documents/bussola-master/RELATORIO-FECHO.md: o que ficou feito por parte, com o commit; os testes; as capturas; as decisões tomadas por omissão; a diferença na fotografia da ACL; as freguesias sem DICOFRE; as perguntas pendentes.
2. documents/DEPLOY-RADAR-IMOBILIARIO.md, para o dia, por ordem:
   a) se o deploy dos Ficheiros e da Bússola do stand (documents/DEPLOY-FICHEIROS-BUSSOLA.md) ainda não estiver feito, vem primeiro;
   b) tudo o que entrou no main a seguir: migrações (só aditivas), a migração de dados do DICOFRE, variáveis do .env (RADAR_ENABLED=false), tarefas agendadas novas, build do frontend;
   c) depois do deploy: o que muda para os clientes (deve ser nada: Radar e imóveis desligados); como ligar a recolha só para o root (RADAR_ENABLED=true), para a calibração; como ligar o módulo imoveis numa empresa;
   d) em cada passo, como confirmar e como voltar atrás.
