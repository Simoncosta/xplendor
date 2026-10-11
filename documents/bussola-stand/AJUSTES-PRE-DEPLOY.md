# Bússola do stand: ajustes antes do deploy

Escrito a 11 de outubro de 2026, com as respostas do Simon às perguntas da noite (`NOITE-PERGUNTAS.md`). Um commit por ponto, com a suite completa.

## 1. Gasto sem contactos (perguntas 1 e 8)

A regra das Recomendações `SpendWithoutLeadRule` e a jogada "Parar o anúncio" da Bússola usam a mesma condição. A chave interna da regra (`automotive_spend_without_lead`) ficou igual, porque as definições de cada empresa estão gravadas por ela.

| | Antes | Depois |
|---|---|---|
| Gasto que conta | todos os anúncios com a etiqueta do veículo, também os pausados | só os anúncios com a etiqueta **ainda ativos** |
| O que tira o veículo da regra | uma lead com origem paga | **qualquer contacto** nos mesmos 14 dias: uma lead de qualquer origem, ou um clique no WhatsApp, uma chamada, ou o telefone visto ou copiado |
| Título da recomendação | "Gasto sem lead" | "Gasto sem contactos" |
| Frase | "Os anúncios com a etiqueta desta viatura registaram 35,00 € de gasto nos últimos 14 dias e nenhuma lead com origem paga no mesmo período (limiar: 30 €)." | "Os anúncios ativos com a etiqueta desta viatura registaram 35,00 € de gasto nos últimos 14 dias e nenhum contacto no mesmo período (nem leads, nem cliques no WhatsApp, chamadas ou telefone visto) (limiar: 30 €)." |
| Na Bússola | "com gasto e sem leads pagas" | "com gasto e sem contactos" |

**Exemplo.** Um Peugeot 2008 teve 120 € de gasto nos últimos 14 dias, 4 cliques no WhatsApp e nenhuma lead.
- **Antes:** aparecia em "Gasto sem lead" e em "Parar o anúncio", porque não tinha leads pagas.
- **Depois:** já não aparece, porque teve contactos.

**Outro exemplo.** Um Golf com 38 € de gasto num anúncio entretanto pausado:
- **antes:** aparecia;
- **depois:** não aparece, porque o anúncio já não está ativo.

Os veículos vendidos continuam na regra "anúncio de viatura vendida ainda ativo", que não mudou.

## 2. Custo por lead paga em vez do custo por contacto (pergunta 2)

- **Topo:** o número "Custo por contacto" deu lugar a "Custo por lead paga": o gasto das 4 semanas a dividir pelas leads pagas (as leads guardam a origem).
  - Na legenda ficam, ao lado, as leads pagas e os contactos de todas as origens nos veículos com gasto, sem divisão.
  - Sem leads pagas, o número fica sem valor.
- **Bloco dos anúncios:** as colunas passam a ser Gasto, Leads pagas, Por lead paga e Contactos (de todas as origens).
- **Nota curta, no topo e no bloco:** as interações (WhatsApp, chamadas, telefone) não guardam a origem.
- **Sem Finanças:** o custo por lead paga não vem do servidor. As contagens de leads e de contactos ficam.

## 3. Publicação ligada ao veículo pelas fotografias (pergunta 5)

- **A ligação:** quando os ficheiros de uma publicação mudam (`EditorialWorkflowService::setMedia`), a publicação fica ligada a cada veículo de cuja pasta nos Ficheiros ("Veículos") vem uma fotografia.
  - A ligação refaz-se a cada mudança: se a fotografia sair, a ligação sai.
  - As ligações criadas pela Bússola ficam como estão.
  - O veículo passa a contar como "com publicação nos últimos 30 dias": sai de "Dar palco" e dos "Esquecidos". A Bússola recalcula.
- **Migração aditiva** `2026_12_31_100000_add_source_to_compass_post_subjects`: a coluna `source` diz de onde vem a ligação ("bussola" ou "ficheiros"). As ligações que já existiam ficam "bussola".
- **O campo manual** ("veículo" na publicação) fica para mais tarde.
- **Defeito encontrado e corrigido** (Ficheiros, F4): o registo dos Ficheiros (de noite e ao abrir a página) apagava a ligação entre a imagem de um veículo e a sua media, depois de "Escolher dos Ficheiros".
  - Com isso, perdia-se o "Em uso" e esta ligação deixava de ser encontrada.
  - Agora, nas fontes que não trazem a media, o registo mantém a que lá está.
  - Há um teste que o confirma.

