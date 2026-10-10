# Noite: Bússola do stand (pedido do Simon, tal como foi escrito)

MODO NOITE: BÚSSOLA DO STAND (veículos: carros e autocaravanas). Corre sozinho até ao fim; eu revejo de manhã. No MAIN. Ignora CLAUDE.md antigo. NÃO registes em memória. Responde-me sempre em português de Portugal. Sem push nem deploy. Nada contra produção.

PRIMEIRO: grava este pedido em documents/NOITE-BUSSOLA-STAND.md e faz commit. Se o contexto for resumido a meio da noite, volta a lê-lo. Confirma que o SCHEDULE_EXTERNAL_JOBS está desligado em dev.

REGRAS (não negociáveis):
- NENHUMA chamada real a serviços externos: Meta, GA4, scraper, Standvirtual, PingWin, CoverManager, Cloudflare, nem fornecedores de IA (IA simulada nos testes e no ecrã). Só os dados já guardados em dev.
- Migrações só aditivas.
- Uma só sessão a construir: as alterações por gravar de outra pessoa não se tocam nem se incluem; adiciona sempre os ficheiros pelo caminho.
- Um commit por parte, com a suite completa (as 24 falhas conhecidas sem aumento). Se uma parte não ficar verde, NÃO avança: para e documenta.
- A Bússola da restauração NÃO pode mudar de comportamento: os testes dela continuam todos verdes.
- ACL: as rotas novas entram na fotografia do varrimento, e nenhum ator ganha nem perde acesso a rotas que já existiam. Documenta a diferença no F3-DECISOES.md.
- Nunca inventar uma decisão de produto. Na dúvida, escreve a pergunta em documents/bussola-stand/NOITE-PERGUNTAS.md e segue no que não depende dela. As decisões técnicas por omissão ficam documentadas para eu confirmar.
- Honestidade de sempre: frases descritivas, nunca causas; amostra e confiança visíveis; preço face ao mercado SÓ com comparação exata; os sinais de confiança baixa não aparecem.
- Dados pessoais: a Bússola nunca mostra nomes, telefones nem e-mails dos contactos, só contagens. Nas capturas e nos documentos, nada de dados pessoais.
- Português de Portugal formal, sem travessões. Ecrãs: documents/design-system.md e componentes do Velzon, em modo claro e escuro, no computador e no telemóvel.

PARTE 0: "VEÍCULOS" EM VEZ DE "CARROS" (commit)
No ramo automóvel, os stands vendem carros e autocaravanas. Em todos os textos visíveis onde "Carros" (ou "carro") nomeia a área, o menu, os títulos ou o veículo em geral, passa a "Veículos" ("veículo"). Onde "Carros" é um tipo ao lado de "Autocaravanas" (ex.: o filtro "Todos, Carros, Autocaravanas"), fica como está. A pasta do sistema "Viaturas" nos Ficheiros passa a "Veículos". Os nomes internos (tabelas, rotas, permissões, código) não mudam. Lista no relatório cada texto alterado.

