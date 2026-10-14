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
- **Onde:** a página da Bússola, o separador Marketing do dashboard do restaurante (as jogadas), o painel dos sinais (`GET {id}/integrations/pingwin/signals`) e o mapa da semana dentro da Bússola (rota própria `GET {id}/marketing/bussola/heatmap`, com `bussola.ver`: as vendas vão em intensidade de 0 a 100, sem euros). Não há exportes da Bússola.

**Outros ecrãs com valores de vendas da restauração, com outra permissão (não mudaram):**

- Dashboard do restaurante, separador Vendas (faturação, ticket médio, vendas por dia): `GET {id}/analytics/pingwin/dashboard`, `restauracao.ver`;
- Calendário de faturação: `GET {id}/analytics/pingwin/calendar`, `restauracao.ver`;
- Faturação mensal: `GET {id}/analytics/pingwin/monthly-billing`, `restauracao.ver`;
- Mapa da semana fora da Bússola (dashboard do restaurante): `GET {id}/integrations/pingwin/heatmap`, `restauracao.ver`;
- Cartão "Dados para o marketing" (Integrações › PingWin): `GET {id}/integrations/pingwin/marketing-data`, `restauracao.ver`;
- Documentos, faturas e conta corrente dos fornecedores do PingWin (custos): `restauracao.ver`.

**A sugestão "Marketing" deixou de ter `restauracao.ver`** (e não tem `financas.ver`): trabalha com as jogadas e as quantidades da Bússola, sem os ecrãs de vendas da restauração; no dashboard do restaurante vê só o separador "Marketing e resultados". O administrador pode acrescentá-los num perfil a partir da sugestão. Migração `2026_12_26_100000_marketing_suggestion_without_restaurant_sales` (só dados: atualiza a sugestão; os perfis já criados não mudam).

**Diferença na fotografia:** uma rota nova, `GET {id}/marketing/bussola/heatmap` (`bussola.ver`), aberta a todos os atores que veem a Bússola e com 403 na empresa sem módulos (como a Bússola). Nenhum ator perde nem ganha outras rotas.

## 6c. Ficheiros (noite dos Ficheiros): a área nova "ficheiros"

Área `ficheiros` (módulo "Ficheiros", desligado por omissão) com ver, criar (enviar e criar pastas), editar (mudar o nome e mover) e apagar. Como a área não existia na fotografia de antes, o que cada perfil de compatibilidade tem está decidido em `CompatibilityProfiles::NEW_AREAS`:

- **Administrador:** tudo.
- **Utilizador (como hoje):** ver, criar e editar.
- **Agência convidada (como hoje), o teto:** ver, criar e editar (mudar o nome e mover), sem apagar nem restaurar. No início era ver e criar; o editar entrou no pré-deploy dos Ficheiros.

Sugestões:
- **Só leitura:** ver;
- **Agência convidada:** ver, criar e editar (no início, ver e criar);
- **Marketing e Financeiro:** ver, criar e editar;
- **lado da agência:** o Gestor ver, criar e editar, e o Criativo ver e criar.

As pastas do sistema seguem, além disto, a permissão da sua área (`App\Services\Files\SystemFolders`).

**Diferença na fotografia:** só as 16 rotas novas (`{id}/files…`). Nenhum ator perde nem ganha outras rotas. Nas novas:
- **Administrador do cliente, root (na própria e noutra empresa) e impersonação do administrador:** nenhum 403.
- **Utilizador, aprovador e impersonação de um utilizador:** 403 em apagar e restaurar (pastas, ficheiros e atalhos).
- **Administrador e membro da agência:** 403 em apagar e restaurar (o teto dá ver, criar e editar).
- **Empresa sem módulos:** 403 em todas (módulo desligado).

**No pré-deploy dos Ficheiros (a agência passa a editar):**
- A migração `2026_12_29_100001_files_agency_edit` volta a gravar os perfis de sistema e as sugestões. Os perfis que cada empresa criou ficam como estão.
- Na fotografia, os três atores da agência (administrador, membro e membro numa empresa criada pela agência) deixam de ter 403 em duas rotas: `PUT {id}/files/{fileId}` (mudar o nome e mover um ficheiro) e `PUT {id}/files/folders/{folderId}` (o mesmo numa pasta).
- Na fotografia, essas duas rotas passam a dar 404, porque os identificadores do pedido não existem.
- Nenhum outro ator muda.

**Na F4 (a Linha Editorial nos dois sentidos):** mais uma rota, `POST {id}/editorial/media/from-file` ("Escolher dos Ficheiros"), com a permissão `editorial.criar` (como o envio de media da Linha Editorial) e, dentro, o módulo "Ficheiros" e `ficheiros.ver`. Na fotografia comporta-se como `editorial/media/uploads`, ator a ator: 403 só na empresa sem módulos; os restantes recebem 422 (o pedido da fotografia vai vazio).

