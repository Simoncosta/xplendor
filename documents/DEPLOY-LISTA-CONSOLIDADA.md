# Lista de deploy consolidada (Finanças, PingWin, ACL, R2 e pré-deploy)

Escrita a 10 de outubro de 2026. Nada disto foi feito em produção. Cobre tudo o que está no `main` desde o último deploy conhecido.

**Quem perde o quê, numa linha:** os utilizadores comuns dos clientes deixam de ligar, alterar ou desligar o PingWin, o CoverManager, o GA4 e a Carmine; o root deixa de tomar as decisões do cliente nas **outras** empresas (aceitar orçamentos, indicar o pagamento de uma cobrança, aprovar artigos do blog, decidir sobre a gestão por agências), mas na XPLENDOR continua a poder tudo; as empresas sem o módulo "Suporte / Tarefas" perdem só as tarefas; as empresas sem a "Análise de Marketing" perdem a página da Meta / Anúncios; as empresas do PingWin sem uma secção (lojas, artigos, documentos) deixam de lhe chegar também pela API. Mais ninguém perde nada.

## 1. Desde quando

- O último deploy conhecido é `e6c0596` (7 de outubro): era o `origin/main` quando se preparou o deploy do PingWin, e não houve deploy desde então.
- Entram **40 commits**, de `837f4c6` ao commit deste documento.
- O `origin/main` está em `21f993c`: **falta fazer push de 17 commits** (do ACL a este documento) antes do deploy.
- **Confirmar no servidor antes** (só leituras):
  ```
  cd /home/xplendor
  git log -1 --oneline                     # deve ser e6c0596
  git status --short                       # deve mostrar só docker/scraper/scraper.log
  docker exec xplendor-php php artisan migrate:status | grep -i pending   # nada
  ```
  Se o `git log` não for `e6c0596`, entram também os commits entre esse e `e6c0596`.

| Bloco | Commits | O que é |
|---|---|---|
| CoverManager | `837f4c6`, `255dfbf` | token mascarado nos registos de erro; mapa dos códigos de estado e reservas "por classificar" |
| PingWin para o marketing | `8c1bfb6` a `6314808`, `8e497da`, `7d5b822` | vendas por artigo e por hora, histórico, qualidade dos dados, períodos fracos, "O que publicar e quando" (tudo atrás do interruptor `pingwin_item_sales_enabled`, desligado) |
| Bússola | `cd346f9`, `ff51c99` | redesenho e resumo no dashboard do restaurante |
| deploy.sh e registos | `9223545`, `5cc67ae`, `e29a854` | `scraper.log` fora do git; pull antes do down; `composer install` em todos os deploys |
| Finanças | `2e41ce4`, `8d0f666`, `c89bdf7`, `383fb78`, `21f993c` | conta corrente e documentos de fornecedor do PingWin (S1, F1, F4), escrita de fornecedores e documentos no PingWin (S2), OCR das faturas com QR da AT, artigos e lançamento |
| ACL | `104b62b` a `2aa91f7`, `bdb9a00` | isolamento entre empresas (F0), perfis e permissões em todas as rotas (F1 a F5) |
| R2 | `9753f64` | ficheiros privados no R2 por configuração, com migração (discos ainda locais) |
| Pré-deploy | `0332e6e` a `2b62901`, e este documento | root na própria empresa, suporte na área base, PATCH do OCR, exposição de ficheiros, commonmark, composer no deploy |

## 2. Só no primeiro deploy: o pull à mão

O servidor ainda tem o `deploy.sh` de `e6c0596`, que faz o `down` **antes** do pull e não corre o Composer. Além disso, `docker/scraper/scraper.log` saiu do git e tem alterações no servidor, por isso um `git pull` simples recusa-se a continuar. Desta vez, o pull faz-se à mão e só depois se corre o script (já o novo):

```
cd /home/xplendor
cp docker/scraper/scraper.log /home/scraper.log.antes-do-deploy
git checkout -- docker/scraper/scraper.log
git pull --ff-only origin main
cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log
git status --short              # não deve aparecer nada
bash deploy-scripts/deploy.sh   # ou como o deploy é corrido habitualmente
```

- Se o `git pull` falhar, nada foi desligado: devolver o registo (`cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log`) e ver o motivo antes de continuar.
- O `deploy.sh` novo faz, por esta ordem: pull (já não traz nada), `down`, `up -d --build`, **`composer install --no-dev --optimize-autoloader`**, migrações, `storage:link`, caches, `queue:restart`, compilação do `web` e do `site`, e reinício do nginx.
- Nos deploys seguintes, basta o `deploy.sh`.

## 3. Migrações (25, correm no `deploy.sh`, pela ordem)

