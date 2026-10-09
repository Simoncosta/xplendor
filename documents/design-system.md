# Padrão visual da XPLENDOR

Referência para todas as páginas e para as próximas fatias. Páginas de referência:
- **Restauração > Faturas** (`/restauracao/faturas`, os dois separadores), para cartões, tabelas, "Colunas" e filtros;
- **Cadastros > Fornecedores** (`/restauracao/fornecedores`), para ações por linha;
- **Administração > Regras de formato** (`/admin/creative-format-rules`), para a hierarquia de botões.

**Regras de base** (decididas a 9 de outubro de 2026):
1. Escolhas sempre com react-select, nunca com `<select>` nativo.
2. As ações da página ficam **dentro do cartão**, no cabeçalho do quadro a que dizem respeito. Nunca a flutuar fora dele.
3. As frases descritivas soltas passam para um ícone (i) ao lado do título, com a explicação numa tooltip, que abre ao tocar no telemóvel.
4. Uma única tabela para todo o projeto (`DataTable`), com ordenação, paginação, pesquisa, "Colunas", estados de vazio e de carregamento, e ações por linha.

## 1. Cabeçalho de página

Todas as páginas começam com o `PageHeader` (`src/Components/Common/PageHeader.tsx`):

```tsx
<PageHeader
    title="Faturas"
    breadcrumbs={[{ label: "Restauração" }]}
    info="As faturas de fornecedor: as que carrega para a IA ler e validar, e as que já estão lançadas no PingWin."
    filters={<XSelect ariaLabel="Loja" small … />}
/>
```

- **Título:** `h4`, o mesmo nível em todas as páginas, sem ícone. Usa o nome do menu. Numa página de detalhe, o título é o nome do registo e o último breadcrumb é curto (`crumbLabel`).
- **(i):** a explicação curta da página (`info`), ao lado do título (§3). Nunca uma frase solta por baixo do título.
- **Breadcrumbs:** à direita do título, com a secção do menu e as páginas acima. A página atual entra sozinha no fim.
- **Filtros da página:** só os que valem para a página inteira (período, loja), em `filters`, por baixo do título, à direita.
- **Sem ações.** As ações vão para o cabeçalho do cartão a que dizem respeito (§2).
- **Compatibilidade, até à UI-2:** as páginas ainda não migradas passam `description` e `actions` e continuam a desenhar a barra antiga. O código novo não os usa.
- **Títulos dentro da página:** cartões com `h5.card-title.mb-0` no cabeçalho; subsecções com `h6`. Nunca `h3` nem `h4` dentro da página.

## 2. Cartões (PageCard)

O quadro de uma página é um `PageCard` (`src/Components/Common/PageCard.tsx`):

```tsx
<PageCard
    title="Fornecedores"
    info="Os fornecedores do PingWin."
    status={<>Última sincronização: 09/10, 21:15</>}
    actions={<>{cols.selector}<Button color="outline-primary">Sincronizar</Button><Button color="primary">Novo fornecedor</Button></>}
    filters={<RestFilterBar … />}
>
    <DataTable … />
</PageCard>
```

| Zona | O que leva |
|---|---|
| Título | `h5`, com o (i) quando o quadro precisa de explicação |
| Estado (`status`) | Texto pequeno por baixo do título: "Última sincronização…", "A gravar no PingWin…", contagens ("3 de 200 leituras este mês"). **Estado não é ajuda:** não vai para o (i) |
| Ações (`actions`) | À direita do título, por esta ordem: vistas e "Colunas", secundárias, menu "...", e a ação principal no fim. No telemóvel passam para baixo do título e encostam à esquerda |
| Filtros (`filters`) | Por baixo do cabeçalho: a pesquisa e os filtros do quadro (`RestFilterBar`) |
| Conteúdo | Normalmente um `DataTable` (sem margem) |
| Rodapé (`footer`) | Totais. A paginação do `DataTable` já vem dentro do cartão |

