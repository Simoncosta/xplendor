# Deploy: Radar de oportunidade e imobiliário (Bússola Master)

Lista para o dia do deploy, por ordem. Escrita a 14 de outubro de 2026. Em todos os passos, os comandos correm a partir de `/home/xplendor`, como na lista anterior.

**Quem ganha o quê, numa linha:** nenhum cliente vê nada de novo nas suas páginas. O Radar fica desligado (`RADAR_ENABLED=false` e o módulo `radar` desligado em todas as empresas) e os imóveis também (o módulo `imoveis` desligado em todas). Só o root vê Administração › Radar, que diz "Radar desligado".

---

## Passo a) Primeiro, o deploy dos Ficheiros e da Bússola do stand

Este código vem depois do de `documents/DEPLOY-FICHEIROS-BUSSOLA.md` (até `ca0a2a9`), que ainda não foi feito (o `origin/main` não tem esses commits). Faz-se primeiro essa lista, no mesmo dia, com uma nota:

- **A tarefa `bussola-stand-nightly` passou a externa** (P2 do fecho: chega à IA). Em produção corre como antes, porque `SCHEDULE_EXTERNAL_JOBS` está ligado por omissão em produção. Nada muda no passo 3 dessa lista; a verificação do `schedule:list` continua a mostrar as 4 tarefas.
- Os passos 1 (cópia da base de dados) e 2 (`.env`) dessa lista fazem-se uma vez para as duas listas. O `deploy.sh` corre uma só vez e leva o código das duas.

**Confirmar:** os passos de confirmação dessa lista, todos.
**Voltar atrás:** os dessa lista.

---

## Passo b) O que entrou na `main` a seguir

**Código:** de `196a18c` ("Noite Bússola Master: o pedido") ao commit deste documento.

| Commit | Parte |
|---|---|
| `f059d41` | R0: desenho e teste de caracterização do stand |
| `e68b441` | R1: recolha dos feeds, filtro de segurança e painel do root |
| `641c5f3` | P1 e P2: Radar desligado por omissão e tarefas com IA como externas |
| `bb84df3` | R2: relevância, encaixe e cartão do Radar |
| `aa42d8b` | R3: imobiliário, DICOFRE e calendário oficial |
| `7dab10c` | R4: verificação no ecrã e correções |
| (este) | Fecho: permissões `imoveis` nos perfis que já existem, a leitura do INE só com imóveis, relatórios |

### b1. Variáveis do `.env` (antes do `deploy.sh`, que faz o `config:cache`)

```
RADAR_ENABLED=false
```

- Fica explícita, apesar de `false` ser a omissão.
- **`AI_FAKE` não se põe em produção.** Mesmo que lá esteja, é ignorada em `production` (há teste), mas não deve existir.
- Opcional: `RADAR_PORQUE_AI_MONTHLY_CAP` (omissão 100 pedidos por empresa e por mês).

**Confirmar:**
```
grep -E "^RADAR_ENABLED|^AI_FAKE" server/.env   # só RADAR_ENABLED=false
```
**Voltar atrás:** tirar a linha (a omissão é a mesma).

### b2. O deploy

```
bash deploy-scripts/deploy.sh
```

**Migrações (7), todas aditivas.** Pela ordem em que correm:

| Migração | O que faz |
|---|---|
| `2027_01_02_100000_create_radar_tables` | Tabelas `radar_runs`, `radar_items`, `radar_topics` (a recolha). |
| `2027_01_03_100000_create_radar_signal_tables` | Tabelas `radar_relevances`, `radar_signals`, `radar_settings`. |
| `2027_01_03_100100_add_radar_fields_to_editorial_posts` | Cinco colunas opcionais em `editorial_posts` (`brief`, `sources`, `valid_until`, `origin`, `origin_ref`); as publicações que existem ficam com elas vazias. |
| `2027_01_04_100000_add_dicofre_to_parishes` | **Migração de dados do DICOFRE.** Cria `official_parishes` (3092 freguesias do INE, a partir de `server/database/data/ine_freguesias_dicofre_censos2021.csv`) e as colunas novas `parishes.dicofre` e `municipalities.dico`, que preenche pelo nome. Só escreve nas colunas novas; nada é apagado nem mudado nas colunas que existem. |
| `2027_01_04_100100_add_imobiliario_sector` | O ramo "Imobiliário" (folha de Universal). |
| `2027_01_04_100200_create_properties_and_ine_housing_prices` | Tabelas `properties` (a carteira) e `ine_housing_prices` (a referência do INE), vazias. |
| `2027_01_04_100300_imoveis_permissions` | A área "imoveis" nos perfis de sistema e na sugestão "Agência convidada" (só dados; os perfis personalizados não mudam). |

