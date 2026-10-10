# ACL: desenho aprovado (perfis de permissão por módulo e por ação)

Este documento é o spike do ACL (só leitura, feito antes da noite de 10 de outubro de 2026), aprovado como desenho com as decisões D1 a D14 do pedido `documents/NOITE-ACL-R2.md`. **As decisões estão no fim (secção 10) e prevalecem sobre as recomendações do spike** quando diferem (por exemplo, D9 sobre o perfil "Só leitura" e D14 sobre a Media Tailors).

As referências são relativas a `server/` e a `web/src/`.

## 0. Antes do desenho: duas falhas de isolamento confirmadas

Confirmei-as pessoalmente no código. Não as corrigi, porque o spike é só de leitura.

1. **Despesas** (`server/app/Http/Requests/ExpenseRequest.php:34,40,41`).
   - Os campos `expense_category_id`, `supplier_id` e `car_id` são validados com `exists:` sem filtro por empresa.
   - O `ExpenseService` não volta a verificar esses IDs.
   - O `ExpenseResource:33-34` devolve o nome do fornecedor e a marca e modelo do carro.
   - Na prática, uma empresa pode associar a uma despesa sua um fornecedor ou um carro de outra empresa e ler esses dados, bastando enumerar IDs.
2. **Vendedor do carro** (`CarRequest.php:407`).
   - O campo `seller_user_id` é validado com `exists:users,id`.
   - O `CarService` carrega o nome, o telemóvel e o WhatsApp do vendedor sem filtrar por empresa. São **dados pessoais** de outra empresa.

**Recomendação:** corrigir já, num hotfix à parte (Fase 0), com o seu OK.

## 1. O que existe

| Camada | Estado atual |
|---|---|
| **Papéis** | `users.role` com `user`, `admin` ou `root`. Um utilizador pertence a uma só empresa (`users.company_id`, sem tabela pivot). O aprovador de conteúdos é a coluna `users.can_approve_content`. Não há `isRoot()` central: há cerca de 45 comparações `role === 'root'` em 31 ficheiros |
| **CompanyAccess** | É um serviço, não uma tabela (`app/Services/Tenancy/CompanyAccess.php`). Classifica o acesso a uma empresa como `own`, `root` ou `agency` (com cache por pedido). É usado pelo middleware `tenant`, pela subscrição, por `authorizeCompany` (65 chamadas, mais 20 wrappers) e pelos serviços |
| **Agência** | A relação está em `company_managements`: estado `active` ou `ended`, `team_scope` `all` ou `assigned`, e só uma agência ativa por empresa. As atribuições estão em `company_management_members`. Há pedidos de gestão (expiram em 14 dias), fim da relação com efeitos (período experimental, arquivo, apagamento aos 90 dias) e faturação de 15 € por cliente. O administrador da agência é simplesmente um `admin` da empresa agência |
| **Módulos** | 18 chaves em `ModuleRegistry` e presets por ramo, ligados e desligados só pelo root. **Só 7 são verificados no backend**: `restauracao_*` (9), `marketing_analytics` e `support_tasks` só escondem o menu |
| **Backend** | Não há Policies, Gates nem `authorize()`. Há cerca de 12 estilos de verificação e 4 formas de devolver um 403. Os helpers estão espalhados por `CollaboratorService`, `EditorialWorkflowService`, `BlogWorkflowService`, `AgencyController::isAgencyAdmin` e por verificações `viaAgency` nos controllers. 22 dos 24 FormRequests têm `authorize()` sempre `true` |
| **Rotas** | 489 no total: 332 atrás de `tenant` (184 com módulo, 148 sem), 72 de root em `/admin` mais 6 root só por verificações no código, 16 de agência e 51 públicas. Há cerca de 114 POST que são ações (aprovar, sincronizar, ligar), não criações |
| **Ecrã** | As decisões estão em 5 sítios: o filtro do menu, os guardas `RequireModule` e `RequireSuperAdmin`, cerca de 18 verificações de papel locais, cerca de 30 flags `can_*` vindas do backend, e o contexto da empresa de trabalho e da agência |
| **Testes** | `AgencyTenancySweepTest` faz pedidos reais a todas as rotas de empresa, mas só com os atores de agência e com todos os módulos ligados. `TenancyArchitectureTest` limita as verificações de root por ficheiro, mas não lê as subpastas (ficam de fora `Admin/*` e `AgencyController`) |