**Não aditivas: uma.** `2026_12_18_100000_f2b_ocr_line_articles` altera uma coluna existente: `ocr_invoice_lines.quantity` passa de `decimal(12,3)` a `decimal(18,6)`. Só alarga, sem perda de dados, numa tabela pequena.

Três mexem em dados, sem apagar nada que exista hoje em produção (assinaladas com **dados**).

| Migração | O que faz |
|---|---|
| `2026_12_04_100000_create_pingwin_item_sales` | tabelas das vendas por artigo; `companies.pingwin_item_sales_enabled`, **desligado** por omissão |
| `2026_12_05_100000_add_item_history_to_pingwin_locations` | colunas do histórico e do início das lojas |
| `2026_12_06_100000_create_restaurant_family_categories_and_quality` | categorias das famílias e qualidade dos dados |
| `2026_12_07_100000_create_f2_hourly_and_reservation_aggregates` | vendas por hora e agregados das reservas |
| `2026_12_08_100000_create_restaurant_signals` | sinais da F3 |
| `2026_12_09_100000_add_unclassified_count_to_cm_reservation_shift_summary` | reservas "por classificar" |
| `2026_12_09_100000_create_restaurant_compass_texts` | textos da Bússola |
| `2026_12_10_100000_create_restaurant_excluded_items` | artigos excluídos das análises |
| `2026_12_11_100000_add_settled_to_pingwin_document_configs` | coluna `settled`, preenchida a partir dos dados que já existem (só na coluna nova) |
| `2026_12_11_100001_create_pingwin_supplier_cc_documents` | conta corrente dos fornecedores: documentos |
| `2026_12_11_100002_create_pingwin_supplier_cc_balances` | conta corrente dos fornecedores: saldos |
| `2026_12_12_100000_create_pingwin_supplier_documents` | documentos de fornecedor |
| `2026_12_12_100001_create_pingwin_document_sync_runs` | registo das sincronizações |
| `2026_12_13_100000_grant_restauracao_conta_corrente_module` | **dados:** liga o módulo `restauracao_conta_corrente` às empresas que têm o módulo `pingwin` (só acrescenta) |
| `2026_12_14_100000_create_pingwin_supplier_writes` | escritas de fornecedores no PingWin; três colunas novas em `suppliers` |
| `2026_12_15_100000_add_qr_pipeline_to_ocr_invoices` | colunas do QR da AT nas faturas do OCR |
| `2026_12_16_100000_create_pingwin_supplier_document_lines` | linhas dos documentos de fornecedor |
| `2026_12_16_100001_add_detail_discount_adjustment_to_pingwin_supplier_documents` | colunas de descontos e acertos |
| `2026_12_17_100000_create_ocr_invoice_pingwin_links` | ligação das faturas do OCR aos documentos do PingWin |
| `2026_12_18_100000_f2b_ocr_line_articles` | **não aditiva** (ver acima); colunas dos artigos nas linhas, preço com 6 casas (preenchido a partir dos cêntimos), mapa fornecedor e código para artigo |
| `2026_12_19_100000_create_pingwin_document_writes` | lançamentos de documentos no PingWin |
| `2026_12_20_100000_create_permission_profiles` | perfis do ACL; colunas `users.profile_id`, `users.agency_profile_id` e `company_managements.guest_profile_id`, preenchidas com os perfis de compatibilidade ("como hoje") |
| `2026_12_21_100000_add_profile_suggestions` | sugestões de perfis (só leitura até alguém as usar) |
| `2026_12_22_100000_create_storage_migration_items` | registo da migração dos ficheiros para o R2 |
| `2026_12_23_100000_split_support_and_tasks_permissions` | **dados:** separa o suporte das tarefas nos perfis; nos perfis personalizados (ainda não existem em produção) apaga `suporte.editar` e `suporte.apagar`, que passam a `tarefas.*` |

## 4. Variáveis do `.env` de produção

Acrescentar **antes** do deploy (o `deploy.sh` faz o `config:cache`):

```
# OCR (obrigatórias: sem elas, o OCR das faturas falha com "Modelo de OCR não configurado")
OCR_MODEL_TEXT=gpt-4o-mini
OCR_MODEL_IMAGE=gpt-6.1-sol
OCR_REASONING_EFFORT=low

# R2 (Cloudflare): as credenciais podem entrar já, com os discos AINDA locais
R2_ACCESS_KEY_ID=<Access Key ID>
R2_SECRET_ACCESS_KEY=<Secret Access Key>
R2_BUCKET=xplendor-media
R2_ENDPOINT=https://<ID_DA_CONTA>.eu.r2.cloudflarestorage.com
R2_PUBLIC_ENDPOINT=
R2_REGION=auto
R2_PATH_STYLE=true
MEDIA_EXTERNAL_FETCH_TTL=3600
MEDIA_DISK=media
PRIVATE_FILES_DISK=local
```