## 4. A área "Bússola" vale para o PingWin ou para o stock (pergunta 7)

- **A área:** `Permissions::AREAS['bussola']` passa a ter dois módulos, `['pingwin', 'stock']`; basta um ativo (`Permissions::areaModules`).
- **O editor de perfis:**
  - a pré-visualização só avisa "módulo inativo" quando nenhum dos dois está ativo;
  - o catálogo das áreas envia a lista dos dois módulos (o tipo no frontend aceita a lista).
- **O acesso não muda:** as rotas continuam a verificar o seu módulo (`ensure_module:pingwin` na restauração, `ensure_module:stock` no stand).
  - A fotografia da ACL, reescrita, ficou igual.
  - Registado em `documents/acl/F3-DECISOES.md`, secção 6d.
- **Teste:** `PermissionProfilesTest::test_the_compass_area_counts_as_active_with_pingwin_or_with_stock`.

## 5. Confirmadas sem alterações (perguntas 3, 4 e 6)

- Caravanas como autocaravanas; motos só em "Todos".
- As jogadas no fim de "Marketing e resultados".
- O seletor "Restauração / Stand".

## 6. Acentos e ortografia (Acordo Ortográfico de 1990) nas mensagens do ramo automóvel

Corrigidas as mensagens visíveis sem acentos ou com a grafia anterior ao Acordo ("actualizado", "eléctrico", "exacto"), no servidor e no ecrã. Onde a frase ficava errada só com o acento, acertou-se também a gramática mínima (por exemplo, "o problema esta na oferta, preco" passou a "o problema está na oferta, no preço"). Dois travessões em mensagens alteradas passaram a dois pontos.

**Como se encontraram:** um vocabulário com as palavras acentuadas que o projeto já usa, comparado com as palavras sem acento dentro das mensagens do ramo automóvel (servidor, ecrãs, API pública das viaturas); os casos ambíguos ("e"/"é", "esta"/"está", "ha"/"há") foram revistos à mão.

**Ficaram de fora, de propósito:**
- **Os extras do equipamento** ("Camara 360º", "Retrovisores Rebativeis Eletricos", "Mesa Rebativel", "Suspensão Pneumatica", "Direcção Assistida", "Travão de Mão Eléctrico"): o nome é o valor gravado em cada viatura; mudá-lo desligava as viaturas que já o têm. Precisa de uma migração de dados, a decidir.
- **Chaves e valores de dados:** as chaves enviadas à IA e as do resultado ("versao", "preco", "nivel", "previsao"), os campos da Carmine ("Preco", "Potencia"), os mapas do scraper e do mercado ("Eléctrico" para o Standvirtual, "eletrico", "hibrido"), os motivos das leads ("preco", "distancia") e os nomes de ficheiros.
- **Registos internos** (logs) e comentários no código.
- **Mensagens em inglês** ("Car updated successfully."): não são uma questão de acentos.

**O que mudou** (antes → depois):

