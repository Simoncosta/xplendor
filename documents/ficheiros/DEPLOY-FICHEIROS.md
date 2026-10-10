# Deploy: Ficheiros e pré-deploy dos Ficheiros (com o OCR 1 a 4)

Escrita a 10 de outubro de 2026. Nada disto foi feito em produção.

## Base desta lista

- **A lista anterior** (`documents/DEPLOY-ACL-R2.md`) cobre o `main` até `69645d5`.
- **Esta lista** cobre o que veio depois: 22 commits, de `facd5a6` a `cfb2a88`, mais o commit deste documento.
  - **OCR 1 a 4:** a sugestão "Marketing", o OCR em Modelos de IA e na Anthropic, e a comparação dos modelos.
  - **Os dois commits "update":** as listas do back-office, e apagar faturas do OCR com a ordenação das listas.
  - **A noite dos Ficheiros (P0 a F5).**
  - **O pré-deploy dos Ficheiros (pontos 1 a 7).**
- **Se a lista anterior ainda não foi feita,** faz-se primeiro (ou no mesmo dia, antes desta), com uma correção no passo b2: os modelos do OCR passam a ser os da secção b1 desta lista.

**Quem ganha o quê, numa linha:**
- **Ninguém perde nada.** O módulo "Ficheiros" nasce desligado em todas as empresas; liga-se primeiro na XPLENDOR.
- **O OCR das faturas** passa a ler só na Anthropic (claude-opus-5-5, esforço médio), com os PDF com texto enviados como documento.
- **O cabeçalho** mostra o perfil da pessoa por baixo do nome.
- **O leitor de PDF** mostra o nome do ficheiro.
- **A escolha do ramo** tem dois ramos novos: "Software (SaaS)" e "Agência de comunicação".

Em todos os passos, os comandos correm a partir de `/home/xplendor`.

---

## Antes do dia

- **Push do `main`:** sem push feito; o `origin/main` não tem estes commits.
- **Chave da Anthropic:** confirmar que o `.env` de produção tem `ANTHROPIC_API_KEY` (sem ela, o OCR das faturas falha). Os valores não se mostram:
  ```
  grep -c '^ANTHROPIC_API_KEY=.\+' /home/xplendor/server/.env      # 1
  ```

---

## a) Cópia da base de dados

```
cd /home/xplendor
mkdir -p /home/backups
docker exec xplendor-db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases "$MYSQL_DATABASE"' \
  | gzip > /home/backups/xplendor-antes-ficheiros-$(date +%F-%H%M).sql.gz
git log -1 --oneline > /home/backups/commit-antes-ficheiros.txt
ls -lh /home/backups/ | tail -2      # o ficheiro existe e não está vazio
```

---

## b) Deploy

### b1. Variáveis do `.env` (antes do script, que faz o `config:cache`)

```
# OCR: a reserva, quando nada está escolhido em Modelos de IA. Só a Anthropic.
OCR_MODEL_TEXT=claude-opus-5-5
OCR_MODEL_IMAGE=claude-opus-5-5
OCR_REASONING_EFFORT=medium
```
- **OCR:**
  - substituir os valores antigos (`gpt-4o-mini`, `gpt-6.1-sol`, `low`), se existirem;
  - mesmo que fique um modelo da OpenAI no `.env`, o código ignora-o e usa o `claude-opus-5-5`; o OCR nunca vai à OpenAI.
- **`SCHEDULE_EXTERNAL_JOBS`:** não acrescentar. Em produção (`APP_ENV=production`), as tarefas com chamadas externas ficam ligadas por omissão; só fora de produção ficam desligadas.
- **Ficheiros:** sem variáveis novas. Usam `PRIVATE_FILES_DISK` e `MEDIA_DISK`, que já existem.
- **`OPENAI_KEY`:** fica por agora. Pode sair depois do passo c4.
- **Filas:** se existir `REDIS_QUEUE_RETRY_AFTER`, tem de ser pelo menos `3700` (como na lista anterior). O OCR volta à fila com esperas até 20 minutos, e cada leitura pode durar até 15.

### b2. O script

```
bash deploy-scripts/deploy.sh
```
O script faz, por esta ordem:
- o pull, o `down` e o `up -d --build`;
- o `composer install --no-dev`;
- as migrações;
- as caches e o `queue:restart`;
- a compilação do `web` (com `yarn install`: o `ee11b9c` tirou uma dependência) e do `site`;
- o reinício do nginx.

