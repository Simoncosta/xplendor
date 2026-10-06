# Social · Agendamento, Aprovação e Automação

> Documento de projeto. Visão, decisões, fases e método de trabalho do módulo de redes sociais (Instagram e Facebook) da XPLENDOR, integrado na Linha Editorial.
> 

---

## 1. Visão

**O problema.** Hoje, gerir as redes sociais de um cliente obriga a saltar entre ferramentas: Meta Business Suite para publicar, mLabs ou similar para agendar, Canva para criar, ManyChat para automatizar respostas, folhas de cálculo para planear e medir. A informação fica espalhada e ninguém liga a estratégia aos resultados.

**O objetivo.** A Linha Editorial passa a ser o sítio onde as redes sociais se **planeiam, produzem, aprovam, publicam, automatizam e analisam**. Um só lugar, sem alternar entre ferramentas.

**Ligação ao Sistema Operacional de Marketing.** Este módulo cobre as etapas centrais do ciclo:

Planeamento → Produção → Aprovação → Publicação → Análise → Aprendizagem

**O diferencial (o que não é só "mais um agendador"):**

- **Melhores dias, horários e formatos por conta**, calculados a partir do comportamento dos seguidores de cada conta, e não de médias genéricas. O melhor horário não é quando toda a gente publica.
- **IA com o tom de voz de cada marca**: legendas, variações por rede e formato, hashtags.
- **Automação comentário → mensagem privada** ("comente QUERO e receba o link"), configurada no mesmo sítio.
- **Tudo ligado à estratégia e aos resultados** (pilares, objetivos, leads, vendas).

**Inspirações:** mLabs (agendamento e gestão multi-conta), ManyChat (automação comentário → DM).

---

## 2. Princípios (como decidimos)

1. **Processo real primeiro.** A operação de Social Media da XPLENDOR é o laboratório: observar, documentar, testar, padronizar, só depois automatizar.
2. **Versão mínima testável** antes de investir no completo.
3. **Spike antes de construir.** Ver o terreno (código, API, dados reais) antes de escrever código.
4. **Uma fatia de cada vez, testada em tela** com dados reais. "Passa nos testes" não é "funciona no uso real".
5. **Não partir o que já funciona.** Testes de não-regressão em tudo o que mexe em código provado.
6. **Segurança no backend.** Tenancy, permissões e webhooks validados no servidor, nunca só no ecrã.
7. **Honestidade nos dados.** Estados reais (falhou e porquê, token expirado, sem dados), nunca zeros ou sucessos falsos.
8. **Nada de conteúdo falso.** Sem números ou testemunhos inventados.
9. **Português de Portugal formal, sem travessões** em todo o texto do produto.

**Perguntas antes de cada funcionalidade nova:**

- Que problema operacional resolve?
- Em que etapa do ciclo entra?
- É universal ou específica de um nicho?
- O que deve ser automático e o que deve continuar humano?
- Que dados gera para decisões futuras?
- Qual é a versão mínima para testar?

---

## 3. Como trabalhamos

### Papéis

| Quem | Papel |
| --- | --- |
| Simon | Decide, testa em tela, faz git e deploy |
| Equipa de Social Media | Utilizadora piloto: testa no dia a dia, diz o que falta e o que sobra |
| Claude (Claude.ai) | Parceiro de pensamento: ajuda a decidir, desenha, escreve os prompts e revê os resultados |
| Claude Code | Executa no repositório: spikes, código, testes, relatórios |

### Ciclo de cada fatia

1. **Spike** (só leitura): mapear o terreno e propor.
2. **Desenho**: decisões fechadas antes de código.
3. **Construir** em duas partes: backend → mostrar e esperar OK → frontend.
4. **Testar em tela** com dados reais (desktop e telemóvel, tema claro e escuro).
5. **Gravar no git** (nunca o `docker/redis/db/dump.rdb`).
6. **Acrescentar os passos à lista de deploy.**
7. **Deploy** com as verificações pós-deploy.

### Regras dos prompts para o Claude Code

