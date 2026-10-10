# Ficheiros: perguntas da noite

Decisões que não estavam no pedido e que não se desfazem facilmente, ou que são suas. O trabalho seguiu com o que não dependia delas; onde foi preciso um valor, ficou o indicado em "Até decidir".

**Todas respondidas pelo Simon** no pré-deploy dos Ficheiros (resposta em cada ponto).

## 1. Plano R2-2: o domínio dos ficheiros públicos

O desenho está em `DESENHO.md`, secção 5.

- **A.** Pôr o `xplendor.tech` na Cloudflare. Os URL não mudam, mas todo o domínio passa pela Cloudflare.
- **B.** Um domínio só para imagens (por exemplo, `img.xplendor.tech`), com o nginx a manter `/storage/*` (a servir do disco ou com redirecionamento 301).

**Recomendação técnica:** B (menos risco e reversível).

**Até decidir:** nada foi feito. A R2-2 é só documento.

**Resposta: opção B, com um domínio próprio para as imagens**, comprado à parte e todo na Cloudflare.
- Um subdomínio do `xplendor.tech` (como o `img.xplendor.tech` do exemplo) só funciona com a opção A, porque o domínio de um bucket do R2 tem de estar na Cloudflare.
- O `DESENHO.md` foi corrigido (secção 5.3).
- Nada a construir agora.

## 2. A pasta "Faturas (OCR)": que permissão?

Hoje a pasta exige `restauracao.ver` e `financas.ver`, porque as faturas dos fornecedores são informação financeira.

**Alternativa:** só `restauracao.ver`, como o ecrã das faturas da Restauração.

**Até decidir:** as duas permissões. A regra está numa linha de `App\Services\Files\SystemFolders`.

**Resposta:** ficam as duas permissões. Nada a mudar.

## 3. A agência gestora e as fotografias dos relatórios de satisfação

A pasta "Relatórios de satisfação" (e a "Faturação XPLENDOR") está fechada à agência gestora, mesmo que o perfil dela tenha `automovel.ver`. As fotografias podem mostrar clientes e matrículas.

**Pergunta:** a agência deve vê-las?

**Até decidir:** fechada (`no_agency`).

**Resposta:** continuam fechadas à agência. Nada a mudar.

## 4. O perfil "Só leitura" e a pasta "Viaturas"

A sugestão "Só leitura" (decisão D9 da ACL) não tem `automovel.ver`. Por isso, nos Ficheiros, esta pessoa não vê a pasta "Viaturas", tal como não vê o stock. Está coerente com a regra de cada pasta seguir a sua área.

**Pergunta:** a sugestão "Só leitura" deve passar a ver o stock?

Isto mexe na ACL fora dos Ficheiros, por isso não foi alterado.

**Resposta:** fica como está.

**Para mais tarde:** separar a área `automovel` em duas, "stock" e "contactos". Assim, um perfil pode ver o stock (e a pasta "Viaturas") sem ver os contactos dos clientes.

## 5. Por onde começar em produção

O módulo "Ficheiros" fica desligado em todas as empresas.

**Pergunta:** em que empresas o ligar primeiro, e quando?

Ao ligar, o primeiro abrir da página regista os ficheiros que a empresa já tem. Também se pode correr `files:catalog` antes, como se indica no relatório.

**Resposta:** liga-se primeiro na XPLENDOR, em produção, pelo Simon. Os passos estão em `DEPLOY-FICHEIROS.md`.
