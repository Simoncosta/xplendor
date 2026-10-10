# ACL: a fotografia do varrimento (antes)

Gravada na noite de 10 de outubro de 2026, com o código de antes do ACL (depois do hotfix F0, que não muda nenhum 403). Cada ator faz um pedido a cada rota de empresa (`/companies/{id}/…` e `/companies/{company}`), com registos reais na empresa (artigo de blog, colaborador, publicação, utilizador e viaturas) e um corpo que passa a validação de muitas rotas. Fica registado o estado HTTP de cada rota e a mensagem de cada 403.

- Ficheiros: `server/tests/Fixtures/acl/fotografia-antes/<ator>.json` (rotas com 403, mensagens e todos os estados).
- Teste: `server/tests/Feature/Access/AccessSnapshotTest.php` (compara; `ACL_SNAPSHOT=write` grava).
- É determinística: duas corridas seguidas dão o mesmo resultado.

| Ator | Rotas | Rotas com 403 |
|---|---|---|
| Administrador do cliente (`cliente_admin`) | 359 | 3 |
| Utilizador do cliente (`cliente_utilizador`) | 359 | 54 |
| Utilizador aprovador (`cliente_aprovador`) | 359 | 51 |
| Root na própria empresa (`root_propria`) | 359 | 9 |
| Root noutra empresa (`root_outra`) | 359 | 19 |
| Administrador da agência (`agencia_admin`) | 359 | 28 |
| Membro da agência (`agencia_membro`) | 359 | 52 |
| Membro da agência, numa empresa que a agência criou e ainda sem administrador (`agencia_membro_criou`) | 359 | 51 |
| Root a impersonar o administrador (`impersonacao_admin`) | 359 | 56 |
| Root a impersonar um utilizador (`impersonacao_utilizador`) | 359 | 57 |
| Administrador de uma empresa sem módulos (`sem_modulos_admin`) | 359 | 214 |

Os dois últimos atores (o root a impersonar um utilizador e o membro da agência numa empresa criada pela agência) foram acrescentados antes da F3, ainda com o código antigo. Em modo sombra (F1 e F2), os 11 atores deram zero divergências e zero inconclusivos. A fotografia depois da F3 está em `documents/acl/F3-DECISOES.md`.
