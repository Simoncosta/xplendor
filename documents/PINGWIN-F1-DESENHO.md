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