Regras complementares:
- **Separadores:** cada separador tem o seu cartão, com as suas ações. Uma ação nunca muda de sítio conforme o separador.
- **Vistas** (Lista, Kanban, Calendário) usam o controlo segmentado `xp-seg` (CSS em `src/assets/css/xp-design.css`), no cabeçalho do cartão, ao lado das ações.
- **Ações em massa** (aprovar selecionados, limpar) ficam no cabeçalho do cartão, com "N selecionados". Não se usam barras fixas no fundo do ecrã.
- A ação principal (por exemplo, "Carregar fatura") pode repetir-se, em contorno, no estado de vazio da tabela.

## 3. Explicações (InfoTip)

O ícone (i) (`src/Components/Common/InfoTip.tsx`) substitui as frases descritivas soltas.
- Abre ao passar o rato, ao focar com o teclado e ao tocar (telemóvel). É um botão com nome para leitores de ecrã ("Sobre esta página", "Sobre este quadro").
- **Texto curto:** uma ou duas frases. Uma frase longa encurta-se, sem mudar o sentido.
- Usa-se no título da página (`PageHeader` `info`) e no título dos cartões (`PageCard` `info`).
- **Não serve para** avisos (usar `Alert`), estados (usar `status` do cartão), nem para explicar um botão desativado (usar `ReasonButton`, §6).

## 4. Hierarquia de botões

Uma só regra para toda a aplicação.

| Tipo | Quando | Aspeto |
|---|---|---|
| **Principal** | A ação mais importante do quadro (por exemplo, "Nova cobrança", "Carregar fatura", "Novo fornecedor"). **No máximo UMA por quadro.** | `color="primary"` (sólido), com ícone, no fim das ações do cartão. |
| **Secundária** | As outras ações frequentes ("Sincronizar") e o botão "Colunas". | Contorno: `color="outline-primary"`. |
| **Neutra** | Fechar ou cancelar um modal. | `color="light"`, à esquerda da ação do modal. |
| **Positiva** | Só para confirmar um estado positivo (por exemplo, "Marcar como paga", "Aprovar", "Já paguei"). **Nunca como ação principal do quadro.** | `color="success"`. |
| **Destrutiva** | Apagar, anular, retirar, terminar. | Vermelho, **só** no menu "..." (item `danger`) e no botão de confirmação (`color="danger"`). **Nunca solta no cabeçalho nem numa linha de tabela.** |
| **Rara** | Ações pouco usadas (duplicar, copiar link, exportar). | No menu "..." (`ActionsMenu`). |

Regras complementares:
- **Modais:** à esquerda "Cancelar" (neutro); à direita a ação do modal (`primary`, ou `danger` numa confirmação destrutiva, ou `success` numa confirmação positiva).
- **Linhas de tabelas e cartões de lista:** no máximo uma ou duas ações visíveis (`size="sm"`, contorno ou positiva) e o resto no menu "..." (`<ActionsMenu size="sm" />`). Os botões só com ícone têm `aria-label`. Na `DataTable`, vão em `rowActions`.
- **Exceção:** remover uma linha de um formulário ainda por guardar (por exemplo, uma linha de orçamento ou uma tarefa) pode ser um ícone de caixote em contorno vermelho pequeno (`btn-outline-danger btn-sm`), porque nada se apaga até "Guardar".
- **Não usar** as variantes `soft-*`, `ghost-*`, `info`, `warning`, `secondary` nem `dark` em botões. Ligações com aspeto de botão (`<Link className="btn ...">`) seguem a mesma regra.

## 5. Menu "..."

Componente `ActionsMenu` (`src/Components/Common/ActionsMenu.tsx`):

```tsx
<ActionsMenu label="Mais ações: Fatura 12" items={[
    { label: "Copiar link", icon: "ri-link", onClick: copy },
    { label: "Exportar", icon: "ri-download-line", disabledReason: "Sem dados neste mês." },
    { label: "Anular", icon: "ri-forbid-2-line", danger: true, onClick: askCancel },
]} />
```

