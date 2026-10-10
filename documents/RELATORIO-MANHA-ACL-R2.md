# Relatório da manhã: noite do ACL e do R2 (10 de outubro de 2026)

Tudo no main, sem deploy, sem migrações não aditivas e sem nenhuma chamada real à Meta, à Cloudflare, ao PingWin, ao CoverManager, ao GA4 ou a fornecedores de IA. O pedido está em `documents/NOITE-ACL-R2.md`, e o desenho aprovado (o spike com as decisões D1 a D14) em `documents/ACL-DESENHO.md`.

Antes de começar, desliguei o interruptor das vendas por artigo da Yuko **em dev** (`pingwin:item-sales-switch 5 off`).

## 1. O que ficou feito, fase a fase

Todas as fases ficaram verdes; nenhuma parou.

| Fase | Commit | O que ficou feito |
|---|---|---|
| Pedido e desenho | `104b62b` | `NOITE-ACL-R2.md` (o pedido, com o nome do ficheiro corrigido) e `ACL-DESENHO.md` (o spike com as decisões) |
| F0, hotfix de isolamento | `263a148` | Despesas: categoria, fornecedor e viatura só da empresa do endereço, e o serviço volta a verificar. Viaturas: vendedor só da empresa, e o contacto público nunca vem de outra empresa. Encontrados e corrigidos mais três: o contacto e a visualização públicos (`car-lead`, `car-view`) aceitavam viaturas de outra empresa, e as campanhas de uma viatura não verificavam a viatura |
| F1, Access em modo sombra | `8b5c7f8` | Catálogo área × ação, permissão declarada em cada uma das 359 rotas de empresa, `Access` com o motivo em português, middleware `permission` em modo sombra, a fotografia do varrimento e os perfis de compatibilidade derivados dela (sem conflitos). Zero divergências e zero inconclusivos |
| F2, perfis de compatibilidade | `f8fc005` | Tabelas `permission_profiles`, `profile_permissions` e `permission_profile_events`; colunas `users.profile_id`, `users.agency_profile_id` e `company_managements.guest_profile_id`; cinco perfis de sistema atribuídos pelo papel de hoje. A fotografia ficou igual antes e depois |
| F3, bloqueio e decisões | `799297e` | O middleware passou a bloquear; um só formato de 403; as verificações de papel saíram dos controllers; D1, D6, D7 e D8, uma a uma, com a diferença de cada uma (`documents/acl/F3-DECISOES.md`); teste de arquitetura com as subpastas e sem comparar o papel |
| F4, ecrã | `51b5d2b` | `GET /my-access`, `useCan` com os motivos do backend, menu e rotas a falhar fechados, papéis só em `helpers/roles.ts` (com um teste que o garante), botão do suporte só com o módulo |
| F5, perfis | `2aa91f7` | Separador **Perfis** em Configurações › Colaboradores: sugestões (D13), pré-visualização antes de gravar, atribuição a cada pessoa, sempre um administrador (D12), Criativo externo só nos clientes atribuídos (D11), teto da agência no cartão da agência gestora |
| R2 | `9753f64` | Disco R2 por configuração (local por omissão), endereços assinados de curta duração, envio em partes, ffmpeg e miniaturas numa cópia temporária, retenção, quota e apagamento aos 90 dias, migração retomável com confirmação, apagamento do local à parte, MinIO em dev, método para a Meta |

A F5 tinha como critério "o caso real verificado em produção". Pela D14, nada se configura em produção esta noite; o caso foi verificado em dev (ver a secção 2).

## 2. Testes, fotografia e capturas

**Testes**
- **Backend:** a suite completa tem 1764 testes; falham **as mesmas 24 de sempre**, sem nenhuma nova, em todas as fases.
- **Frontend:** 76 testes a passar (o `App.test.tsx` continua a falhar, como antes); tsc e eslint sem erros nos ficheiros desta noite.
- **Ficheiros de testes novos:**
  - `CompanyIsolationF0Test`;
  - em `tests/Feature/Access`: `AccessSnapshotTest`, `AccessCatalogTest`, `AccessDecisionTest`, `CompatibilityMigrationTest`, `AccessF3DecisionsTest`, `MyAccessTest` e `PermissionProfilesTest`;
  - `R2StorageTest`;
  - no ecrã: `ModulesContext.test`, `RequireModule.test`, `roles.test` e `ProfileEditorModal.test`.