**Tarefas agendadas novas (4) e uma mudada:**

| Tarefa | Quando | Tipo | Em produção, logo a seguir ao deploy |
|---|---|---|---|
| `radar-collect-feeds` | de 15 em 15 minutos | externa | Não faz nenhum pedido: `RADAR_ENABLED=false`. |
| `radar-purge-items` | 03:20 | interna | Apaga itens com mais de 30 dias (não há nenhum). |
| `bussola-imoveis-nightly` | 05:25 | externa (chega à IA) | Não faz nada: nenhuma empresa com o módulo `imoveis`. |
| `ine-housing-prices-monthly` | dia 25, 04:50 | externa (lê o INE) | Não faz nenhum pedido: `--if-used` e nenhuma empresa com imóveis. |
| `bussola-stand-nightly` (mudada) | 05:20 | passou a externa | Igual a antes (as externas correm em produção). |

**Funções de IA novas (em Modelos de IA, só o root):** `radar_relevancia` (Claude Haiku 5.5, esforço baixo) e `radar_porque` (Claude Opus 5.5, médio). O Claude Haiku 5.5 entra no catálogo de modelos. Ficam fora do "Aplicar a todas as funções" e do teste às cegas. Não são chamadas com o Radar desligado.

**Build do frontend:** feito pelo `deploy.sh` (a página "Imóveis", o bloco do Radar, o painel "Agora", Administração › Radar).

**Confirmar:**
```
docker exec xplendor-php php artisan migrate:status | grep -E "create_radar_tables|create_radar_signal_tables|add_radar_fields_to_editorial_posts|add_dicofre_to_parishes|add_imobiliario_sector|create_properties_and_ine_housing_prices|imoveis_permissions"   # 7 linhas "Ran"
docker exec xplendor-php php artisan schedule:list | grep -E "radar-collect-feeds|radar-purge-items|bussola-imoveis-nightly|ine-housing-prices-monthly|bussola-stand-nightly"   # 5 linhas
docker exec xplendor-php php artisan tinker --execute="echo json_encode(['radar' => config('radar.enabled'), 'fake' => App\Services\Ai\AiGateway::fake(), 'modulos' => DB::table('company_modules')->whereIn('module_key', ['radar', 'imoveis'])->count(), 'oficiais' => DB::table('official_parishes')->count(), 'com_codigo' => DB::table('parishes')->whereNotNull('dicofre')->count(), 'sem_codigo' => DB::table('parishes')->whereNull('dicofre')->count(), 'imoveis_perfis' => DB::table('profile_permissions')->where('area', 'imoveis')->count()]);"
# esperado: radar false, fake false, modulos 0, oficiais 3092, com_codigo e sem_codigo a somar o total das freguesias, imoveis_perfis maior que 0
docker exec xplendor-php php artisan radar:collect   # "Radar desligado (RADAR_ENABLED=false): nada foi recolhido."
```
- Administração › Radar abre e diz "Radar desligado"; o separador "Freguesias e INE" lista as freguesias sem código (em dev eram 12 de 2873; a lista de dev está em `documents/bussola-master/FREGUESIAS-DICOFRE.md`).

**Voltar atrás:**
- As migrações só acrescentam: o código anterior funciona com elas. `git checkout` do commit anterior e o `deploy.sh` sem o pull (ou os passos dele à mão), como na lista anterior.
- Se for preciso tirar o DICOFRE: as colunas novas ficam vazias de uso com o código anterior; não é preciso apagar nada.
- A base de dados só em último caso (a cópia do passo 1 da lista anterior).

---

## Passo c) Depois do deploy

### c1. O que muda para os clientes: nada nas páginas deles

- O Radar e os imóveis estão desligados em todas as empresas: nenhum bloco do Radar, nenhum painel "Agora", nenhuma página "Imóveis".
- A Bússola da restauração e a do stand respondem exatamente como antes (os testes de caracterização confirmam-no byte a byte).
- Mudanças visíveis, sem efeito no trabalho de ninguém:
  - o ramo "Imobiliário" passa a poder escolher-se na Linha Editorial (com o seu modelo no perfil da marca);
  - no editor de perfis aparece a área "Imóveis" (com o aviso de módulo inativo);
  - o root vê Administração › Radar e as duas funções novas em Modelos de IA.
