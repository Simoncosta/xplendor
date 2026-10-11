# Deploy do dia: Ficheiros e Bússola do stand

Escrita a 11 de outubro de 2026. Nada disto foi feito em produção.

Um só documento para o dia. Junta, por ordem:
- **a lista dos Ficheiros** (`documents/ficheiros/DEPLOY-FICHEIROS.md`, que continua válida e tem mais detalhe);
- **a lista da Bússola do stand.**

Cada passo diz o que fazer, o que confirmar e como voltar atrás.

## Base desta lista

- **Antes de tudo:** a lista `documents/DEPLOY-ACL-R2.md` (o `main` até `69645d5`). Se ainda não foi feita, faz-se primeiro, no mesmo dia, com a correção do passo b2 dessa lista: os modelos do OCR são os do passo 2 desta.
- **Código desta lista:** do `facd5a6` ao commit deste documento.
  - **Ficheiros e OCR:** `facd5a6` a `f032f74`, com o OCR 1 a 4, a noite dos Ficheiros e o pré-deploy dos Ficheiros.
  - **Bússola do stand:** `4b910f1` a `87e7b9f` (a noite), e os ajustes antes do deploy (`2af02ed`, `55ea032`, `fff19f6`, `5bd826b`, `ca7b738` e este documento).
  - **`f869922`, "noturno bussola do stand":** o trabalho F2d de outra pessoa, no OCR da restauração. Não é da Bússola, apesar do nome. Ver o passo 3.
- **Um só deploy:** o `deploy.sh` corre uma vez e leva tudo. Os passos depois do deploy separam os Ficheiros (passos 4 a 8) da Bússola (passos 9 a 13).

**Quem ganha o quê, numa linha:**
- **Ninguém perde acesso a nada.** Os Ficheiros e a Bússola do stand nascem desligados em todas as empresas.
- **Muda logo para todos, com o deploy:**
  - o OCR das faturas lê só na Anthropic;
  - o cabeçalho mostra o perfil da pessoa, e o leitor de PDF o nome do ficheiro;
  - há dois ramos novos ("Software (SaaS)" e "Agência de comunicação");
  - "Veículos" em vez de "Carros" nos textos;
  - a recomendação "Gasto sem contactos" (antes "gasto sem leads");
  - os acentos nas mensagens do ramo automóvel;
  - o editor de perfis deixa de avisar "Bússola: módulo inativo" numa empresa só de stand;
  - o trabalho F2d no OCR da restauração.

Em todos os passos, os comandos correm a partir de `/home/xplendor`.

---

## Antes do dia

- **Push do `main`:** sem push feito; o `origin/main` não tem estes commits.
- **Chave da Anthropic:** o OCR e as frases da Bússola usam-na. Os valores não se mostram:
  ```
  grep -c '^ANTHROPIC_API_KEY=.\+' /home/xplendor/server/.env      # 1
  ```
- **O trabalho F2d (`f869922`):** confirmar com quem o fez que pode ir neste deploy. Escreve no PingWin quando alguém usa as ações novas (passo 3).

---

## Passo 1. Cópia da base de dados

```
mkdir -p /home/backups
docker exec xplendor-db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases "$MYSQL_DATABASE"' \
  | gzip > /home/backups/xplendor-antes-ficheiros-bussola-$(date +%F-%H%M).sql.gz
git log -1 --oneline > /home/backups/commit-antes-ficheiros-bussola.txt
cp server/.env /home/backups/env-antes-ficheiros-bussola
```

- **Confirmar:**
  - `ls -lh /home/backups/ | tail -3`: os três ficheiros existem e a cópia não está vazia.
  - `commit-antes-ficheiros-bussola.txt` tem o commit que está em produção.
- **Voltar atrás:** nada a desfazer.

---

## Passo 2. Variáveis do `.env` (antes do script, que faz o `config:cache`)

```
OCR_MODEL_TEXT=claude-opus-5-5
OCR_MODEL_IMAGE=claude-opus-5-5
OCR_REASONING_EFFORT=medium
```

