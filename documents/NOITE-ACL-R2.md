MODO NOITE: HOTFIX DE ISOLAMENTO, ACL (backend e ecrã) e R2. Eu revejo tudo amanhã de manhã. No MAIN. Ignora CLAUDE.md antigo. NÃO registes em memória. Sem deploy.

PASSO 0: desliga o interruptor da Yuko EM DEV (pingwin:item-sales-switch 5 off). A produção é a única a ler o PingWin da Yuko.

PRIMEIRO: grava este pedido em documents/NOITE-ACL-R2.md e faz commit. Segue o teu spike do ACL como desenho aprovado, com as decisões abaixo; grava-o também em documents/ACL-DESENHO.md (com as decisões) se ainda não estiver no repositório. Se o contexto for resumido a meio da noite, volta a ler estes dois ficheiros.

ATENÇÃO: pode haver outra sessão a ler o repositório (só leitura). Não mexas em nada fora destas tarefas.

REGRAS (não negociáveis):
- NENHUMA chamada real à Meta, à Cloudflare, ao PingWin, ao CoverManager, ao GA4 nem a fornecedores de IA. Testes sem chaves reais.
- Nada contra produção. Sem migrações não aditivas.
- Um commit por fase, com os testes a passar (as 24 falhas conhecidas sem aumento).
- Se uma fase do ACL não ficar verde: PARA o ACL, documenta onde ficou, e passa ao R2. Se o R2 não ficar verde: para e documenta.
- Nunca inventar uma decisão de produto. Na dúvida: documents/NOITE-PERGUNTAS.md, e segue no que não depende dela. As decisões técnicas por omissão ficam documentadas para eu confirmar.
- Português de Portugal formal, sem travessões. Ecrãs: documents/design-system.md e componentes do Velzon.

PARTE 1: F0, HOTFIX DE ISOLAMENTO (autorizado)
- ExpenseRequest: expense_category_id, supplier_id e car_id só aceitam IDs da empresa do endereço; o ExpenseService volta a verificar.
- CarRequest: seller_user_id só aceita utilizadores da empresa; o CarService não carrega dados de vendedores de outra empresa.
- Procurar e corrigir outros exists: simples sobre tabelas com company_id que deixem ler ou associar dados de outra empresa; listar os que encontrares.
- Testes: IDs de outra empresa dão 422, para cada campo. Commit.

PARTE 2: ACL (fases F1 a F5 do spike), com estas DECISÕES:
D1. O root passa em todas as permissões, EXCETO as decisões do cliente (aprovar conteúdos, aceitar orçamentos, aceitar ou terminar a gestão), que exigem uma pessoa do próprio cliente, como hoje.
D2. Agência: o perfil do utilizador na agência ∩ o teto do cliente.
D3. Tabela profile_permissions (não JSON).
D4. Um utilizador, uma empresa (mantém-se).
D5. Blog e marca como áreas base, sem módulo.
D6. A aprovação do blog passa a blog.aprovar (em F3).
D7. integracoes.configurar só no Administrador por omissão (em F3, como decisão explícita, depois da migração de compatibilidade).
D8. Os 11 módulos que hoje só escondem o menu passam a ser verificados no backend (em F3).
D9. "Só leitura" NÃO vê as Finanças, a faturação da Xplendor, os utilizadores, as integrações nem o back-office da restauração (lojas, artigos, documentos). Vê a empresa, a Linha Editorial, o blog, a marca, a Bússola, os resultados e o suporte.
D10. forCompany() explícito, sem global scopes (a F6 NÃO entra esta noite).
D11. O criativo externo só vê os clientes atribuídos, mesmo com team_scope=all.
D12. SEMPRE PELO MENOS UM ADMINISTRADOR por empresa (clientes e agências): não se pode apagar, desativar, retirar o perfil nem mudar para outro perfil o último administrador ativo; o ecrã explica ("Nomeie outro administrador antes de…"). O perfil Administrador é de sistema e não se edita. Testes.
D13. PERFIS PERSONALIZADOS COM SUGESTÕES: "Novo perfil" deixa partir de uma sugestão (Marketing, Financeiro, Só leitura, Agência convidada) ou de um perfil vazio. Antes de criar, mostra em linguagem simples o que a sugestão dá, por área ("Pode criar e editar publicações; não vê as Finanças"), tudo editável; a sugestão nunca é imposta. Antes de gravar, mostra as permissões efetivas.
D14. MEDIA TAILORS NA YUKO: entra como utilizadores da Yuko com o perfil sugerido "Agência convidada" do lado do cliente (Linha Editorial ver, criar, editar e apagar; Bússola ver, criar e editar; resultados ver; SEM aprovar, Finanças, utilizadores, integrações nem restauração). A XPLENDOR continua a ser a agência gestora da Yuko. Nada disto se configura em produção esta noite.
Fases: F1 (Access, catálogo e permission: em todas as rotas, em modo sombra; a fotografia do varrimento), F2 (migração para os perfis de compatibilidade; a fotografia igual antes e depois), F3 (bloqueio; um só 403 com motivo; D6, D7 e D8 como decisões explícitas, uma a uma; teste de arquitetura sem role === e com as subpastas), F4 (ecrã: /my-access, useCan, menu e rotas a falhar fechados, motivos do backend), F5 (ecrã Utilizadores › Perfis com D12 e D13, e o teto da agência no cartão da agência gestora). Critério de "feito" de cada fase: o do spike.