## 6d. Bússola do stand (noite da Bússola do stand)

**Rotas novas:** 7, todas dentro de `ensure_module:stock`, com as permissões da área `bussola` (as da restauração):
- `GET {id}/marketing/bussola-stand` (`bussola.ver`);
- `POST {id}/marketing/bussola-stand/post` e `/caption` (`bussola.criar`);
- `GET {id}/marketing/bussola-stand/caption/{requestId}` (`bussola.ver`);
- `POST {id}/marketing/bussola-stand/ignore`, `/restore` e `/exclude` (`bussola.editar`).

Não exigem a área automóvel, porque a Bússola não mostra dados pessoais. Criar, sugerir e ignorar exigem ainda a Linha Editorial ativa e a pessoa produzir nela (o mesmo "pode agir" da restauração, na lista dos 403 permitidos de `TenancyArchitectureTest`).

**Diferença na fotografia:**
- Só as 7 rotas novas (de 388 para 395 em cada ator). Nenhum ator ganha nem perde acesso a rotas que já existiam.
- Nas rotas novas, só o administrador da empresa sem módulos recebe 403, nas 7 (sem o módulo de stock).
- Os outros 10 atores não recebem 403: a página dá 200; as escritas dão 422 e o estado das propostas 404, porque o pedido da fotografia vai vazio, como nas rotas da Bússola da restauração.

**No pré-deploy da Bússola do stand (a área "Bússola" vale para os dois módulos):**
- A área `bussola` passa a ter dois módulos, PingWin ou stock (`'module' => ['pingwin', 'stock']`; basta um ativo; `Permissions::areaModules`).
- Muda só o editor de perfis:
  - a pré-visualização deixa de dizer "Bússola: módulo inativo" numa empresa só de stand;
  - o catálogo das áreas envia os dois módulos.
- **Diferença na fotografia: nenhuma.** A fotografia reescrita é igual à anterior: o acesso usa o módulo de cada rota (`ensure_module`), não o da área. Nenhum ator ganha nem perde acesso.

## 6e. Radar de oportunidade (Bússola Master, R2)

**Antes, a correção da contagem:** o commit `f869922` (trabalho F2d de outra pessoa, OCR da restauração) acrescentou 4 rotas (`GET .../integrations/pingwin/articles/{catalogItemId}/supplier-codes`, `GET .../integrations/pingwin/catalog/vat-divergences`, `POST .../ocr/invoices/{invoiceId}/lines/{lineId}/article-vat` e `POST .../ocr/invoices/{invoiceId}/lines/{lineId}/supplier-code/principal`) e reescreveu a fotografia (395 para 399 rotas; `sem_modulos_admin` de 249 para 253) sem atualizar a tabela da secção 7. A tabela passa a ter a coluna "Depois do F2d" com os valores reais.

**Rotas novas:** 7, todas dentro de `ensure_module:radar`, com as permissões da área `bussola`:
- `GET {id}/marketing/radar` e `GET {id}/marketing/radar/settings` (`bussola.ver`);
- `POST {id}/marketing/radar/react` (`bussola.criar`);
- `POST {id}/marketing/radar/ignore`, `/mute` e `/unmute`, e `PUT {id}/marketing/radar/settings` (`bussola.editar`).

Reagir, ignorar e silenciar exigem ainda a Linha Editorial ativa e a pessoa produzir nela (o mesmo "pode agir" da Bússola, `RadarController` na lista dos 403 permitidos de `TenancyArchitectureTest`). A área `bussola` passa a valer também com o módulo `radar` (`'module' => ['pingwin', 'stock', 'radar']`), só para o editor de perfis não dizer "módulo inativo" numa empresa que só tem o Radar; o acesso de cada rota continua a ser o do seu `ensure_module`.

Rotas de administração novas (fora do varrimento `/companies/{id}`, no grupo `ensure_super_admin`): `GET /admin/radar`, `GET /admin/radar/items` (do R1), `GET /admin/radar/relevances` e `POST /admin/radar/companies/{company}/switch`.

**Diferença na fotografia:**
- Só as 7 rotas novas (de 399 para 406 em cada ator). Nenhum ator ganha nem perde acesso a rotas que já existiam: o conjunto das rotas proibidas que já existiam é igual em todos os atores.
- Nas rotas novas, só o administrador da empresa sem módulos recebe 403, nas 7 (sem o módulo `radar`): de 253 para 260.
- Os outros 10 atores não recebem 403 nas rotas novas (o módulo `radar` está ligado nas empresas do varrimento, que ligam todos os módulos): a página e as definições dão 200; as escritas dão 422, porque o pedido da fotografia vai vazio.