- **Substituir os valores antigos** (`gpt-4o-mini`, `gpt-6.1-sol`, `low`), se existirem. Mesmo com um modelo da OpenAI no `.env`, o código usa o `claude-opus-5-5`.
- **Não acrescentar** `SCHEDULE_EXTERNAL_JOBS`: em produção, as tarefas com chamadas externas ficam ligadas por omissão.
- **Ficheiros e Bússola:** sem variáveis novas.
- **`OPENAI_KEY`:** fica por agora (ver o passo 7).
- **Filas:** se existir `REDIS_QUEUE_RETRY_AFTER`, tem de ser pelo menos `3700`.
- **Confirmar:** `grep -E '^OCR_(MODEL_TEXT|MODEL_IMAGE|REASONING_EFFORT)=' server/.env` mostra as três linhas acima.
- **Voltar atrás:** `cp /home/backups/env-antes-ficheiros-bussola server/.env`, e depois `docker exec xplendor-php php artisan config:cache`.

---

## Passo 3. O deploy

```
bash deploy-scripts/deploy.sh
```

O script faz, por esta ordem:
- o pull, o `down` e o `up -d --build`;
- o `composer install --no-dev`;
- as migrações;
- as caches e o `queue:restart`;
- a compilação do `web` (com `yarn install`) e do `site`;
- o reinício do nginx.

O código do scraper está montado no contentor, por isso o pull também o atualiza.

**Migrações (11), todas aditivas.** Pela ordem em que correm:

| Migração | Origem | O que faz |
|---|---|---|
| `2026_12_20_100000_ocr_invoices_soft_delete_and_hash` | OCR | Apagar faturas do OCR (com reposição) e o hash do ficheiro. Calcula o hash das faturas que já existem (só lê os ficheiros). |
| `2026_12_26_100000_marketing_suggestion_without_restaurant_sales` | OCR 1 | A sugestão "Marketing" deixa os ecrãs de vendas da restauração. Os perfis das empresas ficam. |
| `2026_12_27_100000_add_ai_provider_to_ocr_invoices` | OCR | O fornecedor, o esforço e o tempo de cada leitura. |
| `2026_12_28_100000_create_files_catalog` | Ficheiros | As tabelas dos Ficheiros e as colunas novas em `media_assets` e `media_uploads`. |
| `2026_12_28_100000_f2d_ocr_line_vat_write` | F2d (`f869922`) | A coluna `ocr_invoice_lines.vat_write_id`: a correção do IVA do artigo pedida por uma linha da fatura. |
| `2026_12_28_100001_files_permissions` | Ficheiros | A área "ficheiros" nos perfis de sistema e nas sugestões. |
| `2026_12_29_100000_ocr_anthropic_only` | OCR | Uma escolha gravada nas funções do OCR passa a `claude-opus-5-5`, médio. Sem escolha gravada, não grava nada. |
| `2026_12_29_100001_files_agency_edit` | Ficheiros | A agência passa a mudar o nome e mover nos Ficheiros, sem apagar. |
| `2026_12_29_100002_add_saas_and_agency_sectors` | Ficheiros | Os ramos "Software (SaaS)" e "Agência de comunicação". |
| `2026_12_30_100000_create_compass_stand` | Bússola | A coluna `companies.bussola_stand_enabled` (desligada) e as tabelas `compass_signals`, `compass_signal_actions`, `compass_excluded_subjects`, `compass_texts`, `compass_post_subjects` e `compass_runs`. |
| `2026_12_31_100000_add_source_to_compass_post_subjects` | Bússola (ajuste 3) | A coluna `compass_post_subjects.source` ("bussola" ou "ficheiros"): de onde vem a ligação entre a publicação e o veículo. |

Nenhuma migração apaga, move ou copia ficheiros, nem altera dados que existam, com duas exceções: as migrações de perfis voltam a gravar os perfis de sistema e as sugestões (os perfis das empresas ficam), e a do OCR troca a escolha gravada das duas funções do OCR.

**Tarefas agendadas novas (4):**
- `ocr-purge-deleted-files`, às 03:50: apaga do disco o ficheiro das faturas do OCR apagadas há mais de 30 dias.
- `files-purge-recycle`, às 04:00: apaga de vez o que está há mais de 30 dias na reciclagem dos Ficheiros.
- `files-catalog-nightly`, às 04:40: regista os ficheiros novos dos módulos, só nas empresas com os Ficheiros ligados.
- `bussola-stand-nightly`, às 05:20: recalcula os sinais da Bússola do stand, só nas empresas com o interruptor ligado. É interna; as frases da IA vão a seguir, na fila.

