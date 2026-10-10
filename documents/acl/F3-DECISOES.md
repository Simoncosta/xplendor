# ACL, F3: o bloqueio e as decisões explícitas

O middleware `permission` passou a bloquear (antes estava em modo sombra). As verificações de papel saíram dos controllers: a decisão é do `Access` (app/Access), com um só formato de 403, `{success: false, message, reason, errors}`, e o motivo em português. Depois, as decisões D1, D6, D7 e D8 entraram uma a uma, cada uma com a sua diferença na fotografia do varrimento.

## 1. O bloqueio, sem decisões: a fotografia não mudou

Com o middleware a bloquear e as verificações de papel retiradas dos controllers, a fotografia ficou igual à de antes para todos os atores que não são root (o root muda com a D1, a seguir).

Para isso, o `Access` passou a reproduzir quatro regras de hoje que a fotografia inicial não cobria (encontradas por dois atores novos, gravados com o código antigo, e pela suite completa):

- **Em sessão como cliente** (impersonation), a equipa edita os conteúdos (equipa, departamentos, marca e fluxo editorial), mesmo que o utilizador impersonado não os possa editar (`Permissions::IMPERSONATION_GRANTS`).
- **O root não é filtrado por módulos** (como no `ensure_module`).
- **A própria conta:** cada pessoa vê e altera a sua conta (`RoutePermissions::SELF`); a password continua a ser só da própria pessoa (`UpdateUserRequest`).
- **Rotas sensíveis sem o middleware** `block_when_impersonating`, em que o código já recusava durante a impersonation (`RoutePermissions::SENSITIVE`).
- **Criar um colaborador já com acesso** à plataforma depende do pedido: o controller pede `utilizadores.configurar` ao `Access` (`authorizePermission`), com o mesmo 403.

Os 403 que ainda podem sair de um controller estão numa lista fechada, cada um com o porquê (`TenancyArchitectureTest::ALLOWED_403`): as rotas do root, o painel da agência, o modo de produção da Linha Editorial, a Bússola sem Linha Editorial, e rotas sem empresa no endereço. A verificação da empresa ("Acesso negado: utilizador inválido.") continua nos controllers como defesa em profundidade.

O teste de arquitetura lê as subpastas (Admin, Agency) e proíbe comparar o papel num controller: o root e o admin passaram a `isRoot()` e `isAdmin()`.

## 2. D1: o root passa em tudo; noutras empresas, as decisões do cliente são do cliente

As decisões do cliente são `editorial.aprovar`, `blog.aprovar`, `faturacao_xplendor.aprovar` (aceitar orçamentos, indicar o pagamento de uma cobrança) e `empresa.aprovar` (aceitar, recusar ou terminar a gestão por uma agência).

- **Noutras empresas**, o root não as toma: exigem uma pessoa do próprio cliente (confirmado na resposta à pergunta 1 da noite).
- **Na própria empresa**, o root conta como administrador, também nas decisões (por exemplo, aprovar os artigos do blog da XPLENDOR). Foi corrigido no pré-deploy: na noite, o root também ficava sem as decisões na própria empresa.

Hoje o root aceitava orçamentos e indicava pagamentos noutras empresas; com a D1 deixa de poder (continua a marcar as cobranças como pagas no painel `/admin`).

**Root noutra empresa**

- passa a 403: `PATCH {id}/quotes/{quote}/decision`, `PATCH {id}/support-tickets/{ticket}/quote-decision`, `POST {id}/support-tickets/quotes/approve`, `POST {id}/xplendor-charges/{chargeId}/paid`
- deixa de dar 403: `POST {id}/users`, `PUT {id}/users/{user}`, `POST {id}/collaborators/{collaborator}/access` (e reenviar, repor, retirar, cancelar o convite), `PUT {id}/editorial/approvers/{userId}`

**Root na própria empresa**

- deixa de dar 403 em todas as rotas que hoje lhe davam 403: criar e alterar utilizadores, aprovar e pedir alterações na Linha Editorial, os pedidos e as ligações da gestão por agências.
- Nenhuma rota passa a 403.

## 3. D6: a aprovação do blog passa a blog.aprovar

O aprovador de conteúdos (escolhido na Linha Editorial) passa a aprovar também os artigos do blog (`Permissions::APPROVER_GRANTS`). O blog e a Linha Editorial ficam com a mesma regra.

**cliente_aprovador**

- deixa de dar 403: `POST {id}/blogs/{blog}/approve` (agora 200)
- deixa de dar 403: `POST {id}/blogs/{blog}/back-to-draft` (agora 200)
- deixa de dar 403: `POST {id}/blogs/{blog}/request-changes` (agora 422)

## 4. D7: ligar qualquer integração é integracoes.configurar

