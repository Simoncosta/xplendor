# Bússola do stand: relatório da manhã

Noite de 10 para 11 de outubro de 2026. O pedido está em `documents/NOITE-BUSSOLA-STAND.md`.

**Estado:** as cinco partes estão feitas (0 a 4), cada uma com o seu commit na `main` local.
- Sem push e sem deploy; nada correu contra produção.
- Nenhuma chamada a serviços externos. A IA foi simulada nos testes e no ecrã.
- O `SCHEDULE_EXTERNAL_JOBS` está desligado em dev (`scheduler.external_jobs` é `false`).

**Trabalho por gravar de outra pessoa:** a árvore tem alterações por gravar de outra pessoa (OCR, códigos dos fornecedores, IVA dos artigos). Não lhes toquei e não entraram em nenhum commit.
- Quatro ficheiros eram partilhados: `routes/api.php`, `RoutePermissions.php`, `laravel_helper.ts` e as fotografias da ACL. Neles, gravei só as minhas linhas: os commits levam a versão anterior mais as minhas linhas.
- A suite correu sempre com o trabalho dessa pessoa na árvore. A base da noite já tinha 1861 testes, 10 deles dessa pessoa, e as 24 falhas conhecidas.

## 1. O que ficou feito

| Parte | Commit | O que ficou |
|---|---|---|
| Pedido | `4b910f1` | O pedido guardado tal como foi escrito. |
| 0 | `e80571a` | "Veículos" em vez de "Carros" nos textos visíveis (ver o ponto 3), e a pasta do sistema "Viaturas" dos Ficheiros passa a "Veículos". |
| 1 | `54ec5b5` | `DESENHO.md` (o inventário do automóvel e da Bússola da restauração, e o desenho do stand) e `NOITE-PERGUNTAS.md`. |
| 2 | `c54e567` | A Bússola comum (ver o ponto 1.1). |
| 3 | `74c8fa2` | O fornecedor do stand (ver o ponto 1.2). |
| 4 | `314a329` | A verificação no ecrã (ver o ponto 1.3). |

### 1.1 Parte 2: a Bússola comum

- **O núcleo comum** fica em `App\Services\Compass`:
  - a escolha do fornecedor pelos módulos;
  - a escolha das jogadas, o "Quando" e o "Onde";
  - as frases da IA, com o perfil da marca;
  - o "pode agir" e quem vê as Finanças.
- **A restauração passa a usar este núcleo**, com as mesmas classes, rotas e tabelas.
- **Um teste de caracterização** confirma que as respostas da restauração são iguais byte a byte às de antes: a página, o resumo, só as jogadas, uma loja, os sinais e o mapa, com e sem Finanças.
- **No frontend**, ficam comuns o cartão das jogadas, o cartão de cada jogada e as janelas. Um adaptador por ramo dá as chamadas, os textos e os botões.

### 1.2 Parte 3: o fornecedor do stand

- **Sinais:** a partir das regras que já existem (as regras da 2D, a comparação exata, os limiares por tipo).
- **Cinco jogadas**, no máximo 3 de cada vez.
- **Topo, cinco blocos e rodapé.**
- **Ações:** criar publicação com as fotografias do veículo a partir dos Ficheiros; sugerir texto; ignorar; não voltar a sugerir um veículo.
- **Interruptor:** desligado por omissão em cada empresa.
- **Recálculo:** de noite e quando o stock, os preços, o mercado ou os anúncios mudam.
- **Página:** a Bússola, no menu Marketing.
- **Dashboard:** "As 3 jogadas da semana" no fim do separador "Marketing e resultados".

### 1.3 Parte 4: a verificação no ecrã

