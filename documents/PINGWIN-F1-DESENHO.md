# PingWin F1: vendas por artigo para o marketing da restauração (desenho aprovado)

Fonte única da F1. Aprovado a 7 de outubro de 2026, com as decisões 1 a 4 respondidas no fim. Os pormenores dos relatórios do PingWin estão em `documents/PINGWIN-RELATORIOS-F0.md`.

Regras permanentes:
- No PingWin, só leituras autorizadas, à noite, em série e com espaçamento. Nunca escrever.
- O Laravel espelha o PingWin; não é a fonte de verdade. Corrige-se voltando a ler, nunca do nosso lado.
- Nenhum dado pessoal (clientes, funcionários). Só agregados de vendas.
- **Interruptor por empresa, desligado por omissão:** `companies.pingwin_item_sales_enabled`. A sincronização nova (vendas por artigo, histórico e catálogo agendado) só corre com ele ligado, para um deploy não a ativar sozinho em produção. Só se liga depois de validada.

## 1. Partes

| Parte | Conteúdo | Commit |
|---|---|---|
| F1-1 | Leitura das vendas por artigo, tabela, sincronização noturna, interruptor | Um |
| F1-2 | Histórico, início de cada loja, catálogo completo, período manual | Um |
| F1-3 | Qualidade dos dados, categorias das famílias, ecrãs | Um |

## 2. Leitura no PingWin (cliente Python)

- O pedido de relatório que já existe é generalizado (`run_report`: ID e parâmetros por argumento), com as mesmas 8 tentativas quando o servidor devolve vazio. O Resumo de Vendas passa a usá-lo, com os mesmos parâmetros.
- **Vendas por artigo** (`919525121217`): até 7 dias por pedido, as lojas ativas cadastradas. Só saem: loja, dia, artigo (ID, código, nome), família (ID, caminho), quantidade, valores sem IVA, de IVA e com IVA. O resto descarta-se.
- **Início de cada loja:** relatório anual (`1093764095994`), uma loja e um ano de cada vez; o primeiro mês com acumulado positivo indica onde parar o histórico. Nunca os agrupamentos com dados pessoais.
- **Catálogo:** a leitura paginada regista o total que o servidor anuncia em cada página e continua até vir uma página vazia ou só com artigos repetidos. Os artigos vendidos que ainda faltem procuram-se nos anulados (a leitura que já existe, no mesmo pedido) e entram no espelho como anulados, contando como cobertos. A leitura família a família foi retirada (correção da sessão de 8 de outubro).

## 3. Tabelas

| Tabela | Conteúdo | Parte |
|---|---|---|
| `companies.pingwin_item_sales_enabled` | Interruptor, desligado por omissão; fora do `fillable` | F1-1 |
| `pingwin_item_sales_daily` | Loja × dia × artigo: código, nome, família, quantidade, valores sem IVA, de IVA e com IVA. Chave: loja + dia + artigo | F1-1 |
| `pingwin_item_sales_days` | Estado de cada loja × dia lido: conferência com o líquido diário | F1-1 |
| `pingwin_locations.sales_since`, `history_complete_at` | Primeiro dia com vendas (detetado) e fim da importação do histórico | F1-2 |
| `restaurant_family_categories` | Família, categoria sugerida, categoria confirmada, quem confirmou e quando | F1-3 |
| `restaurant_data_quality` | Uma linha por empresa: cobertura do catálogo, conferência com o líquido diário, dias de histórico por loja, famílias por classificar | F1-3 |

Categorias: Pratos principais, Petiscos e entradas, Acompanhamentos, Sobremesas, Cafetaria, Cerveja, Vinho, Cocktails e espirituosas, Sem álcool, Menu infantil, Entrega, Outros, Excluir.

## 4. Sincronização

- **Todas as noites (05:00, depois do Resumo de Vendas), com o interruptor ligado:** 1 pedido para os 7 dias anteriores, que substitui esses dias no espelho e apanha as correções tardias.
  - **Proteção:** relatório sem linhas num dia que tem (ou pode ter) vendas → não se apaga nada; o dia fica marcado e volta a ler-se. Só um líquido diário a zero confirma um dia sem vendas.
- **Conferência:** por loja e dia, a soma dos artigos tem de bater com o líquido diário (tolerância de 1%). Antes de marcar um dia que não bate, relê-se o Resumo de Vendas desse dia (um pedido) e volta-se a conferir; só fica marcado se continuar a não bater. Os dias marcados voltam a ler-se.
- **Dias antes do primeiro dia com vendas** de cada loja: ficam como "sem vendas" e não voltam a ler-se.
- **Histórico (F1-2):** processo noturno à parte, que recua em blocos de 7 dias desde o dia mais antigo já espelhado até ao primeiro dia de vendas de cada loja. Até 10 pedidos por noite, 20 s entre eles, uma empresa de cada vez (cerca de 30 pedidos na Yuko, 3 noites). Para em cada loja quando chega ao mês de início detetado; o dia exato é o primeiro dia com vendas.
- **Ao domingo (F1-2):** o catálogo completo.
- **Período manual (F1-2):** o "Sincronizar período" que já existe (até 92 dias) passa a reler também as vendas por artigo desse período, em blocos de 7 dias.

## 5. Ecrãs (F1-3, só o necessário)

- **Integrações, PingWin:** cartão "Dados para o marketing", por loja: início detetado e dias de histórico, estado da importação, conferência com o líquido diário, cobertura do catálogo.
- **"Categorias das famílias":** cada família com o peso na faturação dos últimos 90 dias e a categoria sugerida, para a equipa confirmar. Escolha com react-select, como nos orçamentos.
- Os sinais e o painel "O que publicar e quando" ficam para a F3.

## 6. Testes

- Leitura da resposta, com amostras construídas a partir da estrutura capturada (sem IDs de sessão).
- Substituição dos 7 dias e proteção contra dias vazios.
- Conferência com o líquido diário.
- Deteção do início de cada loja e paragem do histórico.
- Paginação do catálogo.
- Regras das categorias.
- Nenhum campo fora da lista fechada é guardado.
- Acesso por empresa nos endpoints novos.
- Tudo corrido também no MariaDB de dev.

## 7. Critério de "feito"

- A Yuko tem o histórico completo de cada loja.
- Todos os dias batem com o líquido diário (diferença até 1%).
- O catálogo tem 100% dos artigos vendidos nos últimos 90 dias.
- Todas as famílias com vendas têm categoria confirmada (pela equipa).

## 8. Decisões (com as respostas)

1. **Início efetivo de cada loja:** a data de abertura (`opened_on`) manda quando preenchida, como hoje nos painéis. O início detetado aparece ao lado, com aviso quando os dois diferem mais de 7 dias. **Aceite.**
2. **Sugestão de categorias:** primeiro por regras sobre o caminho da família; a IA só nas que sobrarem. **Aceite.** As regras só sugerem: nada fica confirmado sem a equipa.
3. **"Uber Eats \ Comida" = Entrega.** **Aceite.**
4. **Primeira execução real:** manual, com o utilizador a acompanhar, numa hora fora de serviço do restaurante (o dev corre no Mac do utilizador). Preparar o comando e o que se deve ver no resultado. **Aceite.**

## 9. Correções da sessão real (8 de outubro de 2026)

- **Famílias:** todo o histórico, no cartão e na página; na página, as famílias sem vendas nos últimos 90 dias ficam numa secção à parte, recolhida.
- **Comandos manuais longos:** correm num contentor à parte do serviço `worker` (`docker compose run --rm --no-deps worker …`), que não reinicia de hora a hora.
- **Postos de venda do relatório anual:** ficam na configuração da integração PingWin de cada empresa (`annual_locals`), editáveis só pelo root; nunca obtidos pela chamada que lista os parâmetros. Vazio = todos (confirmado na sessão real).
- **Regras das categorias:** "Uber Eats \ Taxas" e "Uber Eats \ Faturas" sugerem "Excluir"; a comida e as bebidas da Uber continuam "Entrega".
- **Respostas às perguntas da noite:** quem confirma as categorias fica como está (administrador da empresa, da agência gestora e root); sem entrada no menu por agora; o histórico passa a job próprio só quando houver uma segunda empresa com o interruptor ligado.

Outras respostas do utilizador:
- O ramo da Yuko em produção é `restauracao`; em dev foi corrigido para o mesmo.
- Histórico: todo o disponível, por loja, detetado pelo primeiro dia com vendas. A comparação com o ano anterior só aparece com 12 meses dessa loja; até lá, semanas contra semanas, com a confiança indicada (F3).
- Margem (F4): do relatório de margem só o custo; a margem calcula-se com o líquido realmente vendido.
- As lojas da Yuko não estão trocadas.

## 10. F2: períodos fracos (construída a 8 de outubro de 2026)