**A fotografia do varrimento.** São 11 atores × 359 rotas, com o estado e a mensagem de cada 403.
- Antes: `documents/acl/FOTOGRAFIA-ANTES.md`, com os ficheiros em `server/tests/Fixtures/acl/fotografia-antes/`.
- Depois: `server/tests/Fixtures/acl/fotografia/`.
- A diferença, decisão a decisão, está em `documents/acl/F3-DECISOES.md`. Em resumo:

| Ator | Rotas com 403 antes | Depois |
|---|---|---|
| Administrador do cliente | 3 | 3 |
| Utilizador do cliente | 54 | 62 (D7: já não liga integrações) |
| Utilizador aprovador | 51 | 56 (D7; com a D6 passa a aprovar o blog) |
| Root na própria empresa | 9 | 15 (D1) |
| Root noutra empresa | 19 | 15 (D1) |
| Administrador da agência | 28 | 28 |
| Membro da agência | 52 | 52 |
| Membro da agência numa empresa que criou | 51 | 51 |
| Root a impersonar o administrador | 56 | 56 |
| Root a impersonar um utilizador | 57 | 57 |
| Administrador de uma empresa sem módulos | 214 | 228 (D8) |

As rotas novas da F4 e da F5 (`my-access`, perfis, teto) acrescentam-se depois. A fotografia final está nos ficheiros.

**Capturas** (sem dados pessoais: as pessoas reais de dev aparecem como "Pessoa (oculta)"):
- ACL: `documents/acl/capturas/`, 23 capturas, por perfil, no computador e no telemóvel, em claro e escuro. O que cada perfil consegue abrir está em `documents/acl/VERIFICACAO-ECRA.md`.
- R2: `documents/r2/capturas/`. Uma publicação com a imagem servida do MinIO, e uma fatura do OCR lida do MinIO.
  - O Chromium sem janela não desenha PDFs, por isso o painel da fatura aparece vazio.
  - O pedido respondeu 200 `application/pdf`.

## 3. Decisões técnicas tomadas por omissão (para confirmar)

**ACL**
1. **A permissão de cada rota está num só ficheiro** (`server/app/Access/RoutePermissions.php`), em vez de um `->middleware('permission:...')` linha a linha no `routes/api.php`. O middleware `permission` está nos grupos. Um teste falha se uma rota de empresa não estiver no catálogo.
2. **Duas colunas de perfil:** `users.profile_id` (o perfil na própria empresa) e `users.agency_profile_id` (o perfil dentro dos clientes, para quem trabalha numa agência). Com uma só, um membro de agência ganharia ou perderia acessos num dos dois casos. A D4 (um utilizador, uma empresa) mantém-se.
3. **O aprovador de conteúdos** continua a ser a coluna `can_approve_content` (escolhida na Linha Editorial). Soma `editorial.aprovar` e, com a D6, `blog.aprovar` ao perfil, em vez de um perfil à parte.
4. **Duas áreas que o spike não tinha:**
   - `plataforma`, só do root: apagar empresas e os postos de venda do PingWin;
   - `agencia`: convidar o primeiro administrador de um cliente; nunca entra num perfil do lado do cliente.
5. **`empresa.ver` é base:** quem trabalha na empresa vê-a sempre. Sem isso, um perfil como a Agência convidada (D14) não abria o ecrã: o `/my-access`, os avisos e o dashboard base usam essa permissão.
6. **D1 aplica-se também na própria empresa do root:** o root nunca toma decisões do cliente (como hoje na Linha Editorial). Ver a pergunta 1.
7. **O dashboard de vendas da restauração** é `restauracao.ver`, por isso o "Só leitura" não o vê. Ver a pergunta 2.
8. **Regras de hoje que ficaram no `Access`** (regras transversais, fora dos perfis):
   - em sessão como cliente, a equipa edita os conteúdos;
   - o root não é filtrado por módulos;
   - cada pessoa altera a própria conta;
   - algumas rotas sensíveis bloqueiam durante a impersonation.