- **Empresa de demonstração**, claramente fictícia ("Stand Demonstração (fictício)"), com carros, autocaravanas e uma caravana.
- **Pessoas temporárias:** administradora, utilizador sem Finanças, agência convidada e root. Tudo foi apagado no fim.
- **A IA:** durante a verificação, as chaves da IA ficaram vazias no `.env` de dev, para nenhum recálculo nem o "Sugerir texto" chamarem a IA real. As frases "O quê" foram simuladas. O `.env` foi reposto (idêntico à cópia) e o worker reiniciado.
- **A restauração:** numa empresa de restauração de dev, confirmei que a página continua a mostrar a Bússola da restauração (sem capturas, porque a empresa é real).
- **Correções que saíram do ecrã:**
  1. A frase da IA fica ligada aos veículos da jogada. Quando um veículo era ignorado, a frase antiga continuava e falava de outro veículo; agora volta o modelo de frase até ao próximo cálculo.
  2. O número grande deixou de quebrar a linha.
  3. Na lista dos veículos, o detalhe passou para baixo do nome (os nomes ficavam cortados).
  4. As percentagens deixaram de ter uma casa decimal a mais.
- **Também corrigidos antes do commit da Parte 3:**
  - O gasto das 4 semanas passou a contar também os veículos já vendidos.
  - Cada recálculo passou a pedir às regras um retrato novo do stock.

## 2. Testes e capturas

### 2.1 Testes

- **Backend, suite completa:**
  - de 1861 para 1881 testes;
  - as 24 falhas conhecidas mantiveram-se em todas as partes, sem nenhuma nova.
- **Testes novos do backend:**
  - **`RestaurantCompassGoldenTest`:** a restauração igual byte a byte.
  - **`CompassCoreTest`:** o núcleo comum.
  - **`StandCompassTest`** (16 testes):
    - cada tipo de jogada, o agrupamento, a prioridade e nenhuma data passada;
    - o preço só com comparação exata, e os limiares por tipo;
    - a exclusão e o ignorar, o interruptor e a tenancy;
    - sem Finanças nenhum valor financeiro, e nenhum dado pessoal;
    - a criação da publicação, a IA simulada (com o caso da frase que deixa de servir), os gatilhos do recálculo e o dashboard.
- **Testes da restauração:** `RestaurantCompassTest`, `RestaurantSignalsPanelTest` e `RestaurantSignalsTest` continuam todos verdes.
- **Frontend:**
  - de 84 para 92 testes, todos a passar; `App.test.tsx` é a falha conhecida;
  - TypeScript e ESLint sem erros;
  - os testes da restauração (`BussolaDashboardSummary`) continuam verdes, sem alterações.

### 2.2 Capturas

Estão em `documents/bussola-stand/capturas/` (44 imagens), em modo claro e escuro:
- **01 a 09:** a página completa, o topo, as jogadas, os cinco blocos e o rodapé;
- **10:** o detalhe de "Rever o preço";
- **11:** as autocaravanas;
- **12:** os carros depois de ignorar duas jogadas;
- **13:** "Criar publicação", com a nota das fotografias. A ideia ficou criada com 3 fotografias do veículo, a partir dos Ficheiros.
- **14:** o dashboard;
- **15:** sem Finanças, com os valores desfocados;
- **16:** a agência convidada, sem euros e sem os botões da área automóvel;
- **17:** o root;
- **18:** o telemóvel.

Só dados fictícios.

## 3. Os textos alterados de "Carros" para "Veículos"

**Ficaram como estavam:**
- onde "Carros" é um tipo ao lado de "Autocaravanas": o tipo de veículo nos formulários e filtros, o ramo "Carros" da Linha Editorial, os limiares no dashboard ("carros 45 dias, … autocaravanas"), a landing ("stands, carros e autocaravanas");
- os nomes das audiências da Meta ("Carros elétricos" e semelhantes);
- os prompts da IA, os registos e as mensagens de consola;
- uma frase de testemunho de um cliente (citação);
- os nomes internos.