- "Ignora CLAUDE.md antigo. NÃO registes em memória. Sem deploy."
- build/tsc EXIT=0; a suite não pode ganhar falhas novas.
- Âmbito claro: o que tocar e o que NÃO tocar.
- Testes definidos no próprio prompt.

### Regras de operação

- **Uma sessão do Claude Code de cada vez por repositório.** Para trabalhar em paralelo, usar `git worktree` (outra pasta, outro ramo).
- **Deploy:** seguir a lista acumulada; terminar sempre com `php artisan queue:restart`.
- **Verificações pós-deploy:** rota inexistente responde sem `exception`/`trace`; empresa A a pedir dados da empresa B dá 403.
- **Modelo e esforço:** máximo onde há raciocínio de algoritmo (melhores horários, IA); normal em UI e parsing.

---

## 4. O que a Meta permite (resumo, a confirmar no spike da F0)

| Funcionalidade | Instagram | Facebook | Notas |
| --- | --- | --- | --- |
| Imagem, carrossel, reel | Sim | Sim | Um carrossel conta como uma publicação para o limite diário |
| Stories | Sim, só contas business | A confirmar | Sem autocolantes de link via API, sem localização, sem collabs |
| Collabs | Feed, reels e carrosséis | Não aplicável | Não funciona em stories |
| Marcação de pessoas (@) e localização | Sim | Sim | Localização não funciona em stories |
| Capa do reel | Sim | Não aplicável |  |
| Etiquetas de produto (Shopping) | Condicional | A confirmar | Depende da via de autenticação e de catálogo; a confirmar |
| Link clicável | Não (na legenda não é clicável) | Sim | "Publicações com link" são do Facebook |
| Agendamento nativo | **Não** | A confirmar | No Instagram, é a XPLENDOR que guarda o post e o publica na hora certa |
| Limite de publicações via API | 50 a 100 por conta em 24 h | A confirmar | A documentação da Meta não é consistente; confirmar |
| Contentores de publicação | Expiram em 24 h | Não aplicável | Criar o contentor perto da hora de publicar; um reel não entra em carrosséis |
| Comentário → mensagem privada | 1 mensagem por comentário, até 7 dias | A confirmar (Messenger) | A conversa só continua se a pessoa responder (janela de 24 h); limite de 750 por hora; os webhooks podem chegar duplicados |

---

## 5. Decisões de arquitetura

### 5.1 Modelo de contas: quem gere que contas

A XPLENDOR gere as redes de várias empresas; a Quebom gere só a sua (no futuro, pode gerir outras).

| Opção | Como funciona | Prós | Contras |
| --- | --- | --- | --- |
| A. Impersonation (já existe) | A equipa "entra como" cada cliente | Já construído e seguro | Sem vista única de todas as contas; muito alternar |
| B. Marcas dentro de uma empresa | A XPLENDOR tem N marcas lá dentro | Simples de mostrar | Duplica empresas que já existem na plataforma (ex.: Quebom) |
| **C. Delegação (recomendado)** | Cada empresa é dona das suas contas; uma relação explícita "a XPLENDOR gere a Quebom" dá acesso ao agendador dessas contas | Vista única com seletor de conta; serve agência e cliente; base para cobrar por conta gerida | Exige alargar a tenancy com regras claras (segurança) |

**Estado:** por decidir. Recomendação: **C. Delegação.**

**Nota de segurança:** o middleware de tenancy atual só permite "a própria empresa ou o root". A delegação alarga-o: acesso permitido se existir uma delegação ativa da empresa alvo para a empresa do utilizador, com permissões por âmbito (ver, criar, aprovar, publicar). Desenhar com testes de varrimento de rotas.

### 5.2 Perfil de marca (por conta gerida)

Tom de voz, público, pilares, palavras a usar e a evitar, exemplos de publicações aprovadas, hashtags da marca. É a "ficha estratégica" do Sistema Operacional e a base da IA (F7).

### 5.3 Publicador fiável

- O agendador guarda a publicação; um job publica na hora (scheduler + worker).
- **Idempotência:** uma publicação nunca sai duas vezes (reservar antes de enviar).
- Tentativas com espera crescente; depois, estado "falhou" com o motivo da Meta em linguagem simples.
- Respeitar o limite diário de cada conta.
- Alerta (sino e/ou email) quando uma publicação falha ou quando o publicador não corre.
- Lembrete permanente: depois de cada deploy, `queue:restart`.