9. **O modo sombra só existe nos testes.** Depois da F3 as verificações antigas saíram dos controllers, por isso o middleware bloqueia sempre fora dos testes (`ACCESS_MODE` não tem efeito em produção).
10. **O perfil Administrador anda com o papel `admin`**, e qualquer outro perfil com o papel `user`. Ainda há código antigo (notificações, serviços) que lê o papel.
11. **D8 só nas rotas exclusivas de cada módulo** (81 rotas, a partir do mapa das páginas). As partilhadas (por exemplo, a lista dos fornecedores do PingWin) ficam só com o módulo principal.
12. **Perfis de agência e tetos:** nunca as decisões do cliente, `utilizadores.configurar` nem a faturação da XPLENDOR.
13. **As sugestões são modelos de sistema** que não se atribuem diretamente: copiam-se para um perfil da empresa (D13: "nunca imposta").
14. **Um só formato de 403:** `{success: false, message, reason, errors}`. O "This action is unauthorized." do Laravel passou a "Não tem permissão para esta ação.".
15. **O painel da agência** (`/agencies/...`) ficou fora do catálogo, com o seu portão próprio; a regra "administra a agência" passou para o `CompanyAccess`.
16. **Os membros atuais das agências** ficam com "Gestor de clientes (como hoje)". Os perfis prontos de agência (Gestor de clientes, Criativo externo) são sugestões.

**R2**

17. **O disco `public`** (imagens das viaturas, logótipos, fotografias, banners) fica local: é servido pelo nginx e guardado como URL. Os PDFs dos orçamentos também ficam locais (crescem devagar).
18. **As partes de um envio** ficam sempre no disco local; os media saem por um redirecionamento para um endereço assinado do R2 de 5 minutos. As cobranças e o OCR continuam a sair em bytes pelo backend.
19. **`OCR_INVOICE_DISK`** passa a seguir `PRIVATE_FILES_DISK` por omissão.
20. **Dependências novas:** `league/flysystem-aws-s3-v3` (e, só em dev, `league/flysystem-memory`).
21. **MinIO em dev** com `minio/minio:latest` e `minio/mc:latest` da cache local: o Docker Hub recusou as versões fixas.

## 4. Perguntas pendentes

Estão em `documents/NOITE-PERGUNTAS.md`, cada uma com a decisão tomada por omissão:
1. **D1 diz "como hoje", mas hoje o root fazia algumas decisões do cliente:** aceitava orçamentos noutras empresas, aprovava artigos do blog na própria empresa e indicava pagamentos. Com a D1 deixa de poder.
2. **D9:** o "Só leitura" vê o dashboard de vendas da restauração e a área automóvel? Por omissão, não.
3. **O botão flutuante do suporte** numa empresa sem o módulo "Suporte / Tarefas": há empresas em produção nesse caso?

## 5. O que muda para os utilizadores atuais quando isto for para produção

- **Utilizadores comuns dos clientes** (perfil "Utilizador (como hoje)"): **deixam de ligar, alterar ou desligar o PingWin, o CoverManager, o GA4 e a Carmine** (D7). Já não ligavam a Meta nem as redes sociais.
- **Aprovadores de conteúdos:** passam a **aprovar e devolver artigos do blog** (D6).
- **Root, a equipa XPLENDOR (D1):**
  - **passa a poder**, em qualquer empresa: criar e alterar utilizadores, dar e retirar acessos aos colaboradores, e escolher os aprovadores;
  - **deixa de poder**, em qualquer empresa (incluindo a própria): aceitar orçamentos, indicar o pagamento de uma cobrança, aprovar artigos do blog e decidir sobre a gestão por agências;
  - continua a marcar as cobranças como pagas no `/admin`.