**Incoerências que o modelo novo tem de resolver** (hoje a migração preserva-as tal como estão):
- **Aprovação:** o blog só deixa aprovar admin e root e ignora `can_approve_content`. A Linha Editorial aceita admin ou utilizadores com essa coluna.
- **Integrações, duas regras:**
  - `canConfigureIntegrations` (Meta, redes sociais) exige admin;
  - `agencyMayConfigureIntegrations` (PingWin, CoverManager, GA4, Carmine) deixa passar um `user` simples do cliente.
- **Ecrã contra backend:**
  - os colaboradores mostram "convidar" e "revogar" à agência e ao root dentro do cliente, e o backend responde 403;
  - escondem "editar" a membros da agência que o backend deixa editar.
- **`RequireModule` falha aberto:** com módulos `null` (a carregar ou erro), deixa passar.
- **Menu e rotas desalinhados:** `support_tasks` e `marketing_analytics` estão no menu, mas as rotas respetivas não os verificam.

## 2. O modelo

### Permissão = área × ação

- **Ações:** `ver`, `criar`, `editar`, `aprovar`, `apagar`, `configurar`.
- **Áreas:** seguem os módulos, mais quatro áreas base, sempre ativas:

| Área | Módulo exigido | Exemplos de rotas |
|---|---|---|
| `empresa` | nenhum (base) | dados da empresa, fim da gestão |
| `utilizadores` | nenhum (base) | users, colaboradores, departamentos, aprovadores, perfis |
| `integracoes` | nenhum (base); as do PingWin exigem `pingwin` | Meta, Google, redes sociais, links de configuração, PingWin, CoverManager |
| `faturacao_xplendor` | nenhum (base) | cobranças e orçamentos da Xplendor |
| `editorial` | `linha_editorial` | meses, publicações, revisões, ideias |
| `blog` | nenhum hoje; decisão 5 | blogs, IA do blog |
| `marca` | nenhum hoje | perfil da marca, seguidores |
| `bussola` | `pingwin` + `linha_editorial` para agir | Bússola, sinais |
| `resultados` | `marketing_analytics` | dashboards, GA4, Meta, resultados editoriais |
| `financas` | `finance` | clientes, fornecedores, despesas, categorias |
| `restauracao` | `pingwin` + a secção `restauracao_*` | lojas, artigos, documentos, unidades |
| `automovel` | `stock`, `commercial_crm`, `documents`, `aftersales` | carros, leads, modelos, pós-venda |
| `suporte` | `support_tasks` | pedidos, tarefas |

**A ação de cada rota é declarada, não deduzida do verbo HTTP.** Por exemplo, `POST editorial/posts/{id}/approve` é `editorial.aprovar`, e `POST integrations/pingwin/sync` é `restauracao.editar`.

**Decisão por omissão:** gerar texto, imagens ou sugestões com IA conta como `criar`, porque tem custo e ocupa a quota.

### A decisão, num único sítio

A função é `Access::can(user, companyId, 'area.acao')` e devolve sim, ou não com o motivo em pt-PT. Avalia por esta ordem:

1. **Root:** passa sempre. As decisões do cliente são a exceção (decisão 1).
2. **Inquilino:** `CompanyAccess::kind` tem de ser `own` ou `agency`. Caso contrário, não.
3. **Módulo:** o módulo da área tem de estar ativo na empresa do endereço.
4. **Perfil:**
   - `own`: o perfil do utilizador.
   - `agency`: o perfil do utilizador na agência **∩** o teto que o cliente define para a relação de gestão (o perfil "Agência convidada"). A visibilidade dos clientes (`team_scope` e atribuições) mantém-se como hoje.
5. **Regras transversais**, que continuam fora dos perfis:
   - a impersonação bloqueia acessos e integrações (`block_when_impersonating`);
   - a revisão interna tem de ser feita por outra pessoa;
   - a agência nunca aprova nem aceita orçamentos;
   - o modo de produção (`self` ou `team`).

### Perfis prontos

**Lado do cliente.** V = ver, C = criar, E = editar, A = aprovar, X = apagar, F = configurar.