- Os modelos do OCR são os de dev (`.env.example`); confirmar que são os que quer em produção. Opcionais: `OCR_MAX_PAGES`, `OCR_TEXT_MIN_CHARS`, `OCR_CHECK_TOLERANCE_CENTS`, `OCR_PRICES`, `OCR_RETRY_REASONING_EFFORT`.
- **Não mudar** `MEDIA_DISK` nem `PRIVATE_FILES_DISK` neste deploy. A passagem ao R2 é à parte (secção 6.5 e `documents/r2/R2-PRODUCAO.md`).
- Se o R2 ainda não estiver criado na Cloudflare, as variáveis `R2_*` podem ficar para depois: com os discos locais, nada as usa.
- **Opcionais, sem acrescentar:** `PINGWIN_REPORT_ID_ITEM_SALES`, `PINGWIN_REPORT_ID_ANNUAL`, `PINGWIN_REPORT_ID_HOURLY_SALES` (vazias valem os IDs da Yuko, globais na cloud GrupoPIE).
- **A ver no `.env` atual:** se existir `REDIS_QUEUE_RETRY_AFTER` ou `DB_QUEUE_RETRY_AFTER`, tem de ser pelo menos `3700` (o valor por omissão passou a `3700`, acima do job mais longo, de 3600 segundos).
- `ACCESS_MODE` não tem efeito fora dos testes: o ACL bloqueia sempre em produção.

## 5. Scraper: reconstruir a imagem

O `scraper/requirements.txt` tem um pacote novo (`zxing-cpp`, o QR da AT nas faturas). O `up -d --build` do `deploy.sh` reconstrói a imagem, porque o ficheiro mudou. Para confirmar, ou para forçar se a importação falhar:

```
docker exec xplendor-scraper python -c "import zxingcpp, pdf2image; print('ok')"
# só se falhar:
docker compose -f docker-compose.prod.yml build --no-cache scraper
docker compose -f docker-compose.prod.yml up -d scraper
```

## 6. Depois do deploy, por esta ordem

Todos a partir de `/home/xplendor`. Os que têm simulação correm primeiro sem `--execute` e só mostram números.

**6.1 Migrações e perfis**
```
docker exec xplendor-php php artisan migrate:status | grep 2026_12_   # as 25 em "Ran"
docker exec xplendor-php php artisan acl:migrate-profiles             # deve dizer 0 (a migração já os atribuiu)
docker exec xplendor-php composer audit --no-dev                      # sem avisos
```

**6.2 Interruptor do PingWin para o marketing desligado em todas as empresas** (deve dar 0, salvo a Yuko se já o ligou como previsto em `7d5b822`):
```
docker exec xplendor-php php artisan tinker --execute='echo App\Models\Company::where("pingwin_item_sales_enabled", true)->count(), PHP_EOL;'
```

**6.3 Faturas dos tickets e fotografias dos relatórios para o disco privado** (até correr, continuam públicas como hoje):
```
docker exec xplendor-php php artisan files:make-private              # simulação
docker exec xplendor-php php artisan files:make-private --execute
```
Copia cada ficheiro, confirma os bytes, atualiza a referência e só então apaga a cópia pública. Pode repetir-se ("Falhas" a 0). Confirmar no ecrã: abrir a fatura de um ticket pago e as fotografias de um relatório de satisfação.

**6.4 EXIF e GPS das fotografias já gravadas** (originais das viaturas e avatares):
```
docker exec xplendor-php php artisan images:strip-exif               # simulação: quantas têm EXIF e GPS
docker exec xplendor-php php artisan images:strip-exif --execute
```
As novas já são gravadas sem EXIF.

**6.5 R2 (mais tarde, quando o bucket estiver criado):** os passos 3 a 6 de `documents/r2/R2-PRODUCAO.md`: testar a ligação, `storage:migrate-to-r2` (simulação e depois `--execute`), mudar os discos, `--execute` outra vez e, uns dias depois, `storage:purge-local`. Agora inclui também as faturas dos tickets e as fotografias dos relatórios (tipos `fatura_ticket` e `foto_relatorio`), por isso o 6.3 deve correr antes.

**6.6 Finanças (opcional)**
- Na primeira noite correm sozinhos, **só leitura**, nas empresas com a integração PingWin: conta corrente dos fornecedores (06:30), documentos dos últimos 7 dias (07:00), linhas dos documentos (07:15), ligação das faturas do OCR (07:45) e mapa dos artigos (08:00). São pedidos reais ao PingWin, em lotes, depois da sincronização das 05:00.
- Para ter o histórico antes dessa noite, à mão: `pingwin:sync-supplier-cc {empresa}`, `pingwin:sync-supplier-documents {empresa} --from=AAAA-MM-DD`, `pingwin:sync-supplier-document-lines {empresa}`, `ocr:bootstrap-article-map {empresa}`.
- As escritas no PingWin (fornecedores, código do fornecedor no artigo, lançamento de documentos) só acontecem quando alguém as pede no ecrã.
- Confirmar `docker exec xplendor-php php artisan schedule:list`: os cinco agendamentos novos e os de antes.