| Onde | Antes | Depois |
|---|---|---|
| Menu Comercial | Carros | Veículos |
| Lista dos veículos | título e separador "Carros"; coluna "Carro"; "Carros sincronizados com sucesso!" | "Veículos"; "Veículo"; "Veículos sincronizados com sucesso!" |
| Criar e editar veículo | "Novo Carro", "Carro criado com sucesso!", "Editar Carro", "Carro atualizado com sucesso!" | "Novo veículo", "Veículo criado com sucesso!", "Editar veículo", "Veículo atualizado com sucesso!" |
| Caminho no topo da edição e da análise | Comercial › Carros | Comercial › Veículos |
| Fechar a venda | "Como chegou ao carro?" | "Como chegou ao veículo?" |
| Descarregar as imagens | `carro-{id}.zip` | `veiculo-{id}.zip` |
| Análise do veículo | "Este carro ainda não foi visto" (título e texto), "Este carro está a receber atenção…", "Este carro já está a transformar…", "Lead gerada associada a este carro." | "Este veículo…" nas mesmas frases |
| Leads | coluna "Carro"; "Pesquisar (nome, telefone, email ou carro)…" | "Veículo"; "…ou veículo)…" |
| Dashboard da plataforma (root) | "Carros em stock" | "Veículos em stock" |
| Resumo do dashboard | "Total carros" | "Total de veículos" |
| Prioridade de investimento | "Carros com sinais fortes…", "Carros com sinais positivos…", "Carros ainda com pouco volume…", "Carros onde investir…", "Nenhum carro está forte…", "Sem carros em observação agora.", "Nenhum carro foi sinalizado…", "Promover este carro", coluna "Carro" | "Veículos…", "Nenhum veículo…", "Sem veículos…", "Promover este veículo", "Veículo" |
| Veículos para promover | "Carros para promover", coluna "Carro", "…sugerir carros a escalar…" | "Veículos para promover", "Veículo", "…sugerir veículos a escalar…" |
| Separadores de marketing | "…a fila de carros com melhor potencial…" | "…a fila de veículos…" |
| Compradores silenciosos | "Monitorizar os carros com maior recorrencia…"; "O carro mais afetado e…" | "os veículos"; "O veículo mais afetado e…" |
| Grupos de perfil | "N carro(s)", "Adiciona mais carros deste perfil…" | "N veículo(s)", "…mais veículos deste perfil…" |
| Maior procura | "🔥 Carros com maior procura", "…identificar carros com maior procura." | "🔥 Veículos com maior procura", "…veículos…" |
| Centro de ações | "O que fazer agora, por carro…", "A carregar decisões reais dos carros..." | "por veículo", "dos veículos..." |
| Executar uma ação | "Este anúncio está ligado a mais N carro(s).", "Este conjunto está ligado a mais N carro(s).", "Esta campanha afeta N carro(s)." | "…veículo(s)." |
| Mapeamento de campanhas | "Nenhuma campanha mapeada a este carro.", "Peso relativo deste carro no target", "…entre carros do mesmo target." | "…este veículo.", "…deste veículo…", "…entre veículos…" |
| Módulos da empresa | categoria "Carros" | "Veículos" |
| Recomendações e decisões (vindas do servidor) | "Promover este carro", "O carro atual tem score fraco.", "Existe outro carro…", "Carro #N", "Carro com procura forte…", "…é o carro com maior potencial…", "Há N carros com tráfego pago…", "…página do carro…", "Melhorar página do carro…", "…reavaliar o carro…", "Redistribuir orçamento para carros com sinal", "O carro precisa de correccoes…", "…enquanto o carro nao for reposicionado.", "Sem investimento, este carro nao vai…", as 6 frases "O carro… contacto" da probabilidade de contacto, "Monitorizar, carro com procura saudável", "Carro com boa conversão…", "Faltam interesses mais próximos do carro…" | "veículo" no lugar de "carro" em cada uma |
| Linha do tempo do veículo | "Carro publicado", "Carro adicionado aos favoritos" | "Veículo publicado", "Veículo adicionado aos favoritos" |
| E-mail da venda | assunto "Mais um carro foi vendido"; "Foi vendido mais um carro na empresa…" | "Mais um veículo foi vendido"; "…mais um veículo…" |
| Página pública do relatório de satisfação | "O seu carro" (sem título do veículo) | "O seu veículo" |
| Landing (análise de mercado) | "Cada carro do seu stock…" | "Cada veículo do seu stock…" |
| API pública de seguimento (mensagem de erro) | "Carro não encontrado para esta empresa." e "Carro CARMINE…" | "Veículo…" |
| Ficheiros, pasta do sistema | Viaturas | Veículos (o registo noturno muda o nome das pastas que já existem) |