**O que fica visível logo a seguir:**
- **Recomendações (todas as empresas com stock):** "Gasto sem leads" passa a "Gasto sem contactos".
  - Só contam os anúncios ativos.
  - Qualquer contacto nos mesmos 14 dias tira o veículo da lista (leads de qualquer origem, WhatsApp, chamada, telefone visto).
  - Antes e depois, com um exemplo: `documents/bussola-stand/AJUSTES-PRE-DEPLOY.md`, ponto 1.
- **"Veículos" em vez de "Carros"** nos textos: a lista está no relatório da manhã da Bússola (`documents/bussola-stand/RELATORIO-MANHA.md`, secção 3).
- **Acentos e ortografia** nas mensagens do ramo automóvel: `AJUSTES-PRE-DEPLOY.md`, ponto 6.
- **F2d (`f869922`), no OCR da restauração:** quatro rotas novas.
  - "Tornar principal no PingWin" e "Corrigir o IVA do artigo no PingWin": escrevem no PingWin real quando alguém as usa.
  - Os códigos do fornecedor de um artigo e o relatório "IVA a rever".
  - O scraper do PingWin passa a reler o grupo de IVA para confirmar a escrita.

**Confirmar:**
```
docker exec xplendor-php php artisan migrate:status | grep -E "ocr_invoices_soft_delete_and_hash|marketing_suggestion_without_restaurant_sales|add_ai_provider_to_ocr_invoices|create_files_catalog|f2d_ocr_line_vat_write|files_permissions|ocr_anthropic_only|files_agency_edit|add_saas_and_agency_sectors|create_compass_stand|add_source_to_compass_post_subjects" | grep -c Ran      # 11
docker exec xplendor-php php artisan schedule:list | grep -E "ocr-purge-deleted-files|files-purge-recycle|files-catalog-nightly|bussola-stand-nightly"   # 4 linhas
docker exec xplendor-php php artisan tinker --execute="echo json_encode([App\Services\Ai\AiFunctionSettings::for('ocr_text'), App\Services\Ai\AiFunctionSettings::for('ocr_image')]);"
# as duas: "provider":"anthropic","model":"claude-opus-5-5","effort":"medium"
docker exec xplendor-php php artisan tinker --execute="echo App\Models\Company::where('bussola_stand_enabled', true)->count();"   # 0
docker ps --format '{{.Names}} {{.Status}}' | grep -E "xplendor-(php|worker|scheduler|scraper)"   # todos "Up"
```
- O `/app` abre, e o menu mostra "Veículos".

**Voltar atrás:**
- As migrações só acrescentam, por isso o código anterior funciona com elas.
  ```
  git checkout <commit de /home/backups/commit-antes-ficheiros-bussola.txt>
  ```
- Depois, o `deploy.sh` sem o pull, ou os passos dele à mão: `up -d --build`, `composer install`, as caches, o `queue:restart` e o build do `web`.
- A base de dados só em último caso (passo 1): perde tudo o que foi gravado depois da cópia.

---

## Ficheiros

### Passo 4. Mudar o ramo da XPLENDOR para "Software (SaaS)"

No ecrã: Perfil da empresa da XPLENDOR › separador "Linha Editorial" › "Trocar de ramo" › "Software (SaaS)" › confirmar.

- **Atenção, a troca de ramo apaga:**
  - o estado dos meses do planeamento (voltam a fechados);
  - as datas escondidas da empresa.
- **Ficam:** as publicações e as datas próprias.
- **Confirmar:** a Linha Editorial da XPLENDOR mostra o ramo "Software (SaaS)". Em "Perfil da Marca" › "Sugerir perfil", a sugestão parte deste modelo (pedir a sugestão é opcional e usa a IA).
- **Voltar atrás:** trocar de ramo outra vez para o anterior. Volta a apagar o estado dos meses e as datas escondidas.

### Passo 5. Ligar os Ficheiros na XPLENDOR

No `/admin` › Empresas › XPLENDOR › Módulos › ligar "Ficheiros". As outras empresas ficam com o módulo desligado.

- **Confirmar:** o menu da XPLENDOR mostra "Ficheiros".
- **Voltar atrás:** desligar o módulo no mesmo sítio (efeito imediato, sem deploy).

### Passo 6. Registar os ficheiros que a XPLENDOR já tem