- `CarDecisionEngineService.php`:
  - Testar campanha com contexto favoravel → Testar campanha com contexto favorável
  - Nao investir em ads nesta fase → Não investir em ads nesta fase
  - 'Ainda ha poucos dados proprios, mas benchmark → 'Ainda há poucos dados próprios, mas benchmark
  - 'Ha sinais suficientes para validar → 'Há sinais suficientes para validar
  - mas o preco acima do mercado esta a reduzir a capacidade de conversao.' → mas o preço acima do mercado está a reduzir a capacidade de conversão.'
  - precisa de correccoes em proposta, criativo ou segmentacao antes → precisa de correções em proposta, criativo ou segmentação antes
  - O contexto atual e demasiado fraco para justificar investimento pago enquanto o veículo nao for reposicionado. → O contexto atual é demasiado fraco para justificar investimento pago enquanto o veículo não for reposicionado.
  - Ainda nao existem sinais suficientes para recomendar investimento imediato. → Ainda não existem sinais suficientes para recomendar investimento imediato.
  - Viatura sem dados suficientes — necessario testar mercado para gerar sinais reais. → Viatura sem dados suficientes: é necessário testar o mercado para gerar sinais reais.
  - interesse sem conversao suficiente → interesse sem conversão suficiente
  - sprintf('preco %.1f%% acima → sprintf('preço %.1f%% acima
  - preco competitivo face ao mercado → preço competitivo face ao mercado
  - procura recente em aceleracao → procura recente em aceleração
  - procura recente a perder forca → procura recente a perder força
  - Sem investimento, este veículo nao vai gerar dados nem leads. → Sem investimento, este veículo não vai gerar dados nem leads.
  - Tracao crescente nos ultimos dias e janela favoravel para converter. → Tração crescente nos últimos dias e janela favorável para converter.
  - Primeiro corrigir friccao comercial para nao desperdiçar budget. → Primeiro corrigir a fricção comercial para não desperdiçar budget.
  - Sem sinais suficientes para investir com confianca neste momento. → Sem sinais suficientes para investir com confiança neste momento.
  - Baixa previsibilidade — campanha usada apenas para aprendizagem. → Baixa previsibilidade: campanha usada apenas para aprendizagem.
  - Decisao com base de dados ainda limitada. → Decisão com base de dados ainda limitada.
  - 'Lancar teste curto com budget → 'Lançar teste curto com budget
  - Rever preco e criativo antes de novo spend. → Rever preço e criativo antes de novo spend.
  - 'Rever posicionamento, preco e proposta comercial → 'Rever posicionamento, preço e proposta comercial
  - Criativo comercial direto, com foco em conversao e urgencia real. → Criativo comercial direto, com foco em conversão e urgência real.
  - 'Criativo de validacao com gancho → 'Criativo de validação com gancho
  - 'Criativo de exploracao leve, → 'Criativo de exploração leve,
  - Criativo de revisao orientado a remover friccao e recuperar atencao. → Criativo de revisão orientado a remover fricção e recuperar atenção.
- `CarIssueEngine.php`:
  - Preco acima do mercado → Preço acima do mercado
  - Esta %.1f%% acima da mediana e isso pode estar a travar contacto. → Está %.1f%% acima da mediana e isso pode estar a travar contactos.
  - Rever preco → Rever preço
  - Ajustar preco para recuperar competitividade → Ajustar o preço para recuperar competitividade
  - Tem %d dias em stock e continua sem tracao comercial suficiente. → Tem %d dias em stock e continua sem tração comercial suficiente.
  - Desbloquear rotacao → Desbloquear a rotação
  - Rever preco e relancar o anuncio → Rever o preço e relançar o anúncio
  - Criar novo destaque e refresh ao anuncio → Criar novo destaque e refresh ao anúncio
  - Esta a gerar pouca procura face ao resto do stock ativo. → Está a gerar pouca procura face ao resto do stock ativo.
  - Melhorar destaque e reforcar distribuicao do anuncio → Melhorar o destaque e reforçar a distribuição do anúncio
  - descricao fraca → descrição fraca
  - Anuncio pouco convincente → Anúncio pouco convincente
  - Melhorar anuncio → Melhorar o anúncio
  - Atualizar fotos, titulo e descricao para aumentar resposta → Atualizar fotos, título e descrição para aumentar a resposta
- `CarAiAnalysesService.php`:
  - Quadro medio ou empresario → Quadro médio ou empresário
  - Familia e deslocacoes frequentes → Família e deslocações frequentes
  - Pesquisa, compara e decide apos test drive → Pesquisa, compara e decide após test drive
  - Boa combinacao entre apelo visual e publico-alvo. → Boa combinação entre apelo visual e público-alvo.
  - Capta procura ativa de quem ja esta a comparar. → Capta procura ativa de quem já está a comparar.
  - Revisoes em dia.\nTest drive disponivel esta semana. → Revisões em dia.\nTest drive disponível esta semana.
  - Historico e estado ajudam a reduzir objecoes → Histórico e estado ajudam a reduzir objeções
  - Ajustes de preco ou distribuicao podem acelerar a venda. → Ajustes de preço ou distribuição podem acelerar a venda.
  - Segmentacao por genero limita alcance. → Segmentação por género limita o alcance.
  - Alargar publico, remover interesses e manter Advantage+ para ganhar distribuicao. → Alargar o público, remover interesses e manter Advantage+ para ganhar distribuição.
  - A IA devolveu uma resposta invalida e nao foi possivel interpreta-la. → A IA devolveu uma resposta inválida e não foi possível interpretá-la.
  - A campanha tem boa entrega e sessoes relevantes, mas nao esta a gerar contactos. O problema esta na oferta, preco ou friccao pos-clique. → A campanha tem boa entrega e sessões relevantes, mas não está a gerar contactos. O problema está na oferta, no preço ou na fricção pós-clique.
  - Rever preco, melhorar proposta de valor e reduzir friccao no processo de contacto. → Rever o preço, melhorar a proposta de valor e reduzir a fricção no processo de contacto.
  - A campanha tem boa entrega e ja gerou interacoes reais. Ha margem para optimizar, mas o targeting nao e o problema principal. → A campanha tem boa entrega e já gerou interações reais. Há margem para otimizar, mas o targeting não é o problema principal.
  - Manter campanha ativa e focar na qualidade da proposta e do anuncio. → Manter a campanha ativa e focar na qualidade da proposta e do anúncio.
  - A campanha tem boa entrega e cliques relevantes, mas nao esta a converter. O problema esta na oferta ou friccao pos-clique. → A campanha tem boa entrega e cliques relevantes, mas não está a converter. O problema está na oferta ou na fricção pós-clique.
  - Rever preco, melhorar proposta de valor e analisar a experiencia pos-clique. → Rever o preço, melhorar a proposta de valor e analisar a experiência pós-clique.
- `SilentBuyerDetectionService.php`:
  - Sem compradores silenciosos relevantes nos ultimos {$days} dias. → Sem compradores silenciosos relevantes nos últimos {$days} dias.
  - %d compradores silenciosos detetados nos ultimos %d dias. O veículo mais afetado e %s com %d sinais fortes. → %d compradores silenciosos detetados nos últimos %d dias. O veículo mais afetado é %s, com %d sinais fortes.
  - sprintf('%d compradores silenciosos detetados nos ultimos %d dias.' → sprintf('%d compradores silenciosos detetados nos últimos %d dias.'
  - Nao foram encontrados compradores silenciosos para esta viatura nos ultimos {$days} dias. → Não foram encontrados compradores silenciosos para esta viatura nos últimos {$days} dias.
  - %d visitantes silenciosos com score medio de %d/100 e forte sinal de intencao sem lead formal. → %d visitantes silenciosos com score médio de %d/100 e forte sinal de intenção sem lead formal.
- `MetaAdsCarSyncService.php`:
  - A empresa nao tem uma integracao Meta Ads ativa. → A empresa não tem uma integração Meta Ads ativa.
  - 'O token da integracao Meta Ads expirou. → 'O token da integração Meta Ads expirou.
  - Esta viatura ainda nao tem uma campanha Meta Ads associada. → Esta viatura ainda não tem uma campanha Meta Ads associada.
  - Nao foi possivel atualizar os dados Meta Ads desta viatura. → Não foi possível atualizar os dados Meta Ads desta viatura.
- `CarController.php`:
  - Nao foi possivel gerar a analise IA desta viatura. → Não foi possível gerar a análise IA desta viatura.
  - Nao foi possivel atualizar os dados Meta Ads desta viatura. → Não foi possível atualizar os dados Meta Ads desta viatura.
  - Analise regenerada com sucesso. → Análise regenerada com sucesso.
  - Nao foi possivel regenerar a analise desta viatura. → Não foi possível regenerar a análise desta viatura.
  - Publico recomendado carregado com sucesso. → Público recomendado carregado com sucesso.
  - Nao foi possivel carregar o publico recomendado desta viatura. → Não foi possível carregar o público recomendado desta viatura.
  - Analise do publico carregada com sucesso. → Análise do público carregada com sucesso.
  - Nao foi possivel carregar a analise do publico desta viatura. → Não foi possível carregar a análise do público desta viatura.
  - Aguarda alguns minutos antes de actualizar novamente. → Aguarde alguns minutos antes de atualizar novamente.
  - Apenas imagens enviadas após a actualização suportam re-corte. → Apenas imagens enviadas após a atualização suportam re-corte.
  - Viatura nao encontrada. → Viatura não encontrada.
- `CarAdCampaignController.php`:
  - Estado actualizado. → Estado atualizado.
- `DashboardInsightsCard.tsx`:
  - .replaceAll("ELECTRIC", "Eletrico") → .replaceAll("ELECTRIC", "Elétrico")
  - O que merece leitura rapida → O que merece leitura rápida
  - Ainda nao ha insights suficientes para destacar padroes fiaveis. → Ainda não há insights suficientes para destacar padrões fiáveis.
- `MarketingRoiChannelTable.tsx`:
  - <th>Conversao</th> → <th>Conversão</th>
  - Ainda nao existem dados suficientes para avaliar os canais dos ultimos 7 dias. → Ainda não existem dados suficientes para avaliar os canais dos últimos 7 dias.
- `MarketingRoiInsightsCard.tsx`:
  - >Leitura rapida< → >Leitura rápida<
  - Assim que houver historico suficiente → Assim que houver histórico suficiente
- `MarketingRoiSummaryCards.tsx`:
  - label: "CPL medio" → label: "CPL médio"
  - ROI e decisao de investimento → ROI e decisão de investimento
- `MarketingTopCampaignsCard.tsx`:
  - <th>Conversao</th> → <th>Conversão</th>
  - Ainda nao existem campanhas com dados suficientes para ranking. → Ainda não existem campanhas com dados suficientes para ranking.
- `MarketingTrafficDonutChart.tsx`:
  - Leitura rapida de marketing → Leitura rápida de marketing
  - <Metric label="Conversao" → <Metric label="Conversão"
- `MarketingWorkspaceTabs.tsx`:
  - Execucao e prioridade comercial → Execução e prioridade comercial
- `SilentBuyerExecutiveCard.tsx`:
  - Visitantes com intencao real sem lead formal nos ultimos → Visitantes com intenção real sem lead formal nos últimos
  - >Score medio< → >Score médio<
  - Monitorizar os veículos com maior recorrencia e interacao. → Monitorizar os veículos com maior recorrência e interação.
- `SummaryDashboard.tsx`:
  - label: "Stock proprio" → label: "Stock próprio"
  - label: "Preco medio" → label: "Preço médio"
  - label: "Tempo medio em stock" → label: "Tempo médio em stock"
- `tvdeAuthorization.tsx`:
  - Pelo Proprietario: → Pelo Proprietário:
- `CarPrintSheet.tsx`:
  - title: "Chassis e claraboias" → title: "Chassis e clarabóias"
  - items.push("Degrau eléctrico") → items.push("Degrau elétrico")
- `MarketPositionCard.tsx`:
  - deste modelo exacto à venda → deste modelo exato à venda
- `EnergyClimateAccordion.tsx`:
  - { value: "electric", label: "Eléctrico" } → { value: "electric", label: "Elétrico" }
- `ExteriorAccordion.tsx`:
  - label="Degrau eléctrico" → label="Degrau elétrico"
- `formSearchIndex.ts`:
  - { label: "Degrau eléctrico", → { label: "Degrau elétrico",
- `HeroMotorhomes.tsx`:
  - label: 'Viaturas activas' → label: 'Viaturas ativas'
- `labels.ts`:
  - "electric":        "Eléctrico", → "electric":        "Elétrico",
  - "eletrico":        "Eléctrico", → "eletrico":        "Elétrico",
  - "electrico":       "Eléctrico", → "electrico":       "Elétrico",
- `ScraperController.php`:
  - Execucao nao encontrada. → Execução não encontrada.
- `DashboardRepository.php`:
  - paid' => 'Trafego pago → paid' => 'Tráfego pago
  - Pesquisa organica → Pesquisa orgânica
  - Social organico → Social orgânico
- `CarAdCampaignMapper.tsx`:
  - if (level === "ad") return "Anuncio"; → if (level === "ad") return "Anúncio";
  - Mapeia uma campanha, conjunto ou anuncio para que → Mapeia uma campanha, conjunto ou anúncio para que
  - A carregar campanhas, conjuntos e anuncios do Meta... → A carregar campanhas, conjuntos e anúncios do Meta...
  - `Anuncio do conjunto ${ → `Anúncio do conjunto ${
- `vehicleAttributes.ts`:
  - Cama elevatória eléctrica → Cama elevatória elétrica
- `CarController.php`:
  - Cama elevatória eléctrica → Cama elevatória elétrica

Nenhum teste nem código dependia destes textos (o cartão das decisões tira os acentos antes de comparar o motivo).