- **Vendas por hora** (PingWin, relatório `1023875499019`): tabela loja × dia × hora (sem IVA), leitura noturna dos 7 dias anteriores (depois das vendas por artigo, 20 s depois), histórico pelo mesmo mecanismo (até ao primeiro dia com vendas de cada loja, no mesmo orçamento de 10 pedidos por noite) e conferência da soma das horas com o líquido diário (1%, com releitura do resumo). A lógica do espelho é partilhada com as vendas por artigo (`PingwinDailyMirrorService`).
- **CoverManager, sem chamadas novas:** da leitura que já se faz guardam-se também os agregados por hora de chegada, por canal (provenance), por antecedência (no próprio dia, 1 a 2, 3 a 7, 8 a 30, mais de 30 dias; sem walk-ins) e a contagem por código de estado. As faltas ("-3") passam a contar à parte das anulações. Mapa dos códigos fechado na sessão de 8 de outubro (secção 11). Histórico de 90 dias só em sessão acompanhada (`covermanager:history`).
- **Mapa da semana** (dia × hora, vendas e pessoas, por loja): últimas 12 semanas a partir do início efetivo da loja, no painel de restauração.
- Tudo atrás do mesmo interruptor por empresa, desligado por omissão.

## 11. Mapa dos códigos de estado do CoverManager (fechado a 8 de outubro de 2026)

Contagem por código nos 90 dias de 10/07 a 07/10/2026 (Yuko, as duas lojas), lida na sessão acompanhada de dev. Os códigos fora do mapa foram vistos numa leitura acompanhada de 04/10 e 26/09 (Costa Cabral) e 05/10 (Baixa), sem gravar nada e mostrando só a hora, as pessoas e o código. A correspondência com os estados foi feita pelo utilizador no ecrã do CoverManager.

| Código | Estado no CoverManager | Grupo | Conta como | Reservas (90 dias) | Fonte |
|---|---|---|---|---|---|
| "1" | Reserva confirmada | CONFIRMADA | válida | 87 | Ecrã do CoverManager (8/10/2026) |
| "2" | Reserva confirmada | CONFIRMADA | válida | 1 | Ecrã do CoverManager (8/10/2026) |
| "3" | Confirmada | CONFIRMADA | válida | 7 515 | Projeto yukotavern (`lib/metrics.php`) |
| "4" | Chegada | VEIO | válida | 3 | Ecrã do CoverManager (8/10/2026) |
| "5" | Concluída | VEIO | válida | 1 267 | Projeto yukotavern (`lib/metrics.php`) |
| "-1" | Reserva cancelada | NÃO ACONTECEU | anulada | 11 | Ecrã do CoverManager (8/10/2026) |
| "-2" | Anulada | NÃO ACONTECEU | anulada | 686 | Projeto yukotavern (`lib/metrics.php`) |
| "-11" | (não visto) | NÃO ACONTECEU | anulada | 2 | **Deduzido, não confirmado** |
| "-3" | Falta | NO SHOW | falta | 136 | Projeto yukotavern (`lib/metrics.php`) |

Grupos usados no mapa:
- **VEIO** (Chegada, Chegada Balcão, Sentado, Sobremesa, Conta solicitada, Limpar) e **CONFIRMADA** contam como válidas.
- **NUNCA CONFIRMADA** (Pendente de confirmação, Reserva para revisão) fica à parte. Nenhum código visto até agora pertence a este grupo.
- **NÃO ACONTECEU** (Cancelada, Liberta, Cartão não inserido) conta como anulada.
- **NO SHOW** conta como falta.

**Códigos fora do mapa ("por classificar"):** qualquer código que não esteja na tabela, incluindo um estado vazio, não conta nas válidas nem nas anuladas, para nunca ser contado às cegas.
- Fica em `cm_reservation_shift_summary.unclassified_count` (migração `2026_12_09_100000`).
- Fica com o código em `cm_reservation_status_daily`, só com o interruptor ligado.
- Não entra na hora, no canal nem na antecedência, nem, por isso, nos sinais da F3.
- Deixa um aviso no registo, só com os códigos e as contagens.
- Aparece no cartão "Dados para o marketing" como "Reservas por classificar (90 dias)", com os códigos.
- O comando `covermanager:history` mostra o total "por classificar".

Quando aparecer um código novo, confirma-se no ecrã do CoverManager e acrescenta-se a `CoverManagerService::STATUS_MAP`, com a fonte nesta tabela.

O mapa é aplicado em `CoverManagerService::STATUS_MAP` e testado em `tests/Feature/CoverManagerDetailsTest.php`. Com este mapa, os números já gravados em dev não mudam, porque "1", "2" e "4" já contavam como válidas e "-1" e "-11" como anuladas.

