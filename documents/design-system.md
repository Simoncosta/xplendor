# Padrão visual da XPLENDOR

Referência para todas as páginas e para as próximas fatias. A página de referência é **Administração > Regras de formato** (`/admin/creative-format-rules`).

## 1. Cabeçalho de página

Todas as páginas da aplicação começam com o componente `PageHeader` (`src/Components/Common/PageHeader.tsx`):

```tsx
<PageHeader
    title="Cobranças"
    breadcrumbs={[{ label: "Administração", to: "/admin" }]}
    description="Faturas da XPLENDOR aos clientes."
    actions={<>
        <Button color="outline-primary">Exportar</Button>
        <ActionsMenu items={[...]} />
        <Button color="primary"><i className="ri-add-line me-1" />Nova cobrança</Button>
    </>}
/>
```

- **Título:** `h4`, o mesmo nível em todas as páginas, sem ícone. Usa o nome do menu (por exemplo, "Tickets" e não "Administração do suporte"). Numa página de detalhe, o título é o nome do registo e o último breadcrumb é curto (`crumbLabel`).
- **Breadcrumbs:** à direita do título, com a secção do menu e as páginas acima. A página atual entra sozinha no fim.
- **Barra de ações:** por baixo do título. A descrição curta fica à esquerda e as ações à direita, por esta ordem: secundárias, menu "...", ação principal (a última, à direita). No telemóvel, as ações passam para baixo da descrição e encostam à esquerda.
- O antigo `BreadCrumb` continua a funcionar e já desenha o `PageHeader`; o código novo usa o `PageHeader`.
- **Títulos dentro da página:** cartões com `h5.card-title.mb-0` no `CardHeader`; subsecções com `h6`. Nunca `h3` nem `h4` dentro da página.

## 2. Hierarquia de botões

Uma só regra para toda a aplicação.

| Tipo | Quando | Aspeto |
|---|---|---|
| **Principal** | A ação mais importante da página (por exemplo, "Nova cobrança", "Novo orçamento", "Nova publicação"). **No máximo UMA por página.** | `color="primary"` (sólido), com ícone. Tamanho normal no cabeçalho. |
| **Secundária** | As outras ações frequentes. | Contorno: `color="outline-primary"`. |
| **Neutra** | Fechar ou cancelar um modal. | `color="light"`, sem ícone, à esquerda da ação do modal. |
| **Positiva** | Só para confirmar um estado positivo (por exemplo, "Marcar como paga", "Aprovar", "Já paguei"). **Nunca como ação principal da página.** | `color="success"`. |
| **Destrutiva** | Apagar, anular, retirar, terminar. | Vermelho, **só** no menu "..." (item `danger`) e no botão de confirmação (`color="danger"`). **Nunca solta no cabeçalho nem numa linha de tabela.** |
| **Rara** | Ações pouco usadas (duplicar, copiar link, exportar). | No menu "..." (`ActionsMenu`). |

Regras complementares:

- **Modais:** à esquerda "Cancelar" (neutro); à direita a ação do modal (`primary`, ou `danger` numa confirmação destrutiva, ou `success` numa confirmação positiva).
- **Linhas de tabelas e cartões de lista:** no máximo uma ou duas ações visíveis (`size="sm"`, contorno ou positiva) e o resto no menu "..." (`<ActionsMenu size="sm" />`). Os botões só com ícone têm `aria-label`.
- **Exceção:** remover uma linha de um formulário ainda por guardar (por exemplo, uma linha de orçamento ou uma tarefa) pode ser um ícone de caixote em contorno vermelho pequeno (`btn-outline-danger btn-sm`), porque nada se apaga até "Guardar".
- **Não usar** as variantes `soft-*`, `ghost-*`, `info`, `warning`, `secondary` nem `dark` em botões. Ligações com aspeto de botão (`<Link className="btn ...">`) seguem a mesma regra.
- **Vistas** (Lista, Kanban, Calendário…) não são ações: usam o controlo segmentado `xp-seg` (CSS em `src/assets/css/xp-design.css`).

## 3. Menu "..."

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

## 4. Botões desativados

Um botão desativado **nunca** fica sem explicação. Usa-se `ReasonButton` (`src/Components/Common/ReasonButton.tsx`):

```tsx
<ReasonButton color="primary" onClick={save} reason={!name ? "Indique o nome." : null}>Guardar</ReasonButton>
```

O motivo aparece ao passar o rato, ao focar e ao tocar (telemóvel). Enquanto a ação corre, o botão pode ficar desativado com um `Spinner` dentro: isso não precisa de motivo. Num menu "...", usa-se `disabledReason`. Num formulário, o motivo também pode ficar escrito ao lado do botão (por exemplo, no rodapé do modal).

## 5. Escolhas (react-select)

- **Nunca** um `<select>` nativo. Escolha única com `XSelect` (`src/pages/Editorial/XSelect.tsx`), com o tema claro e escuro de `helpers/reactSelectStyles`. Escolha múltipla com `react-select` (`isMulti`) e `styles={reactSelectTheme}`.
- Os menus abrem no `document.body` (`menuPortalTarget`), para não ficarem cortados em modais e tabelas.
- Com mais de 8 opções, há pesquisa.

## 6. Claro e escuro

- Cores sempre pelas variáveis do tema (`var(--vz-body-color)`, `var(--vz-secondary-bg)`, `var(--vz-border-color)`, `bg-*-subtle`, `text-*`) e nunca por valores fixos de fundo ou de texto.
- A barra lateral segue o tema escuro e volta ao tema configurado no modo claro.
- Cada página nova verifica-se nos dois modos.

## 7. Telemóvel

- Largura de referência: 390 px. **Nunca** há scroll horizontal da página; as tabelas ficam dentro de `.table-responsive` e os controlos segmentados deslizam por dentro.
- A barra de ações do cabeçalho passa para baixo da descrição; a ação principal continua visível.
- As tabelas do `XTanStackTable` ficam em `.table-responsive` por omissão e a paginação quebra linha.
- O botão do tema escuro está no menu do utilizador (no computador, continua no cabeçalho).
- O seletor "A trabalhar em" fica no topo do menu lateral; a faixa de cor do cliente mantém-se visível.

## 8. Texto

- Português de Portugal formal (tratamento por "você": "Indique", "Escolha", "a sua empresa"), sem travessões. Para separar ideias, usa-se vírgula, dois pontos ou ponto final.
- Os botões dizem a ação com um verbo ("Guardar", "Marcar como paga", "Nova cobrança"), sem reticências.

## 9. Inventário

O levantamento de todas as páginas, antes e depois deste padrão, está em `documents/design-system-inventario.md`.

## 10. Lista de verificação de uma página

1. `PageHeader` com título `h4`, breadcrumbs e barra de ações.
2. No máximo uma ação principal; secundárias em contorno; raras e destrutivas no menu "...".
3. Verde só para confirmar estados positivos.
4. Nenhum botão desativado sem motivo; nenhum `<select>` nativo.
5. Verificada no browser em claro, escuro e telemóvel, sem scroll horizontal.
