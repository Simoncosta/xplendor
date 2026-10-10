# Noite: Ficheiros (gestor de ficheiros), o pedido

Pedido do Simon a 10 de outubro de 2026, guardado tal como foi feito.

NOITE: FICHEIROS (gestor de ficheiros). Corre sozinho até ao fim; eu não estou a acompanhar. Antes de começar, guarda este pedido em documents/NOITE-FICHEIROS.md.

REGRAS DA NOITE:
- No MAIN. Ignora CLAUDE.md antigo. NÃO registes em memória. Responde-me sempre em português de Portugal. Nos documentos e nos ecrãs, português de Portugal formal, sem travessões.
- Sem push nem deploy. Nada contra produção.
- Nenhuma chamada real a serviços externos: nem IA, nem Meta, nem PingWin, nem CoverManager, nem GA4, nem Cloudflare. Em dev, o R2 é o MinIO.
- Uma só sessão a construir: se encontrares alterações por gravar de outra pessoa, não as toques nem as incluas; adiciona sempre os ficheiros pelo caminho.
- Commit no fim de cada fase, com a suite completa (as falhas conhecidas sem aumento; se aparecer uma nova, corrige antes de seguir).
- Migrações só aditivas. Nenhum ficheiro existente é movido, copiado ou apagado: o catálogo só os regista.
- Respeita o template do Velzon (página File Manager, na pasta template/pages) e o documents/design-system.md, em modo claro e escuro, e no telemóvel.
- Dados pessoais: nas capturas e nos documentos, só dados fictícios (nada de fotografias reais de relatórios de satisfação nem de faturas reais).
- Uma decisão que não esteja aqui e não se desfaça facilmente não a tomes: escreve-a em documents/ficheiros/NOITE-PERGUNTAS.md e segue com o que não depende dela. As decisões pequenas e reversíveis, toma-as e regista-as no relatório.

P0. DEV SEM CHAMADAS AGENDADAS: em dev, as tarefas agendadas que chamam serviços externos (PingWin, CoverManager, Meta, GA4, IA) ficam desligadas por omissão, com uma variável do .env para as ligar. Em produção, nada muda. Com teste.

O QUE É: um sítio único com todos os ficheiros de cada empresa, a partir do template File Manager do Velzon, ligado nos dois sentidos à Linha Editorial.

DECISÕES:
1. Nome "Ficheiros". Módulo que o root liga por empresa; nasce desligado em todas as empresas (em dev, liga-o nas empresas de teste).
2. ACL: área nova "ficheiros" com ver, criar (enviar e criar pastas), editar (mudar o nome e mover) e apagar. Sugestões: Administrador tudo; Utilizador ver, criar e editar; Só leitura ver; Agência convidada ver e criar, sem apagar. A fotografia do varrimento só ganha as rotas novas; documenta a diferença.
3. Cada empresa vê só os seus ficheiros. O root vê todos, e os acessos do root aos ficheiros de outra empresa ficam registados (usa o registo que já existir; se não existir, cria um simples e diz isso no relatório).
4. Os ficheiros novos ficam no disco privado configurado (MEDIA_DISK ou PRIVATE_FILES_DISK; R2 em produção), abertos por endereços assinados. Contam no "Espaço por empresa" e na quota (a quota verifica-se antes do envio).
5. As pastas guardam-se na base de dados: mudar o nome ou mover não copia ficheiros.
6. Tipos: imagens, vídeos, PDF e documentos do Office. Programas, scripts e HTML bloqueados pelo conteúdo, não só pela extensão. O SVG nunca abre no browser como página. Nomes de ficheiro limpos (sem caminhos). Documentos até 50 MB; imagens e vídeos com os limites da Linha Editorial, e os vídeos grandes pelo envio em partes que já existe.
7. Reciclagem: o que se apaga fica 30 dias antes de desaparecer (tarefa agendada interna).
8. TODOS OS FICHEIROS DA EMPRESA aparecem nos Ficheiros, em pastas do sistema criadas automaticamente (ex.: Linha Editorial por mês, Faturas de fornecedores, Faturação XPLENDOR, Suporte, Relatórios de satisfação, Viaturas, Marca e logótipos), ao lado das pastas que as pessoas criam. Os ficheiros públicos (viaturas, logótipos, banners) ficam onde estão, no disco público: os Ficheiros mostram-nos e descarregam-nos a partir de lá.
9. CADA PASTA DO SISTEMA SEGUE A PERMISSÃO DA SUA ÁREA: os Ficheiros nunca abrem um ficheiro que a pessoa não pudesse abrir no módulo de origem (ex.: as faturas de fornecedores só com Finanças; a faturação da XPLENDOR só o Administrador; Viaturas só com a área automóvel; a Agência convidada não vê faturas). As pastas dos módulos desligados não aparecem.
10. Os ficheiros do sistema veem-se e descarregam-se, mas não se apagam nem se movem nos Ficheiros: tratam-se no seu módulo (as faturas têm prazos legais de conservação).
11. LINHA EDITORIAL NOS DOIS SENTIDOS:
    - a media enviada numa publicação aparece nos Ficheiros, na pasta da Linha Editorial do mês;
    - "Guardar numa pasta": uma media da Linha Editorial pode ser guardada numa pasta das pessoas sem ser copiada (aparece nas duas);
    - na publicação, "Escolher dos Ficheiros" usa uma imagem ou um vídeo de qualquer pasta a que a pessoa tenha acesso, sem copiar o ficheiro; as variantes (miniatura, pré-visualização, poster) geram-se uma vez, e o vídeo passa pelo mesmo processamento;
    - cada ficheiro mostra onde está a ser usado ("em uso em 2 publicações", com o link); um ficheiro em uso não se apaga;
    - a retenção automática da Linha Editorial nunca apaga um ficheiro guardado numa pasta das pessoas ou em uso.