- O root e a equipa XPLENDOR: nenhuma mudança de acesso a rotas que já existiam (`documents/acl/F3-DECISOES.md`, 6e e 6f).

### c2. Ligar a recolha só para o root (a calibração)

1. No `.env`: `RADAR_ENABLED=true`. Depois:
   ```
   docker exec xplendor-php php artisan config:cache && docker exec xplendor-php php artisan queue:restart
   ```
2. A recolha começa na tarefa seguinte (de 15 em 15 minutos, no máximo 4 pedidos por fonte e por hora). Com nenhuma empresa com o módulo `radar`, só há recolha e filtro: **nenhum pedido de IA** (a relevância só corre nos ramos com o Radar ligado nalguma empresa).
3. O root acompanha em Administração › Radar: o que entrou, o que o filtro tirou e porquê.
4. Para calibrar a relevância e o "porque lhe interessa" com IA real (com os custos do plano do `RELATORIO-MANHA.md`, secção 7): ligar o Radar só na XPLENDOR, em Administração › Radar › Empresas, ou:
   ```
   docker exec xplendor-php php artisan radar:switch 1 on
   ```
   A XPLENDOR passa a receber até 3 sinais por dia, na sua página da Bússola.

**Confirmar:** `docker exec xplendor-php php artisan tinker --execute="echo json_encode([config('radar.enabled'), DB::table('radar_runs')->where('created_at', '>=', now()->subHour())->count()]);"` dá `[true, N]` com N a crescer; Administração › Radar mostra a última recolha por fonte.

**Voltar atrás:** `RADAR_ENABLED=false`, `config:cache` e `queue:restart`; `radar:switch 1 off` se o Radar foi ligado na XPLENDOR. O que foi recolhido apaga-se sozinho aos 30 dias (ou fica, sem efeito).

**Antes de ligar o Radar a um cliente:** as autorizações dos jornais (pergunta 14) e a revisão do filtro com uma semana de recolhas no painel do root.

### c3. Ligar os imóveis numa empresa

1. A referência do INE, uma vez (2 pedidos ao INE; CC BY 4.0, uso comercial permitido):
   ```
   docker exec xplendor-php php artisan ine:housing-prices --execute
   ```
   Depois, a tarefa do dia 25 lê os trimestres novos sozinha (só enquanto houver empresas com imóveis).
2. Na empresa: o ramo "Imobiliário" na Linha Editorial (opcional, para o modelo do perfil da marca), e o módulo `imoveis` em Administração › Empresas › Módulos (ou o preset "Imobiliário" numa empresa nova com esse ramo).
3. A pessoa cria os imóveis na página "Imóveis". A Bússola do imobiliário aparece na página da Bússola; recalcula-se quando a carteira muda e todas as noites às 05:25.

**Confirmar:**
```
docker exec xplendor-php php artisan tinker --execute="echo json_encode(['ine' => DB::table('ine_housing_prices')->max('period_label'), 'imoveis' => app(App\Services\CompanyModuleService::class)->isEnabled(ID, 'imoveis')]);"
```
(trocar `ID` pelo id da empresa). Na página da Bússola da empresa, o seletor "Venda | Arrendamento" e os blocos; na carteira, a referência do INE nos imóveis à venda das freguesias com valor publicado.

**Voltar atrás:** desligar o módulo `imoveis` na empresa. A carteira fica guardada (não se apaga) e volta a aparecer se o módulo voltar a ser ligado.

---

## Passo d) Resumo: confirmar e voltar atrás

| Passo | Confirmar | Voltar atrás |
|---|---|---|
| a) Ficheiros e Bússola do stand | a lista `DEPLOY-FICHEIROS-BUSSOLA.md` | a mesma lista |
| b1) `.env` | só `RADAR_ENABLED=false` | tirar a linha |
| b2) Deploy e migrações | 7 migrações "Ran", 5 tarefas, `radar:collect` diz "desligado" | `git checkout` do commit anterior e o `deploy.sh` sem pull; as migrações ficam |
| c2) Recolha para o root | `radar_runs` a crescer; painel do root | `RADAR_ENABLED=false`, `config:cache`, `queue:restart` |
| c3) Imóveis numa empresa | período do INE gravado; a Bússola do imobiliário na empresa | desligar o módulo `imoveis` |