- As destrutivas ficam no fim, a vermelho, separadas por uma linha, e abrem **sempre** uma confirmação (`confirmAction` de `helpers/swal`, ou um modal com o motivo).
- `hidden` esconde um item; `disabledReason` desativa-o e mostra o motivo por baixo.

## 6. Botões desativados

Um botão desativado **nunca** fica sem explicação. Usa-se `ReasonButton` (`src/Components/Common/ReasonButton.tsx`):

```tsx
<ReasonButton color="primary" onClick={save} reason={!name ? "Indique o nome." : null}>Guardar</ReasonButton>
```

O motivo aparece ao passar o rato, ao focar e ao tocar (telemóvel). Enquanto a ação corre, o botão pode ficar desativado com um `Spinner` dentro: isso não precisa de motivo. Num menu "...", usa-se `disabledReason`. Num formulário, o motivo também pode ficar escrito ao lado do botão (por exemplo, no rodapé do modal).

## 7. Escolhas (Select)

- **Nunca** um `<select>` nativo (nem `<Input type="select">`).
- Componente `src/Components/Common/Select.tsx`, com o tema claro e escuro de `helpers/reactSelectStyles` (variáveis `--vz-*`):
  - **Escolha única:** `XSelect` (`import XSelect from "Components/Common/Select"`). O antigo `pages/Editorial/XSelect` continua a funcionar e só reexporta.
  - **Escolha múltipla:** `XMultiSelect` (chips), com o mesmo tema.
  - `small` para barras de filtros (altura de um `btn-sm`).
- Os menus abrem no `document.body`, para não ficarem cortados em modais e tabelas.
- Com mais de 8 opções, há pesquisa (`searchable` muda isso).
- "Todos" é uma opção com valor vazio (por exemplo, "Todos os fornecedores").

## 8. Tabelas (DataTable)

Todas as listas usam o `DataTable` (`src/Components/Common/DataTable.tsx`), sobre o `@tanstack/react-table` com o markup das Basic Tables do Velzon (`table table-bordered table-hover`, cabeçalho `table-light`).

```tsx
const cols = useDataColumns("restauracao.fornecedores", [
    { id: "code", header: "Código", value: (s) => s.code },
    { id: "name", header: "Nome", value: (s) => s.name, mobile: "title" },
    { id: "total", header: "Total", value: (r) => r.total, cell: (r) => euro(r.total), align: "end" },
    { id: "email", header: "Email", value: (s) => s.email, defaultVisible: false },
    { id: "store", header: "Loja", unavailable: "Aparece quando a fatura estiver ligada ao PingWin." },
]);
<PageCard actions={<>{cols.selector}…</>}>
    <DataTable columns={cols} data={rows} rowKey={(r) => r.id} loading={loading} search={search}
        empty={{ message: "Ainda não há fornecedores.", action: <Button …>Sincronizar</Button> }}
        rowActions={(r) => <>…</>} />
</PageCard>
```

