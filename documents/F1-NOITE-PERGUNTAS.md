# F1: perguntas da noite

Perguntas que surgiram durante a construção da F1 (7 para 8 de outubro de 2026). Em cada uma, o que ficou feito enquanto não há resposta.

## 1. Relatório anual: o campo "Locals" vazio quer dizer "todos os postos de venda"?

**Contexto.** A deteção do início de cada loja usa a "Análise de vendas anual". Na captura h8, o back-office enviou a lista explícita dos postos de venda da Yuko (`Locals`: Yuko, Yuko 2, BackOffice, Encomenda, MB, Uber), porque o valor por omissão do parâmetro é "all". Não sei se um `Locals` vazio dá o mesmo resultado (todos) ou nenhum.

**O que ficou feito.**
- O pedido vai com `Locals` vazio, como o `Client` e o `Employee`.
- **Salvaguarda:** se o relatório anual der zero num mês em que o líquido diário espelhado tem vendas, a deteção recusa concluir ("Deteção do início não fiável…") e não grava nada.
- O comando aceita `--locals=` com a lista explícita, para a sessão de amanhã.

**Pergunta.** Na sessão de amanhã, se a deteção recusar, posso usar a lista da captura (`584955579139621602,62590524561075475,1649601157548,1649601157547,1649601156521,56161405118316322`)? E, para o job das 05:00, onde devem ficar estes IDs: na configuração da integração da empresa (são por cliente) ou noutra forma de os obter?