| Área | Administrador | Marketing | Financeiro | Só leitura | Agência convidada (teto) |
|---|---|---|---|---|---|
| empresa | V E F | V | V | V | V |
| utilizadores | V C E A X F | V | V | | |
| integracoes | V F | V | | | V F |
| editorial | todas | V C E A X | V | V | V C E X |
| blog | todas | V C E A X | | V | V C E X |
| marca | V C E | V C E | | V | V C E |
| bussola | V C E | V C E | | V | V C E |
| resultados | V | V | V | V | V |
| financas | todas | | V C E X | V | |
| faturacao_xplendor | V A | | V A | | |
| restauracao | todas | V | V C E | V | |
| automovel | todas | V | V | V | |
| suporte | V C E | V C E | V C E | V | V C |

**Lado da agência:** as permissões dentro dos clientes, mais o painel da agência.

| Área | Administrador da agência | Gestor de clientes | Criativo externo |
|---|---|---|---|
| editorial | V C E X | V C E X | V C E |
| blog | V C E X | V C E X | V C E |
| marca, bussola | V C E | V C E | V |
| resultados | V | V | |
| integracoes | V F | V | |
| suporte | V C | V C | |
| Painel: atribuições, pedidos de gestão, faturação da agência | F | | |
| Clientes visíveis | todos | os atribuídos, ou todos com `team_scope=all` | só os atribuídos |

**Perfis personalizados por empresa:**
- copiam um perfil pronto e ajustam-se área a área;
- quem os gere: o administrador do cliente (lado do cliente e teto da agência), o administrador da agência (lado da agência) e o root;
- antes de gravar, o ecrã mostra as permissões efetivas.

**Armazenamento:**
- tabela `permission_profiles`: `company_id` nulo nos prontos, lado (cliente ou agência), sistema ou personalizado;
- tabela `profile_permissions` (perfil, área, ação);
- coluna `users.profile_id`;
- coluna `company_managements.guest_profile_id` (o teto);
- cada alteração fica registada.

**O que o ecrã recebe:** um `GET /companies/{id}/my-access` que substitui o `/my-modules` e devolve os módulos, as permissões efetivas e os motivos. As flags `can_*` atuais passam a ser calculadas pela mesma função.

## 3. Caso real: a Media Tailors na Yuko

**Pré-requisitos:**
- a Media Tailors tem de estar marcada como agência, com a relação de gestão ativa com a Yuko (ainda não verifiquei se existe em produção);
- **a Yuko precisa do módulo `linha_editorial`.** O preset da restauração não o inclui, e sem ele a Bússola não deixa agir. A Bússola também exige `pingwin`, que a Yuko já tem.

**O teto definido pela Yuko** (perfil personalizado a partir de "Agência convidada"):

| Área | Teto | Efeito para a Media Tailors |
|---|---|---|
| editorial | V C E X | Produz, revê e publica. **Não aprova:** a aprovação é sempre do cliente (regra transversal) |
| bussola | V C E | Vê as sugestões, cria publicações e ignora sugestões |
| resultados | V | Vê os resultados e os dashboards |
| financas, faturacao_xplendor | nada | Não vê despesas, fornecedores, cobranças nem orçamentos |
| utilizadores | nada | Não convida, não revoga e não escolhe aprovadores |
| integracoes | nada | **Muda em relação a hoje:** atualmente o administrador da agência pode ligar integrações. Com este teto, deixa de poder |
| restauracao, blog, marca, suporte | nada | Fica fora, porque não foi pedido |

**Efetivo por pessoa da Media Tailors** (perfil na agência ∩ teto):
- o administrador da agência e o gestor de clientes ficam com V C E X no editorial, V C E na Bússola e V nos resultados;
- o criativo externo fica com V C E no editorial, V na Bússola e nada nos resultados.

## 4. Onde se decide

- **Backend, num só sítio:**
  - O serviço `Access`, ligado ao Gate do Laravel: `Gate::before` para o root e uma única função para todas as permissões.
  - Cada rota declara a sua permissão com um middleware `permission:area.acao`. Este middleware substitui o `ensure_module`, a parte de permissão do `tenant`, o `editorial_producer` e as verificações dentro dos controllers. Os helpers atuais (`canEditContent`, `isApprover`, ...) passam a chamar o `Access`, e depois desaparecem.
  - Uma única resposta 403: `{message, reason}` em pt-PT, que o `ReasonButton` mostra tal como vem.
- **Ecrã:**
  - um hook `useCan('editorial.aprovar')` alimentado pelo `/my-access`;
  - o menu, as rotas e os botões usam as mesmas chaves e **falham fechados**;
  - saem as cerca de 18 verificações locais de papel e as listas de módulos duplicadas (`allRoutes.tsx` e `LayoutMenuData.tsx`).