## 4. As decisões tomadas por omissão (para confirmar)

### 4.1 Regras e dados

1. **As regras existentes:** a Bússola usa as regras que já existem, tal como estão (as regras da 2D, a comparação exata, os limiares), para dizer o mesmo que as Recomendações.
2. **A restauração** fica intacta, com as mesmas rotas, tabelas e interruptor. O stand tem tabelas próprias, comuns a futuros fornecedores (`compass_*`).
3. **O universo:** "em stock" é o stock próprio (`active`, `available_soon`, `reserved`, sem retomas), como no dashboard; "à venda" é só `active`.
4. **Os tipos:** as caravanas contam como "Autocaravanas" no seletor; as motos só em "Todos".
5. **A ordem das jogadas:** parar o anúncio, rever o preço, renovar o stock parado, aproveitar o interesse, dar palco.
6. **"Renovar o stock parado"** só aparece com dois ou mais veículos.
7. **A confiança:**
   - no preço e no palco, a do mercado (alta ou média);
   - no interesse, alta com o dobro das vistas mínimas, senão média;
   - os factos (stock parado, anúncios) com confiança alta.
8. **O "Dar palco"** pede também confiança média ou alta, como o "acima do mercado".
9. **O custo por contacto:** o gasto das 4 semanas a dividir pelos contactos (de todas as origens) dos veículos com gasto. O gasto conta também os veículos já vendidos. Ver a pergunta 2.
10. **"Sem publicação nos últimos 30 dias"** conta as publicações criadas pela Bússola (tabela `compass_post_subjects`). Ver a pergunta 5.

### 4.2 Ecrã e ações

11. **O "Quando":** "Parar o anúncio" é para hoje ("Fazer hoje"); "Rever o preço" para amanhã ("Rever na…"); as publicações para amanhã ("Publicar na…").
12. **Os botões:**
    - "Ver veículo" abre a ficha (`/cars/{id}/ficha`) e só aparece com `automovel.ver`;
    - "Rever preço" abre a edição (`/cars/{id}`) e só aparece com `automovel.editar`;
    - nas jogadas com vários veículos, ficam só "Criar publicação" e "Sugerir texto".
13. **"Criar publicação"** leva até 4 fotografias do veículo, a partir dos Ficheiros, numa jogada de um só veículo. Sem os Ficheiros ou sem acesso à pasta "Veículos", abre sem fotografias.
14. **"Ignorar"** é por veículo e por sinal, e vale em todos os seletores.
15. **A frase da IA** fica ligada aos veículos da jogada; quando estes mudam, volta o modelo de frase.
16. **A IA:** a mesma função `bussola_jogadas`. A dica em Modelos de IA passou a "(restauração e stand)".
17. **Sem Finanças:** um filtro próprio do stand, com a lista dos campos financeiros. Os preços e as medianas ficam à vista.
18. **O dashboard:** as jogadas ficam no fim do separador "Marketing e resultados". Ver a pergunta 4.
19. **A página:** numa empresa com os dois ramos, mostra a restauração, com um seletor "Restauração / Stand" (pergunta 6). O root na própria empresa vê a restauração, salvo se a empresa só tiver o stock.
20. **O menu:** a Bússola aparece com o PingWin ou com o stock (`anyModule`). O guarda da rota faz o mesmo.

### 4.3 Interruptor, recálculo e permissões

