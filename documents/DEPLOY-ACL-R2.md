# Deploy: Finanças, PingWin, ACL, R2 e pré-deploy (lista única, pela ordem do dia)

Escrita a 10 de outubro de 2026. Substitui a lista consolidada anterior. Nada disto foi feito em produção. Cobre tudo o que está no `main` desde o último deploy conhecido (`e6c0596`, 7 de outubro): 45 commits, de `837f4c6` ao commit deste documento.

**Quem perde o quê, numa linha:** os utilizadores comuns dos clientes (e os aprovadores) deixam de ligar, alterar ou desligar o PingWin, o CoverManager, o GA4 e a Carmine, e deixam de ver e aceitar os orçamentos e as cobranças da XPLENDOR (abrem os pedidos de suporte e conversam, sem o valor); a agência gestora deixa de ver a lista dos orçamentos; o root deixa de tomar as decisões do cliente e de ver os orçamentos e as cobranças nas **outras** empresas (trata-os no `/admin`), mas na XPLENDOR continua a poder tudo; as empresas sem o módulo "Suporte / Tarefas" perdem só as tarefas; as empresas sem a "Análise de Marketing" perdem a página da Meta / Anúncios; as empresas do PingWin sem uma secção deixam de lhe chegar também pela API; quem não vê as Finanças vê a Bússola sem os valores em euros (hoje ninguém: os perfis "como hoje" mantêm as Finanças). Mais ninguém perde nada.

Em todos os passos, os comandos correm a partir de `/home/xplendor`.

---

## Antes do dia

- **Push do `main`:** o `origin/main` está em `21f993c`; faltam 22 commits.
- **Módulos:** se alguma empresa sem "Suporte / Tarefas" usa as tarefas, ou sem "Análise de Marketing" usa a página da Meta, ligar o módulo no `/admin` antes do deploy.
- **Cloudflare (para o passo d):** bucket `xplendor-media` privado (jurisdição da UE recomendada) e uma chave "Object Read & Write" só para esse bucket. Detalhe em `documents/r2/R2-PRODUCAO.md`, secção 2. O dia pode fazer-se até à PARAGEM 1 sem o R2.

---

## a) Cópia da base de dados

**Fazer** (antes de qualquer outra coisa; a palavra-passe vem do próprio contentor, não fica no histórico da shell):
```
cd /home/xplendor
mkdir -p /home/backups
docker exec xplendor-db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases "$MYSQL_DATABASE"' \
  | gzip > /home/backups/xplendor-antes-acl-r2-$(date +%F-%H%M).sql.gz
git log -1 --oneline > /home/backups/commit-antes-acl-r2.txt
```

**Confirmar:**
```
ls -lh /home/backups/xplendor-antes-acl-r2-*.sql.gz      # tamanho razoável (não 0)
gunzip -t /home/backups/xplendor-antes-acl-r2-*.sql.gz && echo íntegro
zcat /home/backups/xplendor-antes-acl-r2-*.sql.gz | tail -1  # "-- Dump completed on …"
cat /home/backups/commit-antes-acl-r2.txt                  # deve ser e6c0596
```
Se o commit não for `e6c0596`, entram também os commits entre esse e `e6c0596` (as migrações deles correm igualmente).

**Voltar atrás:** nada a desfazer (é só uma cópia). Guardar o ficheiro até ao fim do passo g.

---

## b) Deploy

### b1. O pull à mão (só neste primeiro deploy)

O servidor ainda tem o `deploy.sh` antigo (faz o `down` antes do pull e não corre o Composer) e o `docker/scraper/scraper.log` saiu do git com alterações no servidor, o que faria o `git pull` recusar-se.

```
cd /home/xplendor
cp docker/scraper/scraper.log /home/scraper.log.antes-do-deploy
git checkout -- docker/scraper/scraper.log
git pull --ff-only origin main
cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log
git status --short              # não deve aparecer nada
```
Se o `git pull` falhar, nada foi desligado: devolver o registo (`cp /home/scraper.log.antes-do-deploy docker/scraper/scraper.log`) e ver o motivo.

### b2. Variáveis do `.env` (antes de correr o script, que faz o `config:cache`)