**Migrações (8), todas aditivas.** Pela ordem:

| Migração | O que faz |
|---|---|
| `2026_12_20_100000_ocr_invoices_soft_delete_and_hash` | Colunas para apagar faturas do OCR (com reposição) e o hash do ficheiro, para recusar o mesmo ficheiro outra vez. Calcula o hash das faturas que já existem (lê os ficheiros, não os altera). |
| `2026_12_26_100000_marketing_suggestion_without_restaurant_sales` | A sugestão "Marketing" deixa os ecrãs de vendas da restauração. Só a sugestão; os perfis das empresas ficam. |
| `2026_12_27_100000_add_ai_provider_to_ocr_invoices` | Colunas do fornecedor, do esforço e do tempo de cada leitura do OCR. |
| `2026_12_28_100000_create_files_catalog` | As tabelas dos Ficheiros. Também as colunas `original_disk` e `original_path` em `media_assets`, e `for_files` e `files_folder_id` em `media_uploads`. |
| `2026_12_28_100001_files_permissions` | A área "ficheiros" nos perfis de sistema e nas sugestões. |
| `2026_12_29_100000_ocr_anthropic_only` | Uma escolha gravada nas funções do OCR passa a `claude-opus-5-5`, médio, com uma linha no histórico de Modelos de IA. Sem escolha gravada, não grava nada (vale a reserva do `.env`). |
| `2026_12_29_100001_files_agency_edit` | A agência (o teto e a sugestão "Agência convidada") passa a editar nos Ficheiros: mudar o nome e mover, sem apagar. Volta a gravar os perfis de sistema e as sugestões. |
| `2026_12_29_100002_add_saas_and_agency_sectors` | Os ramos "Software (SaaS)" e "Agência de comunicação" na Linha Editorial. |

Nenhuma migração apaga, move ou copia ficheiros. O catálogo dos Ficheiros só os regista.

### b3. Logo a seguir ao script

```
docker exec xplendor-php php artisan migrate:status | grep -E "2026_12_2[0-9]_.*(ocr_invoices_soft|marketing_suggestion|ai_provider_to_ocr|files_|ocr_anthropic|saas_and_agency)" | grep -c Ran   # 8
docker exec xplendor-php php artisan schedule:list | grep -E "ocr-purge-deleted-files|files-purge-recycle|files-catalog-nightly"   # 3 linhas
docker exec xplendor-php php artisan tinker --execute="echo json_encode([App\Services\Ai\AiFunctionSettings::for('ocr_text'), App\Services\Ai\AiFunctionSettings::for('ocr_image')]);"
# as duas: "provider":"anthropic","model":"claude-opus-5-5","effort":"medium"
```

**Tarefas agendadas novas:**
- `ocr-purge-deleted-files`, às 03:50: apaga do disco o ficheiro das faturas do OCR apagadas há mais de 30 dias.
- `files-purge-recycle`, às 04:00: apaga de vez o que está na reciclagem dos Ficheiros há mais de 30 dias.
- `files-catalog-nightly`, às 04:40: regista nas pastas do sistema os ficheiros novos dos módulos, só nas empresas com os Ficheiros ligados. Não copia nada.

---

## c) Depois do deploy (pela ordem)

### c1. Mudar o ramo da XPLENDOR para "Software (SaaS)"

No ecrã: Perfil da empresa da XPLENDOR › separador "Linha Editorial" › "Trocar de ramo" › "Software (SaaS)" › confirmar.

- **Se a XPLENDOR ainda não tiver ramo,** é a primeira escolha, na própria Linha Editorial.
- **Atenção, a troca de ramo apaga:**
  - o estado dos meses do planeamento da Linha Editorial (voltam a fechados);
  - as datas escondidas da empresa.
- **Ficam:** as publicações e as datas próprias da empresa.
- **Depois:** em "Perfil da Marca" › "Sugerir perfil", a sugestão parte do modelo "Software (SaaS)". Pedir a sugestão é opcional e usa a IA.

### c2. Ligar os Ficheiros na XPLENDOR

No `/admin` › Empresas › XPLENDOR › Módulos › ligar "Ficheiros". As outras empresas ficam com o módulo desligado.