- **Testes:**
  - O `AgencyTenancySweepTest` passa a ler uma tabela declarativa `rota → permissão`, gerada a partir do middleware.
  - Atores: administrador, Marketing, Financeiro, Só leitura, um perfil personalizado, um utilizador com o equivalente de hoje e o aprovador; root na própria empresa e noutra; impersonação; os três perfis de agência com e sem teto; um membro não atribuído; e uma empresa com o módulo desligado.
  - Para cada rota e ator, a resposta tem de ser 403 se e só se `Access::can` disser que não.
  - O teste estático falha se uma rota de empresa não tiver `permission:`.
  - O teste de arquitetura passa a ler as subpastas e proíbe `role ===` e `abort(403)` fora do `Access`.
  - Escala: cerca de 332 rotas × 15 atores, perto de 5 000 pedidos. Divide-se por área num data provider.

## 5. Migração sem perder acessos

1. **Primeiro, uma fotografia:** antes de mudar qualquer coisa, o varrimento com os atores de hoje grava o conjunto de respostas 403 de cada ator.
2. **Perfis de compatibilidade**, derivados da fotografia e não escritos à mão:

| Hoje | Passa a |
|---|---|
| admin do cliente | Administrador |
| user do cliente | "Utilizador (como hoje)": exatamente o que pode hoje, incluindo ligar PingWin, GA4 e Carmine, mas sem editar a marca, os colaboradores ou os departamentos |
| user com `can_approve_content` | "Utilizador (como hoje)" + `editorial.aprovar` |
| admin da agência | Administrador da agência (como hoje) |
| membro da agência | Gestor de clientes (como hoje) |
| relação de gestão ativa | teto "Agência convidada (como hoje)" = os textos `SCOPE_CAN` e `SCOPE_CANNOT` atuais |
| root | root |

3. **Critério:** depois da migração, o mesmo varrimento dá **exatamente** a mesma fotografia.
4. **Depois disso, as correções:** a aprovação do blog, as integrações dos users e os 11 módulos sem verificação no backend entram uma a uma, como decisões explícitas. A seguir, os administradores mudam os perfis "como hoje" para os perfis prontos quando quiserem.

## 6. As camadas de dados que ficaram por fazer

Não encontrei nenhum documento de auditoria com esses nomes. Uma sessão anterior chegou à mesma conclusão e registou estas camadas como pendentes: o âmbito para os registos filhos e os FormRequests com `authorize()` sempre `true`.

- **Filtros por empresa:** 0 dos cerca de 104 modelos com `company_id` têm âmbito. O isolamento depende de cerca de 582 `where('company_id', ...)` escritos à mão.
  - **Recomendação:** não usar global scopes automáticos. Chocam com os jobs, os comandos, o root e as consultas da agência a vários clientes.
  - Em vez disso, uma trait `BelongsToCompany` com `forCompany($id)`, explícito, mais ligações de rota por empresa (`{car}` só é encontrado dentro de `{id}`).
  - Um teste de arquitetura proíbe `Model::find`/`findOrFail` sobre esses modelos nos controllers.
- **Validação por empresa:** não existe nenhuma regra. Só 2 `Rule::exists` filtram por empresa (vendas de carros) e há 4 `exists:` simples sobre tabelas com dono (as falhas da secção 0).
  - **Proposta:** uma regra `ExistsInCompany(tabela, companyId)` e um teste de arquitetura que proíbe `exists:` simples em tabelas com `company_id` (a lista tira-se do esquema da base).
- **`authorize()` dos FormRequests:** passa a ficar `true` por desenho, porque a permissão é verificada na rota e garantida pelo teste estático. Fica documentado.

## 7. Fases