### 5.4 Media

- Ficheiros num armazenamento com URL pública acessível pela Meta.
- Validação antes de agendar: proporções, tamanhos, duração, formatos por tipo (feed, reel, story).
- Pré-visualização por rede antes de agendar.

### 5.5 Segurança e RGPD

- Tenancy com delegação (5.1), testada.
- Webhooks: verificação da assinatura da Meta; tratar duplicados.
- Tokens cifrados; estados honestos (sessão expirada, conta em falta).
- Automação de mensagens: guardar o mínimo de dados pessoais de quem comenta; registos com contagens.

---

## 6. Ligação ao que já existe

| Módulo existente | Como se liga |
| --- | --- |
| Linha Editorial (publicações com data, formato, estado, canal; calendário; âncoras sazonais) | É a casa do módulo. Cada publicação ganha media, legenda, contas de destino, agendamento e estado de publicação |
| Integração Meta (OAuth, tokens, estados honestos) | Reaproveitada; ganha as permissões de publicação, comentários e mensagens |
| Motor de recomendações | Regras novas: "publicação falhou", "conta sem publicações há X dias", "público desatualizado", "melhor horário disponível esta semana" |
| Marketing e resultados (dashboards) | Resultados por publicação e por pilar, ligados à estratégia |
| UTMs e tracking (xplendor.js) | Links das publicações e da automação com UTMs automáticos; cliques e leads atribuídos |
| Impersonation | Continua a existir para suporte; a delegação passa a ser o caminho do dia a dia |

---

## 7. Fases

### F0 · Permissões e verificação do negócio (começar já, em paralelo)

**Objetivo:** ter a Meta a autorizar tudo o que as fases seguintes precisam.

| Permissão (Facebook Login) | Para quê | Fase |
| --- | --- | --- |
| `instagram_content_publish` | Publicar no Instagram | F2 |
| `pages_manage_posts` | Publicar em páginas do Facebook | F2 |
| `pages_show_list`, `pages_read_engagement`, `business_management` | Listar contas e páginas | F1, F2 |
| `instagram_manage_insights` | Melhores horários e desempenho por publicação | F6 |
| `instagram_manage_comments` | Ler comentários (webhooks) | F9 |
| `instagram_manage_messages` | Enviar a mensagem privada ao comentário | F9 |
| `pages_messaging` | Equivalente no Facebook (Messenger) | F9, opcional |

Notas: com "Instagram Login" os nomes mudam (`instagram_business_*`). Acesso avançado exige App Review e verificação do negócio.

- [ ]  Confirmar que o nome no Meta Business bate com os documentos legais (IT Rocket → XPLENDOR)
- [ ]  Verificação do negócio
- [ ]  Pedir App Review das permissões de F2 primeiro, depois F6 e F9
- [ ]  Spike técnico: confirmar a tabela da secção 4 (limites, stories, Facebook, Shopping)

**Feito quando:** permissões de F2 aprovadas e a tabela da secção 4 confirmada.

### F1 · Fundação: contas sociais, delegação e perfil de marca

**Objetivo:** saber, com segurança, que contas cada utilizador pode gerir.

- [ ]  Modelo de contas sociais (Instagram e páginas Facebook) por empresa
- [ ]  Delegação entre empresas, com âmbitos (ver, criar, aprovar, publicar)
- [ ]  Tenancy alargada à delegação, com testes de varrimento de rotas
- [ ]  Perfil de marca por conta
- [ ]  Seletor de conta no agendador

**Feito quando:** a equipa vê as contas de todas as empresas que gere, e nenhuma outra (teste: empresa sem delegação → 403).

### F2 · Publicar (MVP)

**Objetivo:** publicar e agendar a partir da Linha Editorial, sem abrir a Meta.

- [ ]  Publicação da Linha Editorial ganha: conta(s), rede(s), legenda, media, data e hora
- [ ]  Upload de imagem e vídeo, com validação por formato
- [ ]  Formatos do MVP: imagem, carrossel, reel (Instagram); publicação com imagem e com link (Facebook)
- [ ]  Publicador fiável (5.3)
- [ ]  Estados: rascunho, agendado, a publicar, publicado (com link), falhou (com motivo)
- [ ]  Pré-visualização por rede

**Feito quando:** duas semanas a publicar pela XPLENDOR nas contas reais, sem falhas silenciosas e sem voltar ao Meta Business Suite.

### F3 · Fluxo de aprovação

**Objetivo:** Ideia → Planeamento → Produção → Revisão (interna) → Aprovação (cliente) → Programado → Publicado → Análise, com aprovação do cliente dentro da XPLENDOR, por link sem login, ou pelos dois.

**Decidido (outubro de 2026):**
- A publicação editorial (`editorial_posts`) é a unidade de produção e aprovação; a F2 cria as publicações na rede a partir da versão aprovada.
- Aprovam o administrador da empresa e os utilizadores marcados como "aprovador de conteúdos"; nunca o root e nunca em impersonation. Todas as ações guardam a pessoa real (`impersonator_user_id`).
- Revisão interna e aprovação do cliente configuráveis por empresa; a revisão interna é feita por outra pessoa.
- Modo de produção por empresa: "Produção própria" (por omissão, os utilizadores da empresa produzem) ou "Produção pela equipa XPLENDOR" (os utilizadores do cliente comentam, aprovam e pedem alterações, mas não editam nem mudam etapas). Só a equipa muda o modo.
- Versão congelada no envio ao cliente; editar depois cria a versão seguinte e volta a pedir aprovação. Uma só aprovação por versão.
- Link por lote (não por publicação nem por marca), token no fragmento do URL, validade de 14 dias.
- Media num disco privado com URLs assinados de curta duração; a cópia pública só na publicação (F2). Retenção: versões substituídas apagadas aos 30 dias; originais das publicações publicadas guardados 12 meses (biblioteca da marca), a rever na passagem para S3.

**Fatias:**
- [x]  F3a: etapas, Kanban, versões, comentários (internos e partilhados), aprovação na app, histórico
- [x]  Vistas: calendário com a etapa por cor e legenda, Kanban, a mesma janela de produção nas duas, vista lembrada por utilizador e no URL
- [ ]  F3b: media (imagem, carrossel, vídeo, capa), miniaturas, pré-visualização como na rede e a vista "Grelha do Instagram" (as próximas publicações como a grelha do perfil)
- [ ]  F3c: link por lote, aberturas, avisos e lembretes
- [ ]  F3d: Publicado com o link da publicação e Análise com notas manuais

### F4 · Vistas: calendário, kanban e lista

- [ ]  Calendário (já existe) com os estados de publicação
- [ ]  Kanban por estado (estava planeado: arrastar entre colunas)
- [ ]  Lista com colunas e filtros
- [ ]  Vistas filtradas guardadas e partilháveis (por conta, rede, estado, pilar)

### F5 · Extras de publicação

- [ ]  Stories (contas business; sem links via API)
- [ ]  Collabs (até ao limite da Meta; não em stories)
- [ ]  Marcação de pessoas (@) e localização
- [ ]  Capa do reel
- [ ]  Etiquetas de produto (se o spike confirmar e houver catálogo)
- [ ]  UTMs automáticos em todos os links
- [ ]  Fila e horários automáticos ("publica no próximo horário livre")
- [ ]  Agendamento até 6 meses de antecedência

### F6 · Melhores dias, horários e formatos por conta

**Objetivo:** recomendar quando e o quê publicar, com base nos seguidores de cada conta.

- [ ]  Recolha dos insights por publicação e da atividade dos seguidores (permissão `instagram_manage_insights`)
- [ ]  Análise por dia, hora e formato, por conta
- [ ]  Recomendação com grau de confiança (poucos dados = aviso, como no motor de preço)
- [ ]  Sugestão no momento de agendar ("o melhor horário desta conta é quinta às 19h")

Nota: precisa de semanas de dados acumulados. Começar a recolha cedo.