O número da empresa:
```
docker exec xplendor-php php artisan tinker --execute="echo App\Models\Company::where('fiscal_name','like','%XPLENDOR%')->get(['id','fiscal_name'])->toJson();"
```

Primeiro a simulação (não grava nada), depois a sério:
```
docker exec xplendor-php php artisan files:catalog --company=<ID>
docker exec xplendor-php php artisan files:catalog --company=<ID> --execute
```

- **Nada é copiado nem movido:** o catálogo só regista os ficheiros.
- **Corrigido nos ajustes da Bússola (ponto 3):** o registo já não apaga a ligação entre a imagem de um veículo e a sua media, depois de "Escolher dos Ficheiros". O "Em uso" mantém-se de uma noite para a outra.
- **Confirmar:**
  - a simulação mostra, por origem, o total, os novos e os registos sem ficheiro no disco;
  - depois da execução, uma segunda simulação mostra 0 novos;
  - `/app/ficheiros` mostra as pastas do sistema.
- **Voltar atrás:** desligar o módulo (passo 5). Os registos ficam, mas ninguém os vê.

### Passo 7. Confirmar "Modelos de IA"

Em `/app/admin/ai-models`:
- **OCR:** as duas funções em "Claude Opus 5.5", raciocínio médio.
- **Bússola:** a função `bussola_jogadas` (as frases das jogadas, da restauração e do stand) com um modelo da Anthropic.
- **As outras funções:** ver se alguma usa a OpenAI (só leitura):
  ```
  docker exec xplendor-php php artisan tinker --execute="echo DB::table('ai_function_settings')->where('provider','openai')->get(['function','model'])->toJson();"
  ```
  - **`[]`:** a `OPENAI_KEY` pode sair do `.env` (depois, `config:cache`).
  - **Com linhas:** a chave continua a fazer falta, ou trocam-se essas funções para a Anthropic no ecrã.
- **Voltar atrás:** escolher outro modelo da Anthropic no ecrã; o histórico mostra a escolha anterior.

### Passo 8. Verificações no ecrã dos Ficheiros

1. **Como root na XPLENDOR:**
   - "Os meus ficheiros", "Da plataforma" e "Reciclagem";
   - enviar um PDF de teste e abri-lo: o título do leitor é o nome do ficheiro;
   - apagá-lo e restaurá-lo na reciclagem.
2. **Linha Editorial:** numa publicação de teste, em "Conteúdo", aparece "Escolher dos Ficheiros". Escolher uma imagem: nos Ficheiros, passa a "Em uso em 1 publicação".
3. **Cabeçalho:** por baixo do nome, "Equipa XPLENDOR" (root) e o perfil numa conta de cliente.
4. **Escolha do ramo:** os dois ramos novos aparecem na troca de ramo.
5. **OCR, opcional:** ler uma fatura de teste numa empresa com a Restauração custa cerca de 0,11 USD; o fornecedor da leitura é a Anthropic.

- **Voltar atrás:** cada verificação só cria dados de teste; apagar o PDF e a publicação de teste.

---

## Bússola do stand

### Passo 9. Recomendações: "Gasto sem contactos"

Não precisa do interruptor: vale em todas as empresas com stock, logo depois do deploy.

- **Confirmar:** numa empresa de stand com anúncios Meta etiquetados, nas Recomendações (dashboard, separador Stock):
  - o título é "Gasto sem contactos";
  - só aparecem veículos com anúncios ativos e nenhum contacto nos 14 dias;
  - pode aparecer menos do que antes: é o efeito pedido (um veículo com WhatsApps ou chamadas sai da lista).
- **Voltar atrás:**
  - para todas as empresas, o código anterior (passo 3);
  - para uma empresa, desligar a regra com uma linha na tabela (não há ecrã para isto):
    ```
    docker exec xplendor-php php artisan tinker --execute="App\Models\CompanyRecommendationSetting::updateOrCreate(['company_id' => <ID>, 'rule_key' => 'automotive_spend_without_lead'], ['enabled' => false]);"
    ```
    Para voltar a ligar, o mesmo comando com `'enabled' => true`.

### Passo 10. Ligar a Bússola do stand numa empresa

Só o root, numa empresa com o módulo de stock (a escolher; o comando recusa uma empresa sem stock):
```
docker exec xplendor-php php artisan bussola:stand-switch <ID> on
```
O comando põe o primeiro cálculo na fila.