```
# OCR (obrigatórias: sem elas, ler uma fatura falha com "Modelo de OCR não configurado")
OCR_MODEL_TEXT=gpt-4o-mini
OCR_MODEL_IMAGE=gpt-6.1-sol
OCR_REASONING_EFFORT=low

# R2: as credenciais podem entrar já; os discos FICAM LOCAIS até ao passo d
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
- Confirmar os modelos do OCR (são os de dev).
- Se já existir `REDIS_QUEUE_RETRY_AFTER` ou `DB_QUEUE_RETRY_AFTER`, tem de ser pelo menos `3700`.
- Sem o bucket criado, as variáveis `R2_*` podem ficar para o passo d (com os discos locais, nada as usa).
- Opcionais, sem acrescentar: `PINGWIN_REPORT_ID_ITEM_SALES`, `PINGWIN_REPORT_ID_ANNUAL`, `PINGWIN_REPORT_ID_HOURLY_SALES`.

### b3. O script

```
bash deploy-scripts/deploy.sh      # ou como é corrido habitualmente
```
Faz: pull (já não traz nada), `down`, `up -d --build` (reconstrói a imagem do scraper, porque o `requirements.txt` mudou), `composer install --no-dev --optimize-autoloader`, migrações, `storage:link`, caches, `queue:restart`, compilação do `web` e do `site`, reinício do nginx.

**Migrações (27, pela ordem).** Uma só **não aditiva**: `2026_12_18_100000_f2b_ocr_line_articles` alarga `ocr_invoice_lines.quantity` de `decimal(12,3)` para `decimal(18,6)` (sem perda de dados, tabela pequena). Mexem em dados, sem apagar nada que exista hoje em produção:
- `2026_12_11_100000` preenche a coluna nova `settled`;
- `2026_12_13_100000` liga o módulo `restauracao_conta_corrente` às empresas com o módulo `pingwin` (só acrescenta);
- `2026_12_20_100000` cria os perfis e dá a cada utilizador o perfil "como hoje";
- `2026_12_23_100000` separa o suporte das tarefas nos perfis;
- `2026_12_24_100000` tira a faturação da XPLENDOR de todos os perfis exceto o Administrador (os perfis só existem desde a 2026_12_20, no mesmo deploy).

As outras 21 só criam tabelas ou colunas: PingWin para o marketing (`2026_12_04` a `2026_12_10`), Finanças e OCR (`2026_12_11_100001` a `2026_12_19`), sugestões de perfis (`2026_12_21`), registo da migração para o R2 (`2026_12_22`) e tamanhos dos ficheiros (`2026_12_25`).

### b4. Logo a seguir ao script

```
docker exec xplendor-php php artisan migrate:status | grep -c "2026_12_.*Ran"     # 27
docker exec xplendor-php php artisan acl:migrate-profiles                         # 0 (a migração já os atribuiu)
docker exec xplendor-php composer audit --no-dev                                  # sem avisos
docker exec xplendor-scraper python -c "import zxingcpp, pdf2image; print('ok')"  # ok
docker exec xplendor-php php artisan schedule:list | grep -E "pingwin-supplier|ocr-"   # 5 agendamentos novos
```
Se o `import` do scraper falhar: `docker compose -f docker-compose.prod.yml build --no-cache scraper && docker compose -f docker-compose.prod.yml up -d scraper`.

### b5. Ficheiros expostos (correm já, com os discos locais)

```
docker exec xplendor-php php artisan files:make-private              # simulação: quantas faturas e fotografias
docker exec xplendor-php php artisan files:make-private --execute    # copia, confirma os bytes, atualiza a referência, apaga a cópia pública
docker exec xplendor-php php artisan images:strip-exif               # simulação: quantas fotografias têm EXIF e GPS
docker exec xplendor-php php artisan images:strip-exif --execute
```
Confirmar: "Falhas" a 0 nos dois (podem repetir-se). Até ao `files:make-private --execute`, as faturas dos tickets e as fotografias dos relatórios continuam públicas como hoje.

### b6. Interruptor da Yuko (decidido: ligado no deploy)

Os passos 3.1 a) a d) do guia do PingWin (no histórico do git: `git show 7d5b822:documents/PINGWIN-F1-SESSAO-PRODUCAO.md`): confirmar o ID e as lojas, ativar a Linha Editorial e `pingwin:item-sales-switch <ID> on`. Ligar não lê nada; o job das 05:00 faz a primeira leitura nessa noite (14 pedidos ao PingWin, 15 ao domingo).

Na primeira noite correm também, só leitura, nas empresas com a integração PingWin: conta corrente dos fornecedores (06:30), documentos dos últimos 7 dias (07:00), linhas dos documentos (07:15), ligação das faturas do OCR (07:45) e mapa dos artigos (08:00). As escritas no PingWin (fornecedores, código no artigo, lançamento de documentos) só acontecem quando alguém as pede no ecrã.

**Voltar atrás (passo b inteiro, só logo a seguir; depois perdem-se os dados escritos entretanto):**
```
cd /home/xplendor
git reset --hard $(cut -d' ' -f1 /home/backups/commit-antes-acl-r2.txt)
zcat /home/backups/xplendor-antes-acl-r2-*.sql.gz | docker exec -i xplendor-db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
docker compose -f docker-compose.prod.yml up -d --build
docker exec xplendor-php composer install --no-dev --optimize-autoloader --no-interaction
docker exec xplendor-php php artisan config:cache && docker exec xplendor-php php artisan route:cache && docker exec xplendor-php php artisan queue:restart
(cd web && yarn install && rm -rf build && yarn build) && (cd site && npm install && rm -rf out && npm run build)
docker restart xplendor-nginx
```
- O `.env` pode ficar com as variáveis novas (o código antigo ignora-as).
- Os ficheiros que o `files:make-private` já moveu ficam no disco privado e o código antigo não os encontra. Se for preciso voltar atrás depois do b5, devolvê-los antes do `git reset`: para cada fatura e fotografia movida, copiar de `storage/app/private/support-invoices/…` e `…/satisfaction-reports/…` para o caminho público antigo (a cópia da base de dados repõe as referências antigas).
- Só o interruptor (sem voltar atrás no resto): `pingwin:item-sales-switch <ID> off`.

---

## c) PARAGEM 1: verificações no ecrã

Não avançar para o R2 sem isto bater. **Não aceitar, aprovar nem gravar nada em nome do cliente**: abrir as páginas e ver os botões.

**Escolher as pessoas** (só leitura; id, empresa e perfil, sem dados pessoais):
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

**Entrar como cada um:** root › Empresas › a empresa › Utilizadores › **Entrar como**. Na sessão "como", as credenciais das integrações estão bloqueadas (como antes): ver só se os botões aparecem.

| Quem | Confirmar | Muda (esperado) |
|---|---|---|
| **Administrador** de um cliente | o menu com as mesmas entradas; Empresa, Utilizadores (lista e convites), Integrações (os botões de ligar), Marca, Linha Editorial (aprovar), Blog, Bússola, Finanças e Restauração com os módulos, Automóvel nos stands; **Orçamentos** no menu e, num pedido de suporte com orçamento, o valor e "Aprovar"; o cartão das cobranças por pagar no dashboard | ganha Utilizadores › Perfis; no Novo perfil, a "Faturação da XPLENDOR" aparece bloqueada com "Só o Administrador" |
| **Utilizador** de um cliente | as mesmas páginas que abria; nenhuma mostra "Sem permissão"; o botão flutuante e os pedidos de suporte; num pedido com orçamento, o estado e a frase "O valor e a aprovação do orçamento são do Administrador da empresa" | deixa de ligar integrações (vê-as sem os botões); **sem Orçamentos** no menu; sem o cartão das cobranças; nas Despesas, a despesa da XPLENDOR aparece sem a fatura nem o "Já paguei" |
| **Aprovador** | o mesmo que o utilizador, mais aprovar e devolver na Linha Editorial | ganha aprovar e devolver artigos do blog; perde a faturação como o utilizador |
| **Agência** (um administrador e um membro) | a área da agência e os clientes geridos; num cliente, o que fazia (Linha Editorial, Bússola, resultados); o cartão da agência gestora mostra "Agência convidada (como hoje)" | sem a lista dos orçamentos do cliente |
| **Root na própria empresa** (sem "Entrar como") | tudo, incluindo aprovar os artigos do blog da XPLENDOR e as cobranças e orçamentos da XPLENDOR | nada |
| **Root noutra empresa** | gerir utilizadores e acessos | aceitar um orçamento ou aprovar um artigo: "Esta decisão é do cliente"; as cobranças e os orçamentos do cliente: "Só o Administrador da empresa…" (no `/admin` continua tudo) |

**A Bússola de um utilizador sem Finanças.** Hoje nenhum utilizador real está nesse caso (os perfis "como hoje" mantêm as Finanças), por isso usa-se uma pessoa temporária na Yuko (`<ID>`), que nunca recebe email (é criada diretamente):
```
docker exec xplendor-php php artisan tinker --execute='$p = App\Models\PermissionProfile::create(["company_id" => <ID>, "side" => "cliente", "name" => "Verificação sem Finanças", "is_system" => false, "is_suggestion" => false]); $p->syncPermissions(["bussola.ver", "restauracao.ver", "editorial.ver", "suporte.ver", "suporte.criar"]); $u = new App\Models\User(["name" => "Verificação sem Finanças", "email" => "verificacao-sem-financas@xplendor.invalid", "password" => bcrypt(Illuminate\Support\Str::random(40)), "role" => "user"]); $u->forceFill(["company_id" => <ID>, "profile_id" => $p->id])->save(); echo $u->id, PHP_EOL;'
```
Entrar como essa pessoa e abrir Marketing › Bússola: os números em euros desfocados (faturação, valores dos artigos, a grelha dos turnos, as barras dos dias para encher), a frase "Sem acesso aos valores financeiros" e, à vista, as jogadas, as unidades, os mais vendidos e as percentagens. Sair e confirmar que o Administrador vê os euros. **No fim, apagar a pessoa temporária:**
```
docker exec xplendor-php php artisan tinker --execute='$u = App\Models\User::where("email", "verificacao-sem-financas@xplendor.invalid")->first(); $p = $u?->profile_id; $u?->tokens()->delete(); $u?->forceDelete(); App\Models\PermissionProfileEvent::where("profile_id", $p)->delete(); App\Models\PermissionProfile::whereKey($p)->delete(); echo "removido", PHP_EOL;'
```
(Nas primeiras semanas, antes do histórico do passo f, a Bússola da Yuko ainda é parcial.)

**Recusas inesperadas** (o nginx regista os pedidos à API):
```
docker logs --since 2h xplendor-nginx 2>&1 | grep '/api/' | grep '" 403 '
```
Cada 403 traz o motivo (`perfil`, `modulo`, `decisao_do_cliente`, `so_administrador`, `impersonacao`…).

**Voltar atrás:** uma pessoa com um acesso a menos muda-se de perfil em Utilizadores › Perfis, sem deploy. Uma falha geral: o "Voltar atrás" do passo b.

---

## d) R2: ligação, migração, mudança dos discos e tamanhos

Detalhe e notas em `documents/r2/R2-PRODUCAO.md`, secção 4.

1. **Ligação** (as variáveis `R2_*` no `.env` e `config:cache`):
   ```
   docker exec xplendor-php php artisan config:cache
   docker exec xplendor-php php artisan tinker --execute='Storage::disk("r2")->put("teste/ligacao.txt","ok"); echo Storage::disk("r2")->get("teste/ligacao.txt"), PHP_EOL; Storage::disk("r2")->delete("teste/ligacao.txt");'
   ```
   Confirmar: escreve `ok`.
2. **Migração** (simulação; depois a sério, com confirmação do tamanho e do SHA-256 de cada ficheiro, retomável):
   ```
   docker exec xplendor-php php artisan storage:migrate-to-r2
   docker exec xplendor-php php artisan storage:migrate-to-r2 --execute
   ```
   Repetir até "Falhas" a 0. Inclui as faturas dos tickets e as fotografias dos relatórios (tipos `fatura_ticket` e `foto_relatorio`), já no disco privado desde o b5. Por partes: `--only=media`, `--only=cobranca,ocr`, `--only=fatura_ticket,foto_relatorio`, `--limit=500`.
3. **Mudar os discos** no `.env` para `MEDIA_DISK=r2` e `PRIVATE_FILES_DISK=r2`, e depois:
   ```
   docker exec xplendor-php php artisan config:cache
   docker exec xplendor-php php artisan queue:restart
   ```
4. **Repetir a migração** (os ficheiros escritos entre o 2 e o 3; o que já foi escrito no R2 conta como "já estava"):
   ```
   docker exec xplendor-php php artisan storage:migrate-to-r2 --execute
   ```
5. **Tamanhos dos ficheiros existentes** (espaço por empresa):
   ```
   docker exec xplendor-php php artisan storage:fill-sizes             # simulação: quantos faltam e quantos MB
   docker exec xplendor-php php artisan storage:fill-sizes --execute
   ```
   Confirmar: "Em falta no disco" a 0 (um ficheiro em falta fica com 0) e, a seguir, a simulação diz "Nada a preencher".

**Voltar atrás** (antes do passo g; as cópias locais continuam lá):
```
# no .env: MEDIA_DISK=media e PRIVATE_FILES_DISK=local
docker exec xplendor-php php artisan config:cache
docker exec xplendor-php php artisan queue:restart
docker exec xplendor-php php artisan tinker --execute='App\Models\MediaAsset::where("disk","r2")->update(["disk"=>"media"]);'
```
Os ficheiros enviados já com o R2 ficam só no R2: copiá-los para o local antes de voltar atrás. Os tamanhos do passo 5 não precisam de ser desfeitos.

---

## e) PARAGEM 2: verificações no ecrã

| O quê | Como confirmar |
|---|---|
| Imagem | uma publicação com imagem na Linha Editorial abre a miniatura e a pré-visualização |
| Vídeo | um vídeo abre e **avança** (arrastar para o meio) |
| Envio novo | enviar uma imagem nova na Linha Editorial; ficar "pronta" com miniatura |
| Fatura do OCR | abrir a imagem ou o PDF de uma fatura do OCR |
| PDF de cobrança | como Administrador do cliente, abrir a fatura de uma cobrança; no `/admin` › Cobranças, a fatura e o comprovativo |
| Fatura de um pedido de suporte | como Administrador, num pedido pago, "Ver fatura" |
| Fotografia de um relatório de satisfação | abrir um relatório com fotografias (no ecrã da empresa e no link público) |
| Espaço por empresa | `/admin` › Espaço por empresa: os totais por tipo e por empresa, em MB ou GB, e "Ficheiros sem tamanho" a 0 |

**Voltar atrás:** se algum ficheiro não abrir, o "Voltar atrás" do passo d.

---

## f) Os dois backfills do PingWin (fora do horário de serviço da Yuko)

Na manhã a seguir ao deploy, antes das 11h (começar até às 10h00), depois de confirmar que o job das 05:00 acabou sem erros (passo 3.2 a) e b) do guia do PingWin). Só leituras, em série, um comando de cada vez, num contentor à parte do worker:
```
cd /home/xplendor
art() { docker compose -f docker-compose.prod.yml run --rm --no-deps worker php artisan "$@"; }
art pingwin:item-history <ID> --calls=40      # 1.º: vendas por artigo (cerca de 21 pedidos, 12 a 15 minutos)
art pingwin:hourly-history <ID> --calls=40    # 2.º, só depois do 1.º: vendas por hora (cerca de 30 pedidos, 15 a 20 minutos)
```
**Confirmar:** "Histórico completo." e "Histórico por hora completo.", com as duas lojas "completo". Depois (opcional, sem pedidos ao PingWin): `art covermanager:history <ID> --days=90` e o recálculo dos sinais do passo 3.2 c) 4 do guia. No ecrã: o cartão "Dados para o marketing" com as lojas "Completo" e a Bússola com o topo, as jogadas e os blocos.

**Voltar atrás:** um comando interrompe-se com Ctrl+C e continua de onde parou. Se algo não bater: `art pingwin:item-sales-switch <ID> off` (nada é apagado; ao voltar a ligar, o histórico continua).

---

## g) Uns dias depois: apagar as cópias locais

Só com tudo a funcionar há alguns dias no R2 (passos d e e confirmados).

**Fazer:**
```
tar czf /home/backups/storage-local-antes-purge-$(date +%F).tgz -C /home/xplendor/server storage/app/media storage/app/private
docker exec xplendor-php php artisan storage:purge-local             # simulação: quantos ficheiros e MB a libertar
docker exec xplendor-php php artisan storage:purge-local --execute
```
Só apaga o que já é lido do R2 (verificado, com o mesmo tamanho no destino, e com o disco em uso).

**Confirmar:** a simulação seguinte diz 0 a apagar; repetir as verificações da PARAGEM 2.

**Voltar atrás:** depois deste passo, o "Voltar atrás" do passo d precisa das cópias locais: repor o arquivo (`tar xzf /home/backups/storage-local-antes-purge-….tgz -C /home/xplendor/server`) antes de mudar os discos para local. Sem o arquivo, os ficheiros só existem no R2.