| Fase | Conteúdo | Feito quando |
|---|---|---|
| **F0** | Hotfix das 2 falhas (4 `exists:` e a leitura do vendedor) | Os IDs de outra empresa dão 422 e os testes cobrem os 4 campos |
| **F1** | `Access`, catálogo de permissões e `permission:` em todas as rotas, **em modo sombra** (calcula e regista as divergências, sem bloquear). Fotografia do varrimento | O teste estático exige permissão em 100% das rotas de empresa e há zero divergências na bateria completa |
| **F2** | Migração dos perfis de compatibilidade e dos tetos | A fotografia é idêntica antes e depois, ao 403 |
| **F3** | O middleware passa a bloquear. Saem as verificações nos controllers. Um só 403 com motivo. Teste de arquitetura sem `role ===`. Cada correção (aprovação do blog, integrações, os 11 módulos) entra como decisão própria | O varrimento rota × perfil está verde e o teste de arquitetura passa, incluindo as subpastas |
| **F4** | Ecrã: `/my-access`, `useCan`, menu e rotas a falhar fechados, saem as verificações locais, motivos vindos do backend | Nenhum `role ===` de permissão no `web/src`. Verificação no ecrã por perfil (computador e telemóvel, claro e escuro) |
| **F5** | Perfis prontos e personalizados, ecrã Utilizadores › Perfis e teto da agência no cartão da agência gestora. A Media Tailors na Yuko configurada numa sessão acompanhada | O caso real verificado em produção e as alterações de perfis registadas |
| **F6** | `ExistsInCompany`, `forCompany()` e ligações de rota por empresa, aos poucos, por área | Zero `exists:` simples em tabelas com dono e o teste de arquitetura dos `find` verde |

## 8. Decisões em aberto, com a minha recomendação

1. **O root passa sempre, mesmo a aprovar conteúdos ou a aceitar orçamentos em nome do cliente?** Recomendo que passe em todas as permissões, mas que as decisões do cliente (aprovar, aceitar orçamentos, aceitar ou terminar a gestão) exijam uma pessoa do próprio cliente, como hoje.
2. **Permissões da agência:** perfil do utilizador na agência ∩ teto do cliente, ou um perfil por utilizador e por cliente? Recomendo a interseção. Um perfil por atribuição fica para mais tarde, se for preciso.
3. **Armazenamento:** tabela `profile_permissions` ou JSON? Recomendo a tabela: é consultável, auditável e os perfis são pequenos, com cache por pedido.
4. **Um utilizador, uma empresa:** recomendo manter. Uma tabela pivot fica fora deste trabalho.
5. **Blog e marca sem módulo:** recomendo que fiquem como áreas base, sem módulo, até haver um módulo de conteúdos.
6. **Aprovação do blog:** passar a usar `blog.aprovar` pelo perfil, o que alinha com a Linha Editorial? Recomendo que sim, em F3.
7. **Integrações:** uma só permissão `integracoes.configurar`, só no Administrador por omissão? Recomendo que sim. Com isso, os users deixam de ligar PingWin, GA4 e Carmine, mas só depois de decidido em F3.
8. **Proteger no backend os 11 módulos que hoje só escondem o menu?** Recomendo que sim, em F3. Com isso, desligar um módulo passa a cortar também o acesso pela API.
9. **"Só leitura" vê as finanças?** Recomendo que sim, mas não as cobranças da Xplendor, os utilizadores nem as integrações.
10. **Global scopes ou `forCompany()` explícito?** Recomendo o explícito, pelas razões da secção 6.
11. **O criativo externo vê só os clientes atribuídos, mesmo com `team_scope=all`?** Recomendo que sim.

## 9. Riscos

- **Perder acessos na migração.** Mitigação: perfis derivados da fotografia e critério "fotografia idêntica".
- **Ações mal classificadas** (114 POST que são ações). Mitigação: ação declarada em cada rota, nunca deduzida do verbo.
- **Tempo do varrimento** (cerca de 5 000 pedidos). Mitigação: divisão por área e atores mínimos por perfil.
- **Combinatória de perfis personalizados**, que confunde quem os gere. Mitigação: poucos perfis, pré-visualização das permissões efetivas e motivo claro em cada recusa.
- **Um teto mal configurado bloqueia a agência sem ela perceber porquê.** Mitigação: o motivo diz quem limitou ("A Yuko não deu acesso às Finanças a esta agência").
- **Jobs, comandos e webhooks não têm utilizador.** O `Access` só existe no pedido HTTP, e os serviços não podem depender do utilizador autenticado.
- **Ecrã e backend desalinhados entre F3 e F4.** Mitigação: na F3, as flags `can_*` já vêm do `Access`.
- **A impersonação.** As permissões passam a ser as do utilizador impersonado, mais as regras transversais. Tem de ficar coberta no varrimento.

## 10. Decisões aprovadas (pedido da noite de 10 de outubro de 2026)

