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