- **Empresas sem o módulo "Suporte / Tarefas"** perdem o suporte, as tarefas, a página dos orçamentos e o botão flutuante (D8).
- **Empresas sem a "Análise de Marketing"** perdem a página da Meta / Anúncios (o tráfego do site continua: também é lido no separador das integrações).
- **Empresas do PingWin sem uma secção** (lojas, artigos, faturas…) deixam de chegar a essa secção também pela API (D8).
- **Todos:**
  - as mensagens de recusa mudam de texto: vêm do backend, com o motivo ("O seu perfil não permite editar em Marca.");
  - enquanto as permissões carregam, as páginas mostram "A carregar" em vez de abrirem logo.
- **Administradores dos clientes e das agências:**
  - têm o separador **Perfis**;
  - não conseguem retirar o perfil nem o acesso ao último administrador ativo;
  - os clientes geridos veem e escolhem o **teto da agência** no cartão da agência gestora (por omissão, "Agência convidada (como hoje)": a agência continua a fazer o que faz hoje).
- **Agências:** nada muda, enquanto não forem atribuídos perfis como o Criativo externo.
- **A Media Tailors na Yuko (D14) não foi configurada.** Está pronta a sugestão "Agência convidada" (Linha Editorial ver, criar, editar e apagar; Bússola ver, criar e editar; resultados ver). Quando quiser, o administrador da Yuko cria o perfil a partir dela e atribui-o às pessoas da Media Tailors.

## 6. Os seus passos para amanhã

O detalhe está em `documents/r2/R2-PRODUCAO.md`.

1. **Rever as três perguntas** (secção 4) antes do deploy. A pergunta 3 pode pedir ligar o módulo "Suporte / Tarefas" em algumas empresas antes do deploy.
2. **Na Cloudflare:**
   - criar o bucket `xplendor-media`, privado; recomendo a jurisdição da UE;
   - criar uma chave R2 com **Object Read & Write** só para esse bucket;
   - guardar o Access Key ID, o Secret Access Key e o endereço S3 do bucket.
3. **No `.env` de produção:** acrescentar `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET=xplendor-media`, `R2_ENDPOINT=https://<ID_DA_CONTA>.eu.r2.cloudflarestorage.com`, `R2_REGION=auto`, `R2_PATH_STYLE=true` e `MEDIA_EXTERNAL_FETCH_TTL=3600`. **Por agora, manter `MEDIA_DISK=media` e `PRIVATE_FILES_DISK=local`.**
4. **Deploy:**
   - fazer push e correr o `deploy.sh`; entram três migrações aditivas: perfis, sugestões e registo da migração dos ficheiros;
   - depois: `docker exec xplendor-php composer install --no-dev --optimize-autoloader`, `php artisan config:cache` e `php artisan queue:restart`;
   - confirmar `php artisan acl:migrate-profiles`: deve dizer 0 utilizadores com perfil novo, porque a migração já os atribuiu.
5. **Testar a ligação ao R2** com o comando do passo 4.3 do guia; deve escrever `ok`.
6. **Migrar os ficheiros:**
   - `php artisan storage:migrate-to-r2` (simulação), depois `--execute` até zero falhas;
   - mudar para `MEDIA_DISK=r2` e `PRIVATE_FILES_DISK=r2`, com `config:cache` e `queue:restart`;
   - `--execute` outra vez;
   - confirmar no ecrã: uma imagem, um vídeo, uma fatura do OCR e uma cobrança.
7. **Uns dias depois:** `php artisan storage:purge-local` (simulação) e depois `--execute`.

Fora do pedido, mas notado:
- Os problemas de exposição do disco público estão em `documents/r2/INVENTARIO.md`: as fotografias originais das viaturas, as faturas dos tickets e as fotografias dos relatórios de satisfação são públicas.
- Salvar uma fatura do OCR parece usar PATCH quando a rota só aceita PUT. Não mexi.