- **Quando usar:** em todas as listas de registos. Não se escrevem tabelas à mão.
- **Modo cliente** (por omissão): a página entrega todas as linhas e a tabela ordena, pesquisa (em todas as colunas com `value`, sem acentos) e pagina (25 por página). Serve para listas até algumas centenas de linhas: a página lê todas as páginas da API.
- **Modo servidor** (`mode="server"` + `server={…}`): a página entrega uma página e a API pagina. A ordenação só existe com `server.onSortChange` e colunas com `sortKey`; sem isso, a tabela não deixa ordenar (nunca setas que não fazem nada).
- **Colunas:** cada coluna é visível por omissão, salvo `defaultVisible: false`. O botão "Colunas" (`cols.selector`) vai para o cabeçalho do cartão, à esquerda das outras ações. A escolha de cada pessoa guarda-se no browser, por tabela (`useTablePrefs`, chave `xp.table.<id>`), e "Repor colunas" volta às de omissão.
- **Colunas sem dados:** uma coluna que ainda não tem dados leva `unavailable` com o motivo. Não se mostra, e aparece desativada no "Colunas" com esse motivo. Nunca se mostram colunas vazias.
- **Células ricas:** `cell` (badges, ligações, riscado); `cellClassName` para cores de célula (por exemplo, âmbar quando falta um valor); `rowClassName` para a linha inteira (por exemplo, anulados a cinzento).
- **Carregamento:** sem linhas, esqueleto; com linhas, a tabela fica esbatida e o cartão mostra o indicador ao lado do título.
- **Vazio:** mensagem e, quando faz sentido, a ação que resolve (em contorno). Com pesquisa sem resultados, diz o que foi pesquisado.
- **Ações por linha:** `rowActions`, segundo o §4 (no máximo uma ou duas visíveis; destrutivas no "..."). A linha pode abrir o detalhe (`onRowClick`), mas há sempre uma ação visível para quem usa teclado.
- **Paginação:** a `Pagination` comum, sempre dentro do cartão, com janela de páginas. Com uma só página, fica só a contagem ("8 resultados").

## 9. Claro e escuro

- Cores sempre pelas variáveis do tema (`var(--vz-body-color)`, `var(--vz-secondary-bg)`, `var(--vz-border-color)`, `bg-*-subtle`, `text-*`) e nunca por valores fixos de fundo ou de texto.
- A barra lateral segue o tema escuro e volta ao tema configurado no modo claro.
- Cada página nova verifica-se nos dois modos.

## 10. Telemóvel

- Largura de referência: 390 px. **Nunca** há scroll horizontal da página.
- O `DataTable` passa a cartões: o título é a coluna com `mobile: "title"` (ou a primeira), as colunas `mobile: "subtitle"` ficam por baixo, as outras em pares rótulo e valor, e as ações no fim. `mobile: "hide"` tira uma coluna do cartão.
- As ações do cabeçalho do cartão passam para baixo do título; a ação principal continua visível.
- Os filtros do `RestFilterBar` passam para um painel lateral ("Filtros").
- O (i) abre ao tocar.
- O botão do tema escuro está no menu do utilizador (no computador, continua no cabeçalho).
- O seletor "A trabalhar em" fica no topo do menu lateral; a faixa de cor do cliente mantém-se visível.

## 11. Impressão

- O `xp-design.css` tem a impressão comum: A4, sem menu, barra de topo, botões, filtros nem paginação (`.xp-no-print`); cabeçalho da tabela repetido em cada página; linhas sem cortes.
- Imprime-se o que está no ecrã: as colunas visíveis e a página atual da tabela.

## 12. Texto

- Português de Portugal formal (tratamento por "você": "Indique", "Escolha", "a sua empresa"), sem travessões. Para separar ideias, usa-se vírgula, dois pontos ou ponto final.
- Os botões dizem a ação com um verbo ("Guardar", "Marcar como paga", "Nova cobrança"), sem reticências.

## 13. Inventário

O levantamento de todas as páginas, antes e depois do primeiro padrão, está em `documents/design-system-inventario.md`.

## 14. Lista de verificação de uma página

1. `PageHeader` com título `h4`, (i) e breadcrumbs; sem ações nem frases soltas.
2. Ações dentro do cartão: no máximo uma principal por quadro; secundárias em contorno; raras e destrutivas no menu "...".
3. Estados ("Última sincronização") no `status` do cartão, não no (i).
4. Listas com `DataTable`: "Colunas", ordenação, vazio, carregamento e paginação dentro do cartão.
5. Verde só para confirmar estados positivos.
6. Nenhum botão desativado sem motivo; nenhum `<select>` nativo.
7. Verificada no browser em claro, escuro e telemóvel, sem scroll horizontal.