### c3. Registar os ficheiros que a XPLENDOR já tem

O número da empresa:
```
docker exec xplendor-php php artisan tinker --execute="echo App\Models\Company::where('fiscal_name','like','%XPLENDOR%')->get(['id','fiscal_name'])->toJson();"
```

**Simulação.** Mostra, por origem, o total, os novos, os atualizados, os em falta e os registos sem ficheiro no disco. Não grava nada:
```
docker exec xplendor-php php artisan files:catalog --company=<ID>
```

**Depois, a sério:**
```
docker exec xplendor-php php artisan files:catalog --company=<ID> --execute
```

- **Nada é copiado nem movido:** os ficheiros ficam onde estão, e o catálogo só os regista.
- **Abrir a página:** o primeiro abrir de `/app/ficheiros` faz o mesmo registo, e depois corre todas as noites às 04:40.

### c4. Confirmar "Modelos de IA"

Em `/app/admin/ai-models` (Administração › Modelos de IA):
- **OCR:** as duas funções ("PDF com texto" e "digitalizadas e fotografias") em "Claude Opus 5.5", raciocínio médio. A lista de modelos destas duas só mostra os da Anthropic.
- **As outras funções:** ver se alguma tem um modelo GPT (da OpenAI). A mesma consulta em linha de comandos (só leitura):
  ```
  docker exec xplendor-php php artisan tinker --execute="echo DB::table('ai_function_settings')->where('provider','openai')->get(['function','model'])->toJson();"
  ```
  - **Resposta `[]`:** nenhuma função usa a OpenAI. Sem testes às cegas com o GPT, a `OPENAI_KEY` pode sair do `.env` (depois, `config:cache`).
  - **Com linhas:** essas funções usam a OpenAI por escolha. A chave continua a fazer falta, ou trocam-se para um modelo da Anthropic no ecrã.
- **O detalhe:** está em `documents/ocr/COMPARACAO-MODELOS.md`, secção "Decisão (ponto 5)".

---

## d) Verificações no ecrã

1. **Ficheiros, como root na XPLENDOR:**
   - "Os meus ficheiros", "Da plataforma" e "Reciclagem";
   - enviar um PDF de teste e abri-lo: o título do leitor é o nome do ficheiro;
   - apagá-lo e restaurá-lo na reciclagem.
2. **Linha Editorial:** numa publicação de teste da XPLENDOR, em "Conteúdo", aparece "Escolher dos Ficheiros". Escolher uma imagem: nos Ficheiros, ela passa a "Em uso em 1 publicação".
3. **Cabeçalho:** por baixo do nome aparecem "Equipa XPLENDOR" (root) e o perfil (por exemplo, "Administrador") numa conta de cliente.
4. **Escolha do ramo:** "Software (SaaS)" e "Agência de comunicação" aparecem na troca de ramo, em três colunas.
5. **OCR, opcional:** ler uma fatura de teste numa empresa com a Restauração custa cerca de 0,11 USD. No detalhe da fatura, o fornecedor da leitura é a Anthropic, com o `claude-opus-5-5`.
6. **Registos:** `docker exec xplendor-php tail -n 50 storage/logs/laravel.log`, sem erros novos.

---

## e) Como voltar atrás

- **Os Ficheiros:** desligar o módulo na XPLENDOR (no `/admin`, efeito imediato e sem deploy). Os registos do catálogo ficam, mas não aparecem a ninguém. Nenhum ficheiro foi movido nem copiado.
- **O OCR:** em Modelos de IA, escolher outro modelo da Anthropic. O histórico mostra a escolha anterior à migração. Com o código anterior, o OCR volta a ler a reserva do `.env`, que agora diz `claude-opus-5-5` (o código anterior também lê na Anthropic).
- **O código:** as migrações só acrescentam, por isso o código anterior funciona com elas.
  ```
  git checkout <commit de commit-antes-ficheiros.txt>
  ```
  - Depois, o `deploy.sh` sem o pull, ou os passos dele à mão: `up -d --build`, `composer install`, as caches, o `queue:restart` e o build do `web`.
  - Os ramos novos ficam na tabela. Uma empresa que já os tenha escolhido usa o modelo universal do Perfil da Marca até voltar ao código novo.
- **A base de dados:** só em último caso, a cópia do passo a). Perde tudo o que foi gravado depois dela.