21. **O interruptor:** a coluna `companies.bussola_stand_enabled` e o comando do root `bussola:stand-switch`, desligado por omissão. Em dev ficou desligado nas empresas reais.
22. **O recálculo:**
    - um único trabalho por empresa na fila;
    - lançado quando o veículo muda de estado, preço, venda, tipo, ano, quilómetros, marca ou modelo, quando há um aggregate novo e quando a repartição do gasto é refeita;
    - de noite às 05:20.
23. **A área de permissão `bussola`** continua presa ao PingWin no editor de perfis. Ver a pergunta 7.

## 5. A diferença na fotografia da ACL

- **Rotas novas:** 7, todas em `ensure_module:stock`, com as permissões da área `bussola`. Cada ator passa de 388 para 395 rotas.
- **Rotas existentes:** nenhum ator ganha nem perde acesso a nenhuma.
- **Nas rotas novas:**
  - só o administrador da empresa sem módulos recebe 403, nas 7;
  - os outros 10 atores: a página dá 200 e as escritas 422, porque o pedido da fotografia vai vazio, como na Bússola da restauração.
- **O registo:** está em `documents/acl/F3-DECISOES.md`, secção 6d. Na tabela da secção 7, `sem_modulos_admin` passa de 242 para 249.

## 6. Perguntas pendentes

Estão em `documents/bussola-stand/NOITE-PERGUNTAS.md`:
1. "gasto sem contactos" ou "gasto sem leads" (hoje: leads pagas, a regra da 2D);
2. a definição do custo por contacto;
3. as caravanas e as motos no seletor;
4. o lugar das jogadas no dashboard (Marketing ou junto às Recomendações, no separador Stock);
5. "sem publicação" só com as publicações da Bússola;
6. a empresa com restauração e stand;
7. a área "Bússola" no editor de perfis;
8. "Parar o anúncio" quando o anúncio já está pausado (nova, saiu da verificação no ecrã).

## 7. Lista de deploy

1. **Código:** os commits `4b910f1` a este relatório. Precisa das listas anteriores (`documents/DEPLOY-ACL-R2.md` e `documents/ficheiros/DEPLOY-FICHEIROS.md`) feitas antes ou no mesmo dia.
2. **Migração** (aditiva): `2026_12_30_100000_create_compass_stand`.
   - Acrescenta a coluna `companies.bussola_stand_enabled` (desligada) e as tabelas `compass_signals`, `compass_signal_actions`, `compass_excluded_subjects`, `compass_texts`, `compass_post_subjects` e `compass_runs`.
   - Não mexe nos dados que existem.
3. **Variáveis do `.env`:** nenhuma nova. A IA usa a função `bussola_jogadas`, já em Modelos de IA, com a chave da Anthropic.
4. **Tarefa agendada nova:** `bussola-stand-nightly`, às 05:20 (Lisboa). É interna: recalcula os sinais das empresas com o interruptor ligado, e as frases da IA vão a seguir.
5. **Build:** o build do frontend (o `deploy.sh` corre o `yarn install` e o `yarn build`). O worker das filas já existe e faz o recálculo e as frases.
6. **O interruptor:** a Bússola do stand nasce desligada em todas as empresas. Para a ligar numa empresa com o módulo de stock (o root):
   ```
   docker exec xplendor-php php artisan bussola:stand-switch <ID> on
   ```
   O comando põe o primeiro cálculo na fila. A página aparece no menu Marketing para quem tem `bussola.ver`, e as jogadas no fim do separador "Marketing e resultados" do dashboard.
7. **Verificar depois de ligar:**
   - a página Bússola da empresa: o topo, as jogadas e os blocos;
   - o seletor de tipo;
   - uma pessoa sem Finanças, que vê os valores desfocados.
8. **Voltar atrás:**
   - `bussola:stand-switch <ID> off`, numa empresa (efeito imediato);
   - o código anterior funciona com a migração, porque só acrescenta;
   - a Bússola da restauração não muda.