PARTE 1: SPIKE E DESENHO (só leitura; documents/bussola-stand/DESENHO.md; commit)
1. Inventário do que já existe no automóvel: StockAge e StockThresholds, PricePosition (e a regra da comparação exata), CarAdSpendRepository e a tag [id:N], o funil por veículo (vistas, contactos, leads), o motor de recomendações e as regras da 2D, CarSalePotentialScore, CarPerformance, os insights da Meta guardados, o GA4. Para cada fonte: que dados há em dev, que janela, que fiabilidade.
2. Como está construída a Bússola da restauração (sinais, jogadas, "O que fazer", IA bussola_jogadas, Ignorar e exclusão permanente, dashboard, regra sem Finanças, interruptor) e o plano para a tornar COMUM, com um fornecedor por ramo.
3. O desenho do fornecedor do stand, com estas decisões já tomadas:
   - JOGADAS (no máximo 3, uma por tipo; prioridade: primeiro o dinheiro a perder, depois a força do sinal):
     a. "Parar o anúncio de [veículo]": vendido com anúncio ativo, ou gasto sem contactos (as regras da 2D);
     b. "Rever o preço de [veículo]": acima do limiar de dias (45 nos carros, 120 nas autocaravanas) E acima do mercado, só com comparação exata; mostra os dias em stock, a % acima e a mediana dos comparáveis com o número de comparáveis; NUNCA sugere um preço;
     c. "Aproveitar o interesse em [veículo]": muitas vistas e zero contactos;
     d. "Dar palco a [veículo]": preço alinhado ou abaixo do mercado, sem anúncio e sem publicação nos últimos 30 dias;
     e. "Renovar o stock parado": vários veículos acima do limiar, juntos numa só jogada com a lista.
     Vários veículos com o mesmo tipo de sinal juntam-se numa jogada (como as sobremesas na restauração).
   - "O QUE FAZER" adaptado ao stand: O quê (frase da IA bussola_jogadas, com os dados do veículo: marca, modelo, ano, km, preço; sem números inventados nem causas; a IA usa também o perfil da marca da empresa: tom, palavras a usar e a evitar), Onde (Instagram e Facebook, e o anúncio quando for tráfego pago), Quando (nunca uma data passada).
   - Botões conforme o tipo: "Criar publicação" (preenchida com o texto e com as fotografias do veículo, escolhidas a partir dos Ficheiros, sem cópia; se a empresa não tiver os Ficheiros ligados, abre sem fotografias, com a indicação de as escolher), "Sugerir texto" (legenda com os dados do veículo), "Ver veículo", "Rever preço" (abre a edição do veículo), "Ver detalhe" (modal grande). Cada botão só aparece a quem tem a permissão do destino.
   - TOPO (só na página da Bússola): veículos em stock e o valor do stock, dias médios em stock, % de veículos acima do mercado (com comparação exata), gasto em anúncios das últimas 4 semanas e custo por contacto. O contexto dito uma só vez, dentro do cartão branco.
   - BLOCOS, cada um com manchete descritiva: "Stock a envelhecer" (escalões de dias, por tipo, e os mais antigos); "Preço face ao mercado" (abaixo, alinhado, acima; os mais acima); "O que os anúncios estão a render" (gasto, contactos e custo por contacto por veículo; gasto sem contactos); "Os mais procurados" (vistas e contactos, 4 semanas); "Esquecidos" (à venda há mais de 30 dias sem anúncio nem publicação). Rodapé dentro de um cartão branco.
   - DASHBOARD DO STAND: "As 3 jogadas da semana" no FIM do separador "Marketing e resultados", junto às recomendações, sem repetir os números do separador de stock; seletor de tipo (Todos, Carros, Autocaravanas) no cabeçalho do cartão das jogadas.
   - Os veículos sem tipologia ou sem comparáveis ficam fora das comparações de preço, com uma nota.
   - SEM FINANÇAS (a mesma regra da restauração): quem não pode ver as Finanças não recebe do servidor o valor do stock, o gasto em anúncios nem o custo por contacto (no ecrã, números fictícios desfocados e "Sem acesso aos valores financeiros"). O preço de cada veículo e a mediana dos comparáveis ficam visíveis, porque são públicos.
   - QUEM VÊ: as mesmas regras de acesso da Bússola da restauração (a agência convidada vê, sem os euros). Como a Bússola não mostra dados pessoais, não exige a área automóvel; os botões que abrem essa área só aparecem a quem a tem.
   - INTERRUPTOR: a Bússola do stand aparece só nas empresas com o módulo de stock e, em produção, nasce desligada em cada empresa até o root a ligar (como a da restauração).
   - Deixar o lugar para fontes de contactos externas (o Standvirtual, mais tarde), sem as construir.

PARTE 2: A BÚSSOLA COMUM (construir; commit)
Separar o que é comum (página, topo, jogadas, "O que fazer", modal, Ignorar e exclusão permanente, IA, regra sem Finanças, interruptor, dashboard) do que é da restauração, sem mudar NADA do que a restauração mostra. A página escolhe o fornecedor pelos módulos da empresa. Testes da restauração todos verdes.

PARTE 3: O FORNECEDOR DO STAND (construir; commit)
Sinais, jogadas, blocos, recálculo (todas as noites e quando o stock, os preços ou os anúncios mudam), a página Bússola no menu Marketing para as empresas com o módulo de stock, e o dashboard. Testes: cada tipo de jogada, o agrupamento, a prioridade, nenhuma data passada, o preço só com comparação exata, os limiares por tipo, a exclusão permanente de um veículo, o interruptor, a tenancy, sem Finanças nenhum valor financeiro na resposta, e nenhum dado pessoal na resposta.

PARTE 4: VERIFICAÇÃO NO ECRÃ (commit)
Com uma empresa de stand de dev (carros e autocaravanas). Se os dados não chegarem, cria uma empresa de demonstração claramente fictícia, apagada no fim. Pessoas temporárias, apagadas no fim: administrador, utilizador sem Finanças, agência convidada e root. Computador e telemóvel, modo claro e escuro. Capturas em documents/bussola-stand/capturas/, sem dados pessoais.

NO FIM: documents/bussola-stand/RELATORIO-MANHA.md com:
1. O que ficou feito em cada parte, com o commit.
2. Os testes e as capturas.
3. Os textos alterados de "Carros" para "Veículos".
4. As decisões tomadas por omissão, para eu confirmar.
5. A diferença na fotografia da ACL.
6. As perguntas pendentes.
7. A lista de deploy (migrações, comandos, tarefas agendadas, o interruptor).