O PingWin, o CoverManager, o GA4 e a Carmine passam a pedir a mesma permissão que a Meta e as redes sociais. Por omissão, só o Administrador (e o administrador da agência, dentro do teto do cliente). A ação `integracoes.editar` saiu do catálogo.

**cliente_aprovador**

- passa a 403: `DELETE {id}/carmine-connection/{carmine_connection}`
- passa a 403: `DELETE {id}/integrations/covermanager`
- passa a 403: `DELETE {id}/integrations/google`
- passa a 403: `POST {id}/carmine-connection`
- passa a 403: `POST {id}/integrations/covermanager/connect`
- passa a 403: `POST {id}/integrations/google/connect`
- passa a 403: `POST {id}/integrations/pingwin/connect`
- passa a 403: `PUT {id}/carmine-connection/{carmine_connection}`

**cliente_utilizador**

- passa a 403: `DELETE {id}/carmine-connection/{carmine_connection}`
- passa a 403: `DELETE {id}/integrations/covermanager`
- passa a 403: `DELETE {id}/integrations/google`
- passa a 403: `POST {id}/carmine-connection`
- passa a 403: `POST {id}/integrations/covermanager/connect`
- passa a 403: `POST {id}/integrations/google/connect`
- passa a 403: `POST {id}/integrations/pingwin/connect`
- passa a 403: `PUT {id}/carmine-connection/{carmine_connection}`

## 5. D8: os módulos que só escondiam o menu passam a ser verificados no backend

As 9 secções da restauração, a Análise de Marketing e as Tarefas. Só nas rotas exclusivas de cada módulo (`RoutePermissions::MODULES`, a partir do mapa das páginas do ecrã): as rotas partilhadas por páginas de módulos diferentes ficam só com o módulo principal (por exemplo, a lista dos fornecedores do PingWin, o estado do CoverManager e o tráfego do GA4, que o separador das integrações também lê).

**O suporte com a XPLENDOR é base** (resposta à pergunta 3 da noite): os pedidos de suporte, as mensagens, os orçamentos da XPLENDOR e o botão flutuante funcionam em todas as empresas, sem módulo. No catálogo, a área `suporte` ficou com "ver" e "criar", e as tarefas da equipa passaram a uma área própria, `tarefas`, que continua no módulo "Suporte / Tarefas". Os perfis de compatibilidade ficam com as duas; nos perfis personalizados, quem tinha o suporte fica também com as tarefas (migração `2026_12_23_100000_split_support_and_tasks_permissions`).

Na fotografia, a empresa sem módulos já não tinha o PingWin, por isso a diferença vê-se nas Tarefas e na Meta. As secções da restauração estão cobertas em `AccessF3DecisionsTest::test_d8_…` (uma empresa com o PingWin e sem secções).

**Administrador de uma empresa sem módulos**

- passa a 403: `GET`, `POST`, `PUT` e `DELETE` das tarefas (`{id}/tasks…`), `GET {id}/analytics/meta/overview`, `GET {id}/analytics/meta/ad-tag-warnings`
- o suporte com a XPLENDOR continua a abrir

## 6. Faturação da XPLENDOR só do Administrador (complemento ao pré-deploy, ponto 1)

Ver e aprovar a faturação da XPLENDOR (orçamentos e cobranças) é exclusivo do perfil **Administrador** da empresa e do root na própria empresa (D1). Em código: `Permissions::ADMIN_ONLY_AREAS` e o passo 5b do `Access` (motivo `so_administrador`).

- Nos perfis personalizados, nas sugestões e no teto da agência, a área aparece bloqueada com a nota "Só o Administrador"; o backend recusa gravar um perfil que a inclua (422).
- A sugestão "Financeiro" deixou de a ter. A "Agência convidada" e os perfis da agência nunca a tiveram e continuam sem ela.
- A lista dos orçamentos (`GET {id}/quotes`, `GET {id}/quotes/{quote}/pdf`, `GET {id}/support-tickets/quotes`) passou de `suporte.ver` a `faturacao_xplendor.ver`.
- Os pedidos de suporte continuam base para todos: o utilizador abre o pedido e conversa; o valor, as horas e a fatura do orçamento só vão ao Administrador (nos outros, a resposta traz `null`, e os valores em euros das mensagens da equipa são retirados). O email "orçamento para aprovar" vai para os administradores ativos quando o autor não é Administrador.
- Nas Despesas (Finanças), a despesa que espelha uma cobrança continua visível; a parte da cobrança (estado, fatura, "Já paguei") só vai ao Administrador.
- Migração `2026_12_24_100000_admin_only_xplendor_billing` (só dados): retira a área de todos os perfis exceto o Administrador.

**Quem perde o quê (diferença na fotografia):**