## 6f. Imobiliário e calendário (Bússola Master, R3)

**Rotas novas:** 12, todas dentro de `ensure_module:imoveis`:
- a Bússola do imobiliário, com as permissões da área `bussola` (as do stand): `GET {id}/marketing/bussola-imoveis` e `GET .../caption/{requestId}` (`bussola.ver`); `POST .../post` e `/caption` (`bussola.criar`); `POST .../ignore`, `/restore` e `/exclude` (`bussola.editar`);
- a carteira, na área nova `imoveis`: `GET {id}/properties` e `GET {id}/properties/{propertyId}` (`imoveis.ver`), `POST {id}/properties` (`imoveis.criar`), `PUT {id}/properties/{propertyId}` (`imoveis.editar`) e `DELETE {id}/properties/{propertyId}` (`imoveis.apagar`).

A área `imoveis` segue o padrão dos Ficheiros (resposta à pergunta 7): o Administrador tudo; o Utilizador ver, criar e editar; a agência (teto da agência convidada) ver, criar e editar, sem apagar (`CompatibilityProfiles::NEW_AREAS`; a sugestão "Agência convidada" ganha `imoveis.ver`, `imoveis.criar` e `imoveis.editar`). A área `bussola` passa a valer também com o módulo `imoveis`. Criar, sugerir e ignorar na Bússola do imobiliário exigem ainda a Linha Editorial ativa e a pessoa produzir nela (`ImoveisCompassController` na lista dos 403 permitidos). O "Reagir" a uma data do calendário usa a rota do Radar que já existe (`POST {id}/marketing/radar/react`).

**Diferença na fotografia:**
- Só as 12 rotas novas (de 406 para 418 em cada ator). Nenhum ator ganha nem perde acesso a rotas que já existiam.
- Nas rotas novas: o administrador da empresa sem módulos recebe 403 nas 12 (sem o módulo `imoveis`): de 260 para 272. Quem não tem `imoveis.apagar` recebe 403 só em `DELETE {id}/properties/{propertyId}`: o utilizador do cliente (82 para 83), o aprovador (76 para 77), os três atores da agência (42 para 43, 66 para 67 e 65 para 66) e o root a impersonar um utilizador (76 para 77). O administrador do cliente, o root e o root a impersonar o administrador não recebem 403 nas rotas novas.
- No varrimento da agência (`AgencyTenancySweepTest`), a rota de apagar um imóvel junta-se às dos Ficheiros acima do teto (`IMOVEIS_ABOVE_CEILING`).

## 7. Antes e depois (rotas com 403 por ator)

| Ator | Antes | Depois (com o pré-deploy e o complemento; 395 rotas) | Depois do F2d (`f869922`; 399 rotas) | Depois do Radar (6e; 406 rotas) | Depois do imobiliário (6f; 418 rotas) |
|---|---|---|---|---|---|
| Administrador do cliente (`cliente_admin`) | 3 | 3 | 3 | 3 | 3 |
| Utilizador do cliente (`cliente_utilizador`) | 54 | 82 | 82 | 82 | 83 |
| Utilizador aprovador (`cliente_aprovador`) | 51 | 76 | 76 | 76 | 77 |
| Root na própria empresa (`root_propria`) | 9 | 0 | 0 | 0 | 0 |
| Root noutra empresa (`root_outra`) | 19 | 21 | 21 | 21 | 21 |
| Administrador da agência (`agencia_admin`) | 28 | 42 | 42 | 42 | 43 |
| Membro da agência (`agencia_membro`) | 52 | 66 | 66 | 66 | 67 |
| Membro da agência, numa empresa que a agência criou e ainda sem administrador (`agencia_membro_criou`) | 51 | 65 | 65 | 65 | 66 |
| Root a impersonar o administrador (`impersonacao_admin`) | 56 | 61 | 61 | 61 | 61 |
| Root a impersonar um utilizador (`impersonacao_utilizador`) | 57 | 76 | 76 | 76 | 77 |
| Administrador de uma empresa sem módulos (`sem_modulos_admin`) | 214 | 249 | 253 | 260 | 272 |

Fotografias: `server/tests/Fixtures/acl/fotografia-antes/` (antes, congelada) e `server/tests/Fixtures/acl/fotografia/` (depois). Critério da F3 (`AccessSnapshotTest::test_403_if_and_only_if_access_denies`): para cada rota e cada ator, a resposta é 403 se e só se o Access disser que não.