### F7 · IA: legendas, variações e hashtags

- [ ]  Legendas no tom de voz do perfil de marca (5.2)
- [ ]  Variações por rede (Instagram vs Facebook) e por formato
- [ ]  Sugestão de hashtags
- [ ]  O humano revê sempre antes de publicar

### F8 · Canva

- [ ]  Spike: Canva Connect (o que permite, o que exige aprovar)
- [ ]  Importar um design do Canva para a publicação

### F9 · Automação comentário → mensagem privada

**Objetivo:** "Comente QUERO e receba o link", a responder no momento, mesmo fora de horas.

**Passo 1. O gatilho: o que o comentário precisa conter?**

- [ ]  Palavras-chave por publicação (ou globais por conta)
- [ ]  Ignorar maiúsculas e acentos; opção de correspondência exata ou "contém"
- [ ]  Escolher as publicações onde a automação está ativa

**Passo 2. A resposta: o que a pessoa recebe?**

- [ ]  Mensagem privada configurável
- [ ]  Botão com link (com UTMs automáticos)
- [ ]  Opcional: resposta pública curta no comentário ("Enviámos-lhe uma mensagem!"), com variações para não repetir sempre o mesmo texto

**Regras da Meta a cumprir:**

- Uma única mensagem privada por comentário, até 7 dias depois do comentário
- A conversa só continua se a pessoa responder
- Limite de 750 por hora por conta
- Webhooks podem chegar duplicados: reservar o comentário antes de enviar (nunca duas mensagens)

**Medição:**

- [ ]  Comentários captados, mensagens enviadas, cliques no link, leads geradas, por automação

### F10 · Futuro

- [ ]  Cobrança por conta gerida (delegação)
- [ ]  Uma empresa cliente a gerir várias empresas
- [ ]  WhatsApp

---

## 8. MVP: o que testamos primeiro

**F0 + F1 + F2**, usadas pela equipa de Social Media nas contas reais: as contas que a XPLENDOR gere e a da Quebom.

**Sucesso:** duas semanas a planear, agendar e publicar só pela XPLENDOR, sem falhas silenciosas. O que faltar ou sobrar no uso real define a ordem das fases seguintes.

---

## 9. Riscos transversais

| Risco | Mitigação |
| --- | --- |
| App Review demorada ou rejeitada | Começar a F0 já; nome da empresa alinhado com os documentos |
| Publicador parado (worker) = publicação que não sai | Monitorização, alertas, `queue:restart` em cada deploy |
| Tokens expirados | Estados honestos já existentes + alerta |
| Tenancy com delegação | Desenho cuidado + testes de varrimento de rotas |
| Mudanças na API da Meta | Versão da API fixada; rever a cada atualização |
| Automação vista como spam | Uma mensagem por comentário, respostas variadas, limites |
| RGPD | Mínimo de dados pessoais; registos com contagens |

---

## 10. Decisões em aberto

- [ ]  Modelo de contas (recomendado: delegação)
- [ ]  Formatos e redes do MVP (proposta: imagem, carrossel, reel no Instagram; imagem e link no Facebook)
- [x]  Aprovação do cliente: dentro da XPLENDOR e por link sem login (link por lote); ver F3
- [x]  Papéis: produzem os utilizadores da empresa e a equipa XPLENDOR; aprovam o administrador e os aprovadores marcados; ver F3
- [ ]  Automação: só Instagram primeiro, ou Instagram e Facebook?
- [ ]  Quando ligar a cobrança por conta gerida

---

## 11. Fontes

- Meta · Content Publishing (Instagram): https://developers.facebook.com/docs/instagram-api/guides/content-publishing/
- Meta · Instagram Platform, Content Publishing: https://developers.facebook.com/docs/instagram-platform/content-publishing
- Meta · IG User Media (referência): https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media
- Meta · Collaboration: https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-facebook-login/collaboration
- Meta · Private Replies: https://developers.secure.facebook.com/docs/messenger-platform/instagram/features/private-replies
- Limitações de stories (fornecedor terceiro, a confirmar): https://docs.zernio.com/platforms/instagram