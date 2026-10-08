# PingWin F3: "O que publicar e quando" (desenho aprovado)

Fonte única da F3. Aprovado a 8 de outubro de 2026, com as decisões 1 a 6 aceites e três ajustes (secção 7). Construído no mesmo dia, num único commit. Depende da F1 (vendas por artigo, categorias das famílias) e da F2 (vendas por hora, agregados do CoverManager), descritas em `documents/PINGWIN-F1-DESENHO.md`.

Regras permanentes (as mesmas da F1):
- Nenhuma chamada nova ao PingWin, ao CoverManager nem à IA para calcular os sinais: tudo sai das tabelas espelhadas.
- Nenhum dado pessoal. Os sinais usam só agregados.
- **Interruptor por empresa, desligado por omissão:** `companies.pingwin_item_sales_enabled`. Sem ele, não se calcula nada e o painel diz só que a leitura está desligada.
- As frases descrevem os números ("vendeu", "ficou abaixo") e nunca dão causas.
- As categorias das famílias são sugeridas pelas regras e só contam como confirmadas quando a equipa as confirma.

## 1. Catálogo de sinais

**Regras para todos os sinais:**
- Calculam-se por loja, só a partir do início efetivo da loja. O fim da janela é ontem (hora de Lisboa).
- Os sinais de confiança baixa nunca aparecem.
- Sem comparação com o ano anterior até a loja ter 12 meses de vendas. O painel diz a partir de quando.
- Valores sem IVA. Os rankings e as variações só contam artigos com pelo menos 100 € no período, o que deixa de fora os modificadores e os extras a 0 €.
- **Categorias "Excluir" e "Entrega" fora de S1, S2 e S4** (ajuste 3). Enquanto as categorias não estiverem confirmadas, ficam de fora, por precaução, as famílias que as regras sugerem como Entrega ou Excluir (decisão por omissão 1).
- Os sinais por categoria (S1 por categoria, S6 entrega) só aparecem com todas as famílias da janela confirmadas. Até lá, o painel mostra a nota "Confirme as categorias das famílias", com a ligação para a página.

| Sinal | Regra e limiares | Fonte | Amostra mínima | Confiança |
|---|---|---|---|---|
| **S1 Os mais vendidos** | Top 5 por loja nos últimos 28 dias, pelo valor, com o peso nas vendas da loja. Com as categorias confirmadas, também as categorias com mais peso | Vendas por artigo | 28 dias da loja, com 90% dos dias lidos | Alta com pelo menos 30 unidades; Média com 10 a 29 |
| **S2 Em subida e em descida** | Últimos 28 dias contra os 28 anteriores. Pelo menos 20 unidades e 100 € em cada janela, variação de pelo menos 25%, e mais 20 pontos do que a variação da loja. Se houver dias especiais nas janelas, a frase diz quais ("Inclui datas especiais: …") | Vendas por artigo e dias especiais | 56 dias da loja | Alta com o mínimo das duas janelas em pelo menos 40 unidades e variação de pelo menos 40%; Média nos outros casos |
| **S3 Períodos fracos e quando publicar** | Últimas 8 semanas, por dia da semana e turno. Fraco quando a média fica pelo menos 20% abaixo da média desse turno na semana. Detalhe na secção 2 | Vendas por hora (sem elas, o líquido diário) e antecedência das reservas | 6 ocorrências do dia | Alta com pelo menos 8 ocorrências e pelo menos 6 abaixo da média; Média nos outros casos |
| **S4 Artigos parados** | Ativo e à venda no catálogo, sem vendas nos últimos 30 dias, com pelo menos 10 unidades e 100 € nas 8 semanas anteriores | Vendas por artigo e catálogo | Catálogo lido nos últimos 14 dias | Sempre Média (pode ter saído da carta) |
| **S5 Antecedência das reservas** | Distribuição por escalão nos últimos 90 dias, sem walk-ins; o escalão mais frequente e o prazo que cobre 80% das reservas, para o dia mais forte da loja | Agregados do CoverManager | 80 reservas | Alta com pelo menos 200 reservas; Média com 80 a 199 |
| **S6 Peso da entrega e canais** | Peso da categoria "Entrega" nas vendas (28 dias; precisa da categoria confirmada) e quota de cada canal de reserva (90 dias) | Vendas por artigo e CoverManager | 28 dias e 80 reservas | Alta com pelo menos 200 reservas; Média abaixo disso |

**Sugestões e informação:**
- **Sugestões** (com botões): S3, S2 e S4. Ordem: Alta antes de Média; depois S3 com a data de publicação mais próxima, S2 em subida, S4 e S2 em descida.
- **Informação** (sem botões): S1, S5 e S6.

## 2. S3 em pormenor

- **Turnos:** almoço das 11h às 15h59; tarde das 16h às 18h59; jantar das 19h à 1h59.
- **A tarde** só é considerada nas lojas onde vale pelo menos 10% das vendas do dia nas 8 semanas (ajuste 2).
- **Por hora ou por dia:** por turnos quando há vendas por hora lidas em pelo menos 90% dos dias da janela; senão, por dia, com o líquido diário.
- **Dias especiais fora da média** (ajuste 1): os feriados nacionais e as datas de âncora não contam como ocorrência normal do dia da semana, nem na média do dia nem na média da semana. A amostra indica quantos dias especiais ficaram de fora.
  - Feriados nacionais calculados no código (os 13 obrigatórios, com a Páscoa móvel).
  - Âncoras do caminho do ramo da empresa (sem país ou PT) e as âncoras próprias da empresa.
  - Âncoras de período só contam quando duram até 7 dias; os períodos mais longos são épocas e não datas especiais.
- **Dia ou turno fechado:** quando metade ou mais das ocorrências estão a zero, não se marca como fraco.
- **Data sugerida para publicar:** o próximo dia fraco menos a antecedência típica das reservas da loja (o escalão mais frequente):
  - no próprio dia: 1 dia antes;
  - 1 a 2 dias: 2 dias antes;
  - 3 a 7 dias, 8 a 30 e mais de 30: 7 dias antes;
  - sem reservas suficientes (menos de 80): 2 dias antes, e a frase diz "Sem dados de antecedência das reservas desta loja".
- **Frase-modelo:** "As terças na loja Baixa ficaram 26% abaixo da média dos dias da semana (média de 600 € contra 815 €, em 7 terças). Sem dados de antecedência das reservas desta loja: sugestão de publicar no domingo, 11/10."

## 3. Tabelas

| Tabela | Conteúdo |
|---|---|
| `restaurant_signals` | Os sinais calculados, por empresa e loja: tipo, chave estável (`signal_key`, única por empresa), sugestão ou informação, confiança, título, frase, números, amostra, tema, data sugerida, prioridade. Substituídos a cada cálculo, numa transação |
| `restaurant_signal_actions` | Registo só de acrescentar: ignorada (até quando), voltou a mostrar, publicação criada (com a ligação à publicação, que fica a null se a publicação for apagada), e quem |
| `restaurant_data_quality.signals_computed_at` | Data do último cálculo |
| `restaurant_data_quality.signals_availability` | Por loja e por sinal: disponível ou não, o motivo e a data a partir da qual fica disponível |

Migração: `2026_12_08_100000_create_restaurant_signals.php`, com `down` testado numa cópia da base de dev.

## 4. Recálculo

- Todas as noites, no fim do job das 05:00 (`ScheduledRestaurantSyncJob`), para as empresas com o interruptor ligado. Um erro no cálculo não interrompe o job; fica no resumo com o prefixo "sinais:".
- Depois de um "Sincronizar período" manual (`SyncItemSalesPeriodJob`).
- Ao abrir o painel, se o último cálculo tiver mais de 24 horas.

## 5. Ecrãs

- **Página "O que publicar e quando"** (`/marketing/o-que-publicar`), no menu Marketing (Análise), só com o módulo `pingwin`.
  - Seletor de loja, com "Todas as lojas" por omissão.
  - Secções, por ordem: Sugestões da semana (6 visíveis, "Mostrar todas", "Mostrar ignoradas"); Mapa da semana (o cartão da F2); Os mais vendidos; Os que mudam; Reservas e canais; Quando chegam os outros sinais.
  - Cada sugestão: título, frase, números em destaque, amostra, confiança, data para publicar, e os botões "Criar publicação" e "Ignorar".
- **"Criar publicação"** abre um modal preenchido para rever: tema, data sugerida, redes (Instagram por omissão) e tipo de conteúdo ("Imagem única" por omissão; "Sazonal" quando a data é um feriado ou uma data de âncora dos próximos 90 dias, a menos que a pessoa escolha outro tipo). Cria uma ideia na Linha Editorial, pelo mesmo caminho das ideias da IA.
  - Se o mês da data não estiver aberto, não se abre nada: aparece "O mês de novembro de 2026 não está aberto na Linha Editorial. Abra-o lá primeiro.", com a ligação.
  - Um título repetido no mesmo mês é recusado.
- **"Ignorar"** esconde a sugestão durante 4 semanas, com registo; "Voltar a mostrar" desfaz. Uma sugestão que já deu publicação mostra "Publicação criada", com a ligação.
- **Quem pode agir:** criar e ignorar exigem o módulo da Linha Editorial e o papel de produtor; sem eles, os botões ficam desativados com o motivo. Ver o painel: qualquer utilizador da empresa com o módulo PingWin.
- **Dashboard de restauração:** no separador "Marketing", o cartão "O que publicar esta semana" com as 3 sugestões principais e "Ver todas (N)"; o separador mostra o número de sugestões.

## 6. A IA

- **"Gerar ideias do mês":** com o PingWin e o interruptor ligados, o prompt recebe o bloco "DADOS DO RESTAURANTE (vendas e reservas; descrevem o que aconteceu, não dizem porquê)", com até 8 sinais Alta e Média, sem os ignorados, primeiro as sugestões cuja data cai no mês. Regra no prompt de sistema: usar como contexto, não inventar números, nunca afirmar causas.
- **"Sugerir criativo":** até 5 sinais relevantes para a data da publicação (o período fraco desse dia da semana, os mais vendidos, o peso da entrega).
- **Sem sinais:** o bloco não aparece e tudo funciona como antes.

## 7. Decisões e ajustes

Decisões 1 a 6 do desenho, aceites a 8 de outubro de 2026:
1. Limiares e janelas da secção 1.
2. Resumo no separador "Marketing" do dashboard, com contador no separador.
3. Modal com os campos editáveis; Instagram e "Imagem única" por omissão.
4. Ignorar durante 4 semanas; criar e ignorar para quem produz na Linha Editorial.
5. Turnos de almoço e de jantar (alargados pelo ajuste 2).
6. Um único commit no fim, com testes e verificação no ecrã.

Ajustes pedidos no OK:
1. S3: feriados e datas de âncora fora da média das 8 semanas.
2. Turno da tarde (16h às 18h59), só nas lojas onde vale pelo menos 10% das vendas do dia.
3. "Excluir" e "Entrega" fora de S1, S2 e S4.

Decisões por omissão tomadas na construção (a confirmar):
1. Com as categorias por confirmar, as famílias que as regras sugerem como Entrega ou Excluir ficam fora de S1, S2 e S4. Sem isto, nos dados de dev, a primeira sugestão era "Molho Extra Uber".
2. S4 exige também 100 € nas 8 semanas anteriores (além das 10 unidades), como os outros rankings.
3. As âncoras de período só contam como dias especiais quando duram até 7 dias.
4. Feriados nacionais calculados no código, sem tabela própria.
5. Data sugerida para S2 e S4: o dia seguinte ao cálculo.
6. Antecedência de 8 a 30 dias e de mais de 30 dias: publicar 7 dias antes (não um mês antes). Sem reservas suficientes: 2 dias antes.
7. "Sazonal" por omissão no modal nas datas especiais dos próximos 90 dias.
8. O pacote de módulos da restauração não inclui a Linha Editorial: sem ela, o painel aparece mas os botões ficam desativados com o motivo.

## 8. Testes

- `tests/Feature/RestaurantSignalsTest.php`: S1 com as exclusões (confirmadas e provisórias); S2 com o desconto da variação da loja; S3 com os dias especiais fora da média, os turnos e a tarde; S4; S5 e S6; poucas reservas; categorias; loja nova e disponibilidade; interruptor e frescura do cálculo.
- `tests/Feature/RestaurantSignalsPanelTest.php`: ordem das sugestões e dias especiais do modal; ignorar e voltar a mostrar; criar publicação só com o mês aberto; quem pode agir e isolamento entre empresas; interruptor desligado; linhas para a IA; blocos nos prompts de ideias e de criativos.
- `tests/Feature/PingwinItemHistoryTest.php`: o job noturno grava `signals_computed_at`.

## 9. Observação sobre os dados de dev

No catálogo de âncoras de dev, "Passagem de Ano" está como data fixa a 15/12 e "Janeiro Verde" a 02/01. Os sinais usam as âncoras como estão: em S3, é o dia 15/12 que fica fora da média, não o 31/12. Convém confirmar estas duas âncoras em produção.