12. Fica para mais tarde: links públicos de partilha fora da plataforma.

FASES:
F0 DESENHO (documents/ficheiros/DESENHO.md; não paras, segues para a F1):
   - mapa de todos os ficheiros que a plataforma guarda por empresa (disco, caminho, tabela, quem os pode ver, retenção, apagamento) e dos que ficam de fora, com o porquê (ex.: temporários);
   - o catálogo único: tabelas, como os ficheiros que já existem entram sem serem copiados, como se liga à Linha Editorial e às regras de retenção atuais;
   - a lista de pastas do sistema, com a permissão de cada uma;
   - o que pode correr mal;
   - PLANO R2-2 (só documento, sem código): inventário dos ficheiros públicos (tipo, quantidade e tamanho em dev, e o comando para medir em produção); quem os lê e onde estão guardados os URL (base de dados, site do stand, API pública, Meta); as duas opções de domínio (mudar o xplendor.tech para a Cloudflare, ou um domínio só para imagens); o comando de migração com simulação; como não partir links já publicados; como voltar atrás; as fatias. A decisão do domínio é minha.
F1: catálogo, pastas, envio, reciclagem, ACL e módulo, com testes de isolamento (uma empresa nunca vê ficheiros de outra), de permissões por pasta do sistema (incluindo "a Agência convidada não vê faturas") e de tipos bloqueados.
F2: a página Ficheiros a partir do template do Velzon (pastas, lista e grelha, pesquisa, pré-visualização, espaço usado, reciclagem) e a entrada no menu.
F3: as pastas do sistema, e o comando que regista no catálogo os ficheiros que já existem (simulação por omissão, sem copiar ficheiros; em dev podes correr com --execute).
F4: a Linha Editorial nos dois sentidos (ponto 11).
F5: verificação no ecrã com pessoas temporárias (apagadas no fim): administrador, utilizador, Só leitura, Agência convidada e root. Com os discos de dev apontados ao MinIO, para testar o caminho do R2 (repõe o .env de dev no fim). Capturas em documents/ficheiros/capturas/.

NO FIM: documents/ficheiros/RELATORIO-MANHA.md com o que ficou feito por fase, os commits, os testes, as decisões que tomaste, a diferença na fotografia do ACL, a lista de deploy dos Ficheiros (migrações, comandos, variáveis, o módulo desligado) e as perguntas.