| # | Decisão | O que muda no desenho acima |
|---|---|---|
| D1 | O root passa em todas as permissões, EXCETO as decisões do cliente (aprovar conteúdos, aceitar orçamentos, aceitar ou terminar a gestão), que exigem uma pessoa do próprio cliente, como hoje | Confirma a recomendação 1 |
| D2 | Agência: o perfil do utilizador na agência ∩ o teto do cliente | Confirma a recomendação 2 |
| D3 | Tabela `profile_permissions` (não JSON) | Confirma a recomendação 3 |
| D4 | Um utilizador, uma empresa (mantém-se) | Confirma a recomendação 4 |
| D5 | Blog e marca como áreas base, sem módulo | Confirma a recomendação 5 |
| D6 | A aprovação do blog passa a `blog.aprovar` (em F3) | Confirma a recomendação 6, como decisão explícita na F3 |
| D7 | `integracoes.configurar` só no Administrador por omissão (em F3, como decisão explícita, depois da migração de compatibilidade) | Confirma a recomendação 7 |
| D8 | Os 11 módulos que hoje só escondem o menu passam a ser verificados no backend (em F3) | Confirma a recomendação 8 |
| D9 | "Só leitura" NÃO vê as Finanças, a faturação da Xplendor, os utilizadores, as integrações nem o back-office da restauração (lojas, artigos, documentos). Vê a empresa, a Linha Editorial, o blog, a marca, a Bússola, os resultados e o suporte | **Altera a recomendação 9** e a coluna "Só leitura" da tabela dos perfis prontos: `financas`, `restauracao` e `automovel` ficam vazios (ver a pergunta sobre o automóvel e o dashboard de vendas em `documents/NOITE-PERGUNTAS.md`) |
| D10 | `forCompany()` explícito, sem global scopes (a F6 NÃO entra esta noite) | Confirma a recomendação 10; a F6 fica fora |
| D11 | O criativo externo só vê os clientes atribuídos, mesmo com `team_scope=all` | Confirma a recomendação 11 |
| D12 | SEMPRE PELO MENOS UM ADMINISTRADOR por empresa (clientes e agências): não se pode apagar, desativar, retirar o perfil nem mudar para outro perfil o último administrador ativo; o ecrã explica ("Nomeie outro administrador antes de…"). O perfil Administrador é de sistema e não se edita | Regra nova, com testes |
| D13 | Perfis personalizados com sugestões: "Novo perfil" parte de uma sugestão (Marketing, Financeiro, Só leitura, Agência convidada) ou de um perfil vazio. Antes de criar, mostra em linguagem simples o que a sugestão dá, por área, tudo editável; a sugestão nunca é imposta. Antes de gravar, mostra as permissões efetivas | Substitui "copiam um perfil pronto": os perfis prontos do cliente (exceto o Administrador) são sugestões |
| D14 | Media Tailors na Yuko: entra como **utilizadores da Yuko** com o perfil sugerido "Agência convidada" do lado do cliente (Linha Editorial ver, criar, editar e apagar; Bússola ver, criar e editar; resultados ver; SEM aprovar, Finanças, utilizadores, integrações nem restauração). A XPLENDOR continua a ser a agência gestora da Yuko. Nada disto se configura em produção esta noite | **Altera a secção 3**: a Media Tailors não é uma agência gestora da Yuko; é um conjunto de utilizadores da Yuko com o perfil "Agência convidada". A sugestão "Agência convidada" passa a ter só `editorial` V C E X, `bussola` V C E e `resultados` V |

### Fases desta noite

F0 (hotfix de isolamento), F1 (Access, catálogo e `permission:` em todas as rotas, em modo sombra; a fotografia do varrimento), F2 (migração para os perfis de compatibilidade; a fotografia igual antes e depois), F3 (bloqueio; um só 403 com motivo; D6, D7 e D8 como decisões explícitas, uma a uma; teste de arquitetura sem `role ===` e com as subpastas), F4 (ecrã: `/my-access`, `useCan`, menu e rotas a falhar fechados, motivos do backend), F5 (ecrã Utilizadores › Perfis com D12 e D13, e o teto da agência no cartão da agência gestora). O critério de "feito" de cada fase é o da secção 7. A F6 não entra.

Se uma fase não ficar verde, o ACL pára aí, fica documentado onde parou, e passa-se ao R2.
