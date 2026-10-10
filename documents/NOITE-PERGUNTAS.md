# Noite ACL e R2: perguntas pendentes

Cada pergunta tem a decisão que tomei por omissão para não parar o trabalho. Nada disto foi para produção.

## 1. D1 diz "como hoje", mas hoje o root fazia algumas decisões do cliente

Hoje o root:
- aceitava ou recusava orçamentos noutras empresas (`PATCH quotes/{quote}/decision`, `POST support-tickets/quotes/approve`);
- aprovava artigos do blog na própria empresa;
- indicava o pagamento de uma cobrança da XPLENDOR em nome do cliente (`POST xplendor-charges/{id}/paid`).

**Por omissão:** apliquei a D1 tal como está escrita: as decisões do cliente (aprovar conteúdos, aceitar orçamentos, aceitar ou terminar a gestão) exigem uma pessoa do próprio cliente, e o root deixa de as tomar, mesmo na própria empresa. O root continua a marcar as cobranças como pagas no painel `/admin` (essa rota não mudou).

**Pergunta:** confirma? Se o root tiver de continuar a aceitar orçamentos em nome do cliente, basta tirar `faturacao_xplendor.aprovar` da lista `Permissions::CLIENT_DECISIONS`.

## 2. D9: o "Só leitura" vê o dashboard de vendas da restauração e a área automóvel?

A D9 lista o que o "Só leitura" vê (a empresa, a Linha Editorial, o blog, a marca, a Bússola, os resultados e o suporte) e o que não vê (Finanças, faturação da XPLENDOR, utilizadores, integrações e o back-office da restauração: lojas, artigos, documentos). Ficam duas áreas por decidir:
- o **dashboard de vendas da restauração** (faturação por período, calendário de faturação), que no catálogo é `restauracao.ver`;
- a **área automóvel** (stock, leads, viaturas).

**Por omissão:** o "Só leitura" não vê nenhuma das duas (o mais restrito). A Bússola e o separador "Marketing e resultados" continuam visíveis (`bussola.ver` e `resultados.ver`).

**Pergunta:** o "Só leitura" deve ver o dashboard de vendas da restauração e o stock automóvel? Muda-se na sugestão "Só leitura" (`app/Access/ProfileSuggestions.php`), sem código novo.

## 3. O botão flutuante do suporte numa empresa sem o módulo "Suporte / Tarefas"

Com a D8, as rotas do suporte passam a exigir o módulo `support_tasks`. O botão flutuante do suporte aparece hoje em todas as empresas.

**Por omissão:** no ecrã (F4), o botão só aparece quando a pessoa pode ver o suporte (módulo ativo e `suporte.ver`).

**Pergunta:** há empresas em produção sem o módulo "Suporte / Tarefas" que usem o suporte? Se sim, liga-se o módulo nessas empresas antes do deploy.