## 7. Verificações do ACL: cada tipo de utilizador real

**Escolher as pessoas** (só leitura; mostra o id, a empresa e o perfil, sem dados pessoais):
```
docker exec xplendor-php php artisan tinker --execute='
$ag = App\Models\Company::whereNotNull("agency_enabled_at")->pluck("id");
$b = App\Models\User::where("role", "!=", "root");
foreach (["administrador" => (clone $b)->where("role", "admin")->whereNotIn("company_id", $ag),
          "utilizador" => (clone $b)->where("role", "user")->where("can_approve_content", false)->whereNotIn("company_id", $ag),
          "aprovador" => (clone $b)->where("role", "user")->where("can_approve_content", true),
          "agencia" => (clone $b)->whereIn("company_id", $ag)] as $t => $q) {
  echo str_pad($t, 14), (clone $q)->count(), ": ", (clone $q)->limit(3)->get()->map(fn ($u) => "{$u->id}/{$u->company_id}/" . ($u->profile?->name ?? "-"))->implode(", "), PHP_EOL;
}
echo "sem perfil (0): ", App\Models\User::where("role", "!=", "root")->whereNull("profile_id")->count(), PHP_EOL;'
```

**Entrar como cada um:** root › Empresas › a empresa › Utilizadores › **Entrar como**. Durante a sessão "como", as credenciais das integrações estão bloqueadas (como antes): confirmar só que os botões aparecem ou não. **Não aceitar, aprovar nem gravar nada** em nome do cliente: basta abrir as páginas e ver os botões.

| Quem | Confirmar que continua a fazer | Muda (esperado) |
|---|---|---|
| **Administrador** de um cliente | o menu tem as mesmas entradas; Empresa (editar), Utilizadores (lista e convites), Integrações (ver e os botões de ligar), Marca, Linha Editorial (aprovar), Blog, Bússola, Resultados, Finanças e Restauração se tiver os módulos, Automóvel se for stand, Faturação XPLENDOR (cobranças e orçamentos, com o botão de aceitar), Suporte (botão flutuante, pedidos e orçamentos) | ganha o separador **Utilizadores › Perfis** e o teto da agência no cartão da agência gestora (se for gerido) |
| **Utilizador** de um cliente | as mesmas páginas que abria antes; nenhuma página que abria mostra "Sem permissão"; o botão flutuante do suporte e os pedidos de suporte | **deixa de ligar, alterar ou desligar** o PingWin, o CoverManager, o GA4 e a Carmine (vê a integração, sem os botões) |
| **Aprovador** | o mesmo que o utilizador, mais aprovar e devolver na Linha Editorial | **ganha** aprovar e devolver artigos do blog |
| **Agência** (um administrador e um membro da agência) | a área da agência e a lista dos clientes geridos; entrar num cliente e fazer o que fazia (Linha Editorial, Bússola, resultados); no cliente, o cartão da agência gestora mostra "Agência convidada (como hoje)" | nada |
| **Root** (sem "Entrar como") | na XPLENDOR, tudo, incluindo aprovar os artigos do blog; noutra empresa, gerir utilizadores e acessos | noutra empresa, aceitar um orçamento ou aprovar um artigo responde "Esta decisão é do cliente" |

**Recusas inesperadas:** o nginx regista os pedidos à API. Depois das verificações, e na manhã seguinte:
```
docker logs --since 2h xplendor-nginx 2>&1 | grep '/api/' | grep '" 403 '
```
Cada 403 traz no corpo o motivo (`reason`: `perfil`, `modulo`, `decisao_do_cliente`, `impersonacao`…). Um 403 numa página que a pessoa usava antes é para ver de imediato; o perfil de uma pessoa muda-se em Utilizadores › Perfis, sem deploy.

## 8. Ainda antes do deploy (decisões do lado do produto)

- **Módulos:** as empresas sem "Suporte / Tarefas" deixam de ver as tarefas; as sem "Análise de Marketing" deixam de ver a página da Meta / Anúncios. Se alguma delas usa uma destas páginas, ligar o módulo antes do deploy (no `/admin`, na empresa).
- **Media Tailors na Yuko (D14):** não foi configurada; continua como "Agência convidada (como hoje)".
- **Modelos do OCR:** confirmar os valores de `OCR_MODEL_TEXT` e `OCR_MODEL_IMAGE` (secção 4).
