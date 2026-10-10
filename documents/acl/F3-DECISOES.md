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

## 2. D1: o root passa em tudo, exceto nas decisões do cliente

As decisões do cliente são `editorial.aprovar`, `blog.aprovar`, `faturacao_xplendor.aprovar` (aceitar orçamentos, indicar o pagamento de uma cobrança) e `empresa.aprovar` (aceitar, recusar ou terminar a gestão por uma agência). Nem na própria empresa o root as toma (como hoje na Linha Editorial).

**Atenção:** a D1 diz "como hoje", mas hoje o root aceitava orçamentos noutras empresas, aprovava artigos do blog na própria empresa e indicava pagamentos de cobranças. Com a D1 deixa de poder (o root continua a marcar as cobranças como pagas no painel /admin). Ver `documents/NOITE-PERGUNTAS.md`.

**root_outra**

- passa a 403: `PATCH {id}/quotes/{quote}/decision`
- passa a 403: `PATCH {id}/support-tickets/{ticket}/quote-decision`
- passa a 403: `POST {id}/support-tickets/quotes/approve`
- passa a 403: `POST {id}/xplendor-charges/{chargeId}/paid`
- deixa de dar 403: `DELETE {id}/collaborators/{collaborator}/access/invite` (agora 404)
- deixa de dar 403: `POST {id}/collaborators/{collaborator}/access` (agora 200)
- deixa de dar 403: `POST {id}/collaborators/{collaborator}/access/resend` (agora 200)
- deixa de dar 403: `POST {id}/collaborators/{collaborator}/access/restore` (agora 422)
- deixa de dar 403: `POST {id}/collaborators/{collaborator}/access/revoke` (agora 422)
- deixa de dar 403: `POST {id}/users` (agora 200)
- deixa de dar 403: `PUT {id}/editorial/approvers/{userId}` (agora 422)
- deixa de dar 403: `PUT {id}/users/{user}` (agora 200)

**root_propria**

- passa a 403: `DELETE {id}/management`
- passa a 403: `PATCH {id}/quotes/{quote}/decision`
- passa a 403: `PATCH {id}/support-tickets/{ticket}/quote-decision`
- passa a 403: `POST {id}/blogs/{blog}/approve`
- passa a 403: `POST {id}/blogs/{blog}/back-to-draft`
- passa a 403: `POST {id}/blogs/{blog}/request-changes`
- passa a 403: `POST {id}/support-tickets/quotes/approve`
- passa a 403: `POST {id}/xplendor-charges/{chargeId}/paid`
- deixa de dar 403: `POST {id}/users` (agora 200)
- deixa de dar 403: `PUT {id}/users/{user}` (agora 200)

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

## 5. D8: os 11 módulos que só escondiam o menu passam a ser verificados no backend

As 9 secções da restauração, a Análise de Marketing e o Suporte e Tarefas. Só nas rotas exclusivas de cada módulo (`RoutePermissions::MODULES`, 81 rotas, a partir do mapa das páginas do ecrã): as rotas partilhadas por páginas de módulos diferentes ficam só com o módulo principal (por exemplo, a lista dos fornecedores do PingWin, o estado do CoverManager e o tráfego do GA4, que o separador das integrações também lê).

Na fotografia, a empresa sem módulos já não tinha o PingWin, por isso a diferença vê-se no Suporte, nas Tarefas e na Meta; as secções da restauração estão cobertas em `AccessF3DecisionsTest::test_d8_…` (uma empresa com o PingWin e sem secções).

**Atenção:** o botão flutuante do suporte aparece em todas as empresas. Numa empresa sem o módulo Suporte e Tarefas passa a dar 403; a F4 esconde-o quando o módulo está desligado.

**sem_modulos_admin**

- passa a 403: `DELETE {id}/tasks/{task}`
- passa a 403: `GET {id}/analytics/meta/ad-tag-warnings`
- passa a 403: `GET {id}/analytics/meta/overview`
- passa a 403: `GET {id}/support-tickets`
- passa a 403: `GET {id}/support-tickets/quotes`
- passa a 403: `GET {id}/support-tickets/{ticket}`
- passa a 403: `GET {id}/tasks`
- passa a 403: `PATCH {id}/support-tickets/{ticket}/quote-decision`
- passa a 403: `PATCH {id}/tasks/{task}/move`
- passa a 403: `POST {id}/support-tickets`
- passa a 403: `POST {id}/support-tickets/quotes/approve`
- passa a 403: `POST {id}/support-tickets/{ticket}/messages`
- passa a 403: `POST {id}/tasks`
- passa a 403: `PUT {id}/tasks/{task}`

## 6. Antes e depois (rotas com 403 por ator)

| Ator | Antes | Depois da F3 |
|---|---|---|
| Administrador do cliente (`cliente_admin`) | 3 | 3 |
| Utilizador do cliente (`cliente_utilizador`) | 54 | 62 |
| Utilizador aprovador (`cliente_aprovador`) | 51 | 56 |
| Root na própria empresa (`root_propria`) | 9 | 15 |
| Root noutra empresa (`root_outra`) | 19 | 15 |
| Administrador da agência (`agencia_admin`) | 28 | 28 |
| Membro da agência (`agencia_membro`) | 52 | 52 |
| Membro da agência, numa empresa que a agência criou e ainda sem administrador (`agencia_membro_criou`) | 51 | 51 |
| Root a impersonar o administrador (`impersonacao_admin`) | 56 | 56 |
| Root a impersonar um utilizador (`impersonacao_utilizador`) | 57 | 57 |
| Administrador de uma empresa sem módulos (`sem_modulos_admin`) | 214 | 228 |

Fotografias: `server/tests/Fixtures/acl/fotografia-antes/` (antes, congelada) e `server/tests/Fixtures/acl/fotografia/` (depois). Critério da F3 (`AccessSnapshotTest::test_403_if_and_only_if_access_denies`): para cada rota e cada ator, a resposta é 403 se e só se o Access disser que não.