- **Confirmar** (um ou dois minutos depois):
  ```
  docker exec xplendor-php php artisan tinker --execute="echo json_encode([DB::table('compass_runs')->where(['provider' => 'stand', 'company_id' => <ID>])->value('computed_at'), DB::table('compass_signals')->where(['provider' => 'stand', 'company_id' => <ID>])->count()]);"
  # uma data de hoje e o número de sinais
  docker exec xplendor-php php artisan queue:failed | grep -i compass   # nada
  ```
- **Voltar atrás:** `bussola:stand-switch <ID> off` (efeito imediato). Os sinais calculados ficam na tabela e não aparecem.

### Passo 11. Verificações no ecrã da Bússola do stand

Na empresa do passo 10:
1. **A página** (Marketing › Bússola), como administrador:
   - o topo: veículos em stock, valor do stock, acima do mercado, o custo por lead paga e, ao lado, os contactos de todas as origens nos veículos com gasto;
   - a nota curta: as interações não guardam a origem;
   - as jogadas e os blocos (stock a envelhecer, preço face ao mercado, anúncios, mais procurados, esquecidos);
   - o seletor "Todos / Carros / Autocaravanas".
2. **Sem Finanças:** uma pessoa sem `financas.ver` vê os valores em euros desfocados; os preços dos veículos continuam à vista.
3. **O dashboard:** as jogadas no fim do separador "Marketing e resultados".
4. **Nunca aparecem** nomes, telefones nem e-mails dos contactos, só contagens.
5. **Editor de perfis** (numa empresa só de stand): dar `bussola.ver` a um perfil já não mostra "Bússola: módulo inativo".

- **Voltar atrás:** como no passo 10.

### Passo 12. A publicação ligada ao veículo pelas fotografias

Só se aplica numa empresa de stand com os Ficheiros ligados. No início, os Ficheiros só ficam ligados na XPLENDOR, por isso este passo fica para quando os Ficheiros se ligarem numa empresa de stand.

- **Confirmar:** numa publicação de teste, "Escolher dos Ficheiros" com uma fotografia da pasta de um veículo (Ficheiros › Veículos):
  - o veículo sai de "Dar palco" e dos "Esquecidos" depois do recálculo (a gravação da publicação lança-o);
  - sem a fotografia, o veículo volta.
- **Voltar atrás:** retirar a fotografia da publicação; a ligação sai com ela.

### Passo 13. Na manhã seguinte

- **Confirmar:**
  - `compass_runs` da empresa com a data dessa manhã (05:20);
  - os Ficheiros da XPLENDOR registados às 04:40 (uma simulação do `files:catalog` mostra 0 novos);
  - `docker exec xplendor-php tail -n 100 storage/logs/laravel.log`, sem erros novos.
- **Voltar atrás:** como nos passos 5 e 10.

---

## Voltar atrás, em resumo

| O quê | Como | Efeito |
|---|---|---|
| A Bússola do stand numa empresa | `bussola:stand-switch <ID> off` | Imediato; os outros não mudam. |
| "Gasto sem contactos" numa empresa | linha em `company_recommendation_settings` (passo 9) | Imediato; a regra sai das Recomendações dessa empresa. |
| Os Ficheiros na XPLENDOR | desligar o módulo no `/admin` | Imediato; nenhum ficheiro foi movido. |
| O OCR | outro modelo da Anthropic em Modelos de IA | Imediato. |
| O ramo da XPLENDOR | trocar de ramo outra vez | Apaga o estado dos meses e as datas escondidas. |
| Todo o código | `git checkout` do commit anterior e `deploy.sh` sem o pull (passo 3) | As migrações ficam; o código anterior funciona com elas. |
| A base de dados | a cópia do passo 1 | Só em último caso: perde o que foi gravado depois. |

## Onde está o detalhe

- **Ficheiros:** `documents/ficheiros/DEPLOY-FICHEIROS.md`.
- **Bússola do stand:**
  - o relatório da noite: `documents/bussola-stand/RELATORIO-MANHA.md`;
  - os ajustes antes do deploy: `documents/bussola-stand/AJUSTES-PRE-DEPLOY.md`;
  - as perguntas e as respostas: `documents/bussola-stand/NOITE-PERGUNTAS.md`.
- **Acessos:** `documents/acl/F3-DECISOES.md`, secções 6c e 6d.
