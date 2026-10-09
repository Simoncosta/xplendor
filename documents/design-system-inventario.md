# Inventário do padrão visual

> **Substituído em parte (UI-1, 9 de outubro de 2026):** as ações e a descrição no `PageHeader` (coluna "Depois: ações do cabeçalho") deixaram de ser o padrão. As ações passam para o cabeçalho do cartão (`PageCard`) e a descrição para o (i) ao lado do título (`documents/design-system.md` §1 a §3). As páginas abaixo migram na UI-2.

Levantamento dos cabeçalhos e dos botões de todas as páginas da aplicação, antes e depois da aplicação do padrão de `documents/design-system.md`.

- **Antes:** lido do código no commit anterior a esta fatia (elemento do título, breadcrumbs, variantes de botão encontradas no ficheiro da página, `<select>` nativos).
- **Depois:** lido no browser (dev) em computador e modo claro. Cada página foi também verificada em modo escuro e no telemóvel (390 px), sem erros, sem scroll horizontal, com no máximo uma ação principal, sem variantes proibidas e sem botões destrutivos soltos.
- O título aparece em maiúsculas por causa do estilo do tema; no código é o nome da página.

| Rota | Antes: título | Antes: breadcrumbs | Antes: variantes de botão | Depois: título | Depois: breadcrumbs | Depois: ações do cabeçalho |
|---|---|---|---|---|---|---|
| `/dashboard` | sem título | não | sem botões | Painel De Gestão | Dashboards > Painel de gestão | sem ações |
| `/agency` | BreadCrumb: Painel da agência | sim | primary ×5, soft-primary ×2, light ×1 | Painel Da Agência | Painel da agência | sem ações |
| `/root/companies` | BreadCrumb: Empresas | sim | danger ×1, primary ×1 | Empresas | Administração > Empresas | sem ações |
| `/root/companies/:companyId/users` | BreadCrumb: companyName ||  | sim | danger ×1, light ×1, primary ×1, soft-primary ×1 | Pa Automóveis | Administração > Empresas > Utilizadores | Voltar |
| `/restauracao` | BreadCrumb: Restauração | sim | soft-primary ×1 | Restauração | Painel > Restauração | sem ações |
| `/restauracao/lojas` | h4: Lojas | não | soft-primary ×3, primary ×3, soft-danger ×2, light ×2 | Lojas | Restauração > Lojas | Sincronizar, Adicionar loja |
| `/restauracao/calendario` | h4: Calendário de faturação | não | sem botões | Calendário De Faturação | Restauração > Calendário de faturação | Todas as lojas |
| `/restauracao/documentos` | h4: Documentos | não | light ×3, primary ×3, soft-secondary ×3, soft-primary ×2, soft-danger ×2, danger ×1; 4 select nativo | Documentos | Cadastros > Documentos | Sincronizar lista, Novo documento |
| `/restauracao/artigos` | h4: Artigos | não | primary ×2, soft-primary ×2, success ×1, warning ×1 | Artigos | Restauração > Artigos | Sincronizar, Novo artigo |
| `/editorial` | BreadCrumb: Linha Editorial | sim | primary ×1 | Linha Editorial | Marketing > Linha Editorial | sem ações |
| `/restauracao/artigos/novo` | sem título | não | primary ×2, light ×2, outline-danger ×1, danger ×1 | Novo Artigo | Restauração > Artigos > Novo artigo | sem ações |
| `/restauracao/artigos/:pingwinId` | sem título | não | primary ×2, light ×2, outline-danger ×1, danger ×1 | Mesmo ecrã que `/restauracao/artigos/novo` | | |
| `/restauracao/familias` | h4: Famílias | não | soft-secondary ×2, soft-primary ×1 | Famílias | Cadastros > Famílias | Sincronizar |
| `/restauracao/fornecedores` | h4: Fornecedores | não | soft-primary ×1 | Fornecedores | Cadastros > Fornecedores | Sincronizar |
| `/restauracao/faturas` | h4: Faturas | não | primary ×1, soft-primary ×1 | Faturas | Restauração > Faturas | Carregar fatura |
| `/restauracao/unidades` | h4: Unidades | não | danger ×3, soft-primary ×3, light ×3, primary ×2, soft-danger ×2, warning ×1 | Unidades | Cadastros > Unidades | Sincronizar, Criar unidade |
| `/restauracao/condicoes-pagamento` | h4: Condições de Pagamento | não | soft-secondary ×4, light ×2, primary ×2, soft-danger ×2, danger ×1, soft-primary ×1 | Condições De Pagamento | Cadastros > Condições de Pagamento | Sincronizar, Nova condição |
| `/companies` | h5: Empresas | não | info ×1, soft-warning ×1, success ×1, soft-primary ×1, soft-secondary ×1 | Empresas | Administração > Empresas | Pedidos, Nova empresa |
| `/companies/:id` | sem título | não | sem botões | Pa Automóveis | Administração > Empresas > Perfil da empresa | sem ações |
| `/companies/create` | sem título | não | sem botões | Nova Empresa | Administração > Empresas > Nova empresa | sem ações |
| `/cars` | h5:  | não | soft-info ×2, soft-primary ×2, soft-secondary ×1, soft-danger ×1, primary ×1 | Carros | Comercial > Carros | Nova viatura |
| `/actions` | h3: O que fazer agora, por carro | não | light ×1 | Ações | Comercial > Ações | Atualizar |
| `/cars/create` | sem título | não | sem botões | Nova Viatura | Comercial > Carros > Nova viatura | sem ações |
| `/cars/:id` | sem título | não | sem botões | Peugeot 308 Sw | Comercial > Carros > Editar | sem ações |
| `/cars/:id/analytics` | h6: Distribuição de tráfego | não | sem botões | Peugeot 308 Sw | Comercial > Carros > Tráfego e canais | Editar viatura |
| `/cars/:id/intelligence` | sem título | não | sem botões | Peugeot 308 Sw | Comercial > Carros > Mercado e público | Editar viatura |
| `/cars/:id/ficha` | h6: Informação Técnica | não | primary ×1 | Peugeot 308 Sw | Comercial > Carros > Ficha | Editar viatura |
| `/cars/:id/documents` | h6: Documentos indisponíveis em rascunho | não | primary ×1 | Peugeot 308 Sw | Comercial > Carros > Documentos | Editar viatura |
| `/leads` | h6:  | não | soft-primary ×2, soft-success ×2, soft-secondary ×2, primary ×2, outline-primary ×2 | Leads | Comercial > Leads | Lista Funil |
| `/stock/promotion` | h5:  | não | soft-secondary ×2 | Candidatas A Promoção | Comercial > Candidatas a promoção | PA Automóveis |
| `/quotes` | h4: Orçamentos | não | success ×1, outline-danger ×1 | Orçamentos | Equipa > Orçamentos | sem ações |
| `/users` | h4: Colaboradores | não | primary ×4, soft-secondary ×3, light ×2, success ×1, soft-danger ×1; 2 select nativo | Colaboradores | Configurações > Colaboradores | Novo colaborador |
| `/users/create` | h4:  | não | primary ×2, secondary ×1; 2 select nativo | Novo Colaborador | Configurações > Colaboradores > Novo | Guardar |
| `/users/collaborators/new` | h4:  | não | primary ×2, secondary ×1; 2 select nativo | Mesmo ecrã que `/users/create` | | |
| `/users/collaborators/:id` | h4:  | não | primary ×2, secondary ×1; 2 select nativo | Mesmo ecrã que `/users/create` | | |
| `/users/:id` | sem título | não | sem botões | Paulo Alves | Configurações > Colaboradores > Colaborador | Cancelar, Guardar |
| `/brand-profile` | h4: Perfil da Marca | não | primary ×2, soft-danger ×2, soft-primary ×2, light ×2, soft-success ×1, soft-secondary ×1 | Perfil Da Marca | Marketing > Perfil da Marca | Sugerir perfil, Guardar |
| `/blogs` | h4: Blog | não | primary ×2, light ×2, soft-secondary ×1, success ×1, info ×1, warning ×1 | Blogs | Marketing > Blogs | Perfil da Marca, Novo artigo |
| `/blogs/create` | h5: Publicação | não | light ×4, success ×3, warning ×2, soft-primary ×1, danger ×1, soft-warning ×1, soft-secondary ×1 | Novo Artigo | Marketing > Blogs > Novo | Ajudar a escrever, Guardar |
| `/blogs/:id` | h5: Publicação | não | light ×4, success ×3, warning ×2, soft-primary ×1, danger ×1, soft-warning ×1, soft-secondary ×1 | Mesmo ecrã que `/blogs/create` | | |
| `/blogs/:id/show` | sem título | não | light ×1 | Smart Forfour Elétrico: O Carro Perfeito Para A Cidade | Marketing > Blogs > Pré-visualização | Voltar ao editor |
| `/suppliers` | h5: Fornecedores | não | soft-primary ×1, soft-success ×1, soft-secondary ×1, soft-danger ×1 | Fornecedores | Finanças > Fornecedores | Criação rápida, Novo fornecedor |
| `/customers` | h5: Clientes | não | soft-info ×1, soft-primary ×1, soft-success ×1, soft-secondary ×1, soft-danger ×1 | Clientes | Comercial > Clientes | Criação rápida, Novo cliente |
| `/document-templates` | h4: Modelos de documento | não | light ×4, secondary ×1, soft-danger ×1 | Modelos De Documento | Finanças > Modelos de documento | Exemplo .docx |
| `/expense-categories` | h5: Categorias de Despesa | não | soft-danger ×2, soft-primary ×1, soft-secondary ×1, soft-success ×1 | Categorias De Despesa | Finanças > Categorias de Despesa | Importar sugeridas, Nova categoria |
| `/expenses` | h5: Despesas | não | soft-success ×2, soft-secondary ×2, info ×1, danger ×1, light ×1, soft-warning ×1, soft-primary ×1, soft-danger ×1; 1 select nativo | Despesas | Finanças > Despesas | Nova despesa |
| `/support` | h4: Suporte | não | primary ×5, outline-primary ×2, light ×2 | Suporte | Equipa > Suporte | Lista Kanban, Novo pedido |
| `/orcamentos` | h4: Orçamentos | não | success ×2, warning ×1, info ×1, light ×1 | Orçamentos | Equipa > Orçamentos | sem ações |
| `/support/:id` | BreadCrumb: Pedido de suporte | sim | primary ×3, success ×1, outline-danger ×1, soft-primary ×1 | Arranque: Susana Freitas, Social Media | Equipa > Suporte > Pedido de suporte | sem ações |
| `/tasks` | h4: Tarefas | não | primary ×2, soft-primary ×1, light ×1; 1 select nativo | Tarefas | Equipa > Tarefas | Nova tarefa |
| `/trafego-site` | BreadCrumb: Tráfego do site | sim | primary ×4, outline-primary ×1, outline-secondary ×1 | Tráfego Do Site | Marketing > Tráfego do site | sem ações |
| `/meta-ads` | BreadCrumb: Meta / Anúncios | sim | primary ×4, light ×1, outline-primary ×1, soft-primary ×1 | Meta / Anúncios | Marketing > Meta / Anúncios | sem ações |
| `/admin` | h4: Administração do suporte | não | primary ×3, outline-primary ×2, soft-secondary ×1, light ×1, soft-primary ×1 | Tickets | Administração > Tickets | Lista Kanban |
| `/admin/tickets/:id` | BreadCrumb: Ticket | sim | primary ×5, success ×1, soft-primary ×1; 2 select nativo | #8 Arranque: Susana Freitas, Social Media | Administração > Tickets > #8 | sem ações |
| `/admin/quotes` | h4: Orçamentos | não | primary ×2, warning ×1, success ×1, danger ×1, soft-secondary ×1 | Orçamentos | Administração > Orçamentos | Catálogo de serviços, Novo orçamento |
| `/admin/charges` | h5: Cobranças | não | light ×5, success ×2, danger ×2, warning ×1, info ×1, soft-primary ×1, soft-success ×1, soft-warning ×1, soft-danger ×1 | Cobranças | Administração > Cobranças | Nova cobrança |
| `/admin/quotes/new` | BreadCrumb: isNew ?  | sim | soft-secondary ×3, primary ×2, success ×2, warning ×2, soft-primary ×2, soft-danger ×2, light ×2, info ×1, outline-danger ×1 | Novo Orçamento Rascunho | Administração > Orçamentos > Novo orçamento | Pré-visualizar PDF, Guardar rascunho, Marcar como enviado |
| `/admin/quotes/:id` | BreadCrumb: isNew ?  | sim | soft-secondary ×3, primary ×2, success ×2, warning ×2, soft-primary ×2, soft-danger ×2, light ×2, info ×1, outline-danger ×1 | Orc-2026-003 Versão 2 Aceite | Administração > Orçamentos > ORC-2026-003 | Pré-visualizar PDF |
| `/admin/service-catalog` | h4: Catálogo de serviços | não | primary ×2, light ×2, soft-secondary ×1, soft-danger ×1, soft-primary ×1 | Catálogo De Serviços | Administração > Orçamentos > Catálogo de serviços | Novo serviço |
| `/admin/stock` | h4: "Administração, Stock global" (com travessão) | não | soft-secondary ×2 | Stock Global | Administração > Stock global | sem ações |
| `/admin/creative-format-rules` | BreadCrumb: Regras de formato | sim | primary ×2, light ×2, success ×1, soft-primary ×1, soft-danger ×1 | Regras De Formato | Administração > Regras de formato | Nova regra |

## Fora do cabeçalho de aplicação

Estas rotas não usam o cabeçalho de página: páginas públicas (entrada, registo, links enviados a clientes), páginas de impressão e pré-visualizações. Seguem a hierarquia de botões, mas não têm `PageHeader`. As páginas de detalhe que precisam de dados que o ambiente de desenvolvimento não tem (fatura de restauração, ficha de cliente, detalhe de tarefa) foram convertidas, mas não verificadas no browser.

- `/editorial/aprovacao/:id/ver`
- `/restauracao/faturas/:id`
- `/companies/:companyId/cars/:id/print-sheet`
- `/companies/:companyId/cars/:id/documents/:docId`
- `/customers/:id`
- `/tasks/:id`
- `/oauth/meta/callback`
- `/privacy`
- `/install`
- `/r/:token`
- `/orcamento`
- `/aprovar`
- `/cobranca`
- `/admin/quotes/:id/preview/:version`