- **Utilizador do cliente e aprovador:** passam a 403 `GET {id}/quotes`, `GET {id}/quotes/{quote}/pdf`, `GET {id}/support-tickets/quotes`, `GET {id}/xplendor-charges`, `GET {id}/xplendor-charges/{chargeId}/invoice`, `PATCH {id}/quotes/{quote}/decision`, `PATCH {id}/support-tickets/{ticket}/quote-decision`, `POST {id}/support-tickets/quotes/approve` e `POST {id}/xplendor-charges/{chargeId}/paid`.
- **Root a impersonar um utilizador:** as mesmas (o pagamento já estava bloqueado na impersonation).
- **Administrador e membro da agência:** passam a 403 as três listas dos orçamentos (as cobranças e as decisões já estavam bloqueadas).
- **Root noutra empresa:** passam a 403 as três listas dos orçamentos e as cobranças (ver e a fatura). Trata a faturação no `/admin`, que não mudou.
- **Administrador do cliente e root na própria empresa:** nada muda.

## 6b. Bússola sem Finanças (complemento ao pré-deploy, ponto 2; sem mudança na fotografia)

Quem não tem a permissão de ver as Finanças (`financas.ver`) continua a abrir a Bússola (nenhuma rota muda de 403), mas **o backend não envia os valores em euros** (`App\Services\Restaurant\CompassFinancials`):

- **Desfocados no ecrã** (o backend manda `null` e `hidden: true`; o ecrã mostra um número fictício desfocado e a frase "Sem acesso aos valores financeiros"): a faturação das 4 semanas, os valores por artigo (mais vendidos), por hora e por dia (a grelha dos turnos e as barras dos dias para encher), e todos os campos em cêntimos (`*_cents`: faturação, valores dos artigos, margem e custos).
- **Visíveis:** as jogadas, as quantidades (unidades, reservas, pessoas), os mais e menos vendidos, as horas e os dias fortes, e as variações e pesos em percentagem.
- **Textos:** os valores em euros saem das frases dos sinais e das jogadas (`App\Support\Text\MoneyText`).
- **Onde:** a página da Bússola, o separador Marketing do dashboard do restaurante (as jogadas), o painel dos sinais (`GET {id}/integrations/pingwin/signals`) e o mapa da semana dentro da Bússola (`heatmap?for=bussola`: as vendas vão em intensidade de 0 a 100, sem euros). Não há exportes da Bússola.

**Outros ecrãs com valores de vendas da restauração, com outra permissão (não mudaram):**

- Dashboard do restaurante, separador Vendas (faturação, ticket médio, vendas por dia): `GET {id}/analytics/pingwin/dashboard`, `restauracao.ver`;
- Calendário de faturação: `GET {id}/analytics/pingwin/calendar`, `restauracao.ver`;
- Faturação mensal: `GET {id}/analytics/pingwin/monthly-billing`, `restauracao.ver`;
- Mapa da semana fora da Bússola (dashboard do restaurante): `GET {id}/integrations/pingwin/heatmap`, `restauracao.ver`;
- Cartão "Dados para o marketing" (Integrações › PingWin): `GET {id}/integrations/pingwin/marketing-data`, `restauracao.ver`;
- Documentos, faturas e conta corrente dos fornecedores do PingWin (custos): `restauracao.ver`.

A sugestão "Marketing" tem `restauracao.ver` sem `financas.ver`: vê a Bússola sem euros, mas continua a ver as vendas nestes ecrãs.

## 7. Antes e depois (rotas com 403 por ator)

| Ator | Antes | Depois (com o pré-deploy e o complemento) |
|---|---|---|
| Administrador do cliente (`cliente_admin`) | 3 | 3 |
| Utilizador do cliente (`cliente_utilizador`) | 54 | 76 |
| Utilizador aprovador (`cliente_aprovador`) | 51 | 70 |
| Root na própria empresa (`root_propria`) | 9 | 0 |
| Root noutra empresa (`root_outra`) | 19 | 21 |
| Administrador da agência (`agencia_admin`) | 28 | 36 |
| Membro da agência (`agencia_membro`) | 52 | 60 |
| Membro da agência, numa empresa que a agência criou e ainda sem administrador (`agencia_membro_criou`) | 51 | 59 |
| Root a impersonar o administrador (`impersonacao_admin`) | 56 | 61 |
| Root a impersonar um utilizador (`impersonacao_utilizador`) | 57 | 70 |
| Administrador de uma empresa sem módulos (`sem_modulos_admin`) | 214 | 221 |

Fotografias: `server/tests/Fixtures/acl/fotografia-antes/` (antes, congelada) e `server/tests/Fixtures/acl/fotografia/` (depois). Critério da F3 (`AccessSnapshotTest::test_403_if_and_only_if_access_denies`): para cada rota e cada ator, a resposta é 403 se e só se o Access disser que não.