PARTE 3: R2 (Cloudflare R2, compatível com S3)
1. Inventário: todos os discos e ficheiros guardados hoje (Linha Editorial, miniaturas, PDFs das cobranças e comprovativos, faturas do OCR, logótipos, fotografias da equipa, imagens do blog): onde estão, quanto pesam em dev, quem os lê.
2. O disco "media" e os outros ficheiros privados que crescem (cobranças, comprovativos, faturas) passam a poder estar no R2 por configuração (.env), com o disco local como valor por omissão. Bucket PRIVADO; acesso sempre por endereços assinados de curta duração, gerados pelo backend com a verificação de empresa (e, a partir de agora, a permissão do ACL).
3. Tudo continua a funcionar com o R2: envio em partes dos vídeos, ffmpeg e miniaturas (o worker trabalha numa cópia temporária), pré-visualizações, retenção (30 dias e 12 meses), quotas por empresa, apagamento definitivo aos 90 dias.
4. Comando de migração dos ficheiros existentes: simulação por omissão, retomável, idempotente; confirma cada ficheiro (tamanho e checksum) e NÃO apaga o local. O apagamento do local é um comando à parte.
5. Dev: um contentor MinIO no docker-compose de dev (nunca em produção) para testar de ponta a ponta; testes automáticos com o disco simulado.
6. Para a F2 da Meta: confirmar na documentação se um endereço assinado de 1 hora chega para a Meta ir buscar a imagem ou o vídeo, e deixar o método pronto.
7. Entregar: as variáveis do .env de produção, como criar o bucket e uma chave na Cloudflare com permissão só para esse bucket, e os passos do deploy e da migração.

VERIFICAÇÃO NO ECRÃ (ACL e R2): computador e telemóvel, claro e escuro. Para o ACL, entrar com um utilizador de cada perfil (Administrador, Marketing, Financeiro, Só leitura, Agência convidada, membro de agência) e confirmar o que cada um vê. Capturas em documents/acl/capturas/, sem dados pessoais.

NO FIM: documents/RELATORIO-MANHA-ACL-R2.md com:
1. O que ficou feito em cada fase, com o commit (e onde parou, se parou).
2. Os testes, a fotografia do varrimento antes e depois, e as capturas.
3. As decisões técnicas tomadas por omissão, para eu confirmar.
4. As perguntas pendentes.
5. O que muda para os utilizadores atuais quando isto for para produção (por exemplo, quem deixa de poder ligar integrações).
6. Os meus passos para amanhã: Cloudflare (bucket e chave), .env, deploy e migração dos ficheiros.