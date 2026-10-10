# Ficheiros: relatório da manhã

Noite de 10 de outubro de 2026. O pedido está em `documents/NOITE-FICHEIROS.md`.

**Estado:** tudo feito, de P0 a F5.
- Os commits estão na `main` local: sem push e sem deploy.
- Nada correu contra produção.
- Não houve chamadas a serviços externos. Em dev, o R2 foi o MinIO.

## 1. Trabalho por fase

| Fase | Commit | O que ficou | Testes novos |
|---|---|---|---|
| Pedido | `c939a37` | O pedido guardado tal como foi escrito. | |
| P0 | `50f0a55` | No dev, as tarefas agendadas que chamam serviços externos ficam desligadas. Liga-as a variável `SCHEDULE_EXTERNAL_JOBS`. Produção sem alteração (ligadas por omissão). | 4 |
| F0 | `7583236` | Desenho em `documents/ficheiros/DESENHO.md`: mapa dos ficheiros, catálogo, pastas do sistema, riscos e plano R2-2. | |
| F1 | `9798790` | Catálogo, pastas, envio, reciclagem, ACL e módulo (ver o ponto 1.1). | 9 |
| F2 | `9f41955` | A página Ficheiros (ver o ponto 1.2). | 3 (frontend) |
| F3 | `25610d1` | Pastas do sistema e registo dos ficheiros que já existem (ver o ponto 1.3). | 3 |
| F4 | `5fffb61` | A Linha Editorial nos dois sentidos (ver o ponto 1.4). | 4 |
| F5 | `1bd3ce3` | Verificação no ecrã com o MinIO e pessoas temporárias, três correções e 41 capturas (ver o ponto 1.5). | asserções novas |

### 1.1 F1: catálogo, pastas, envio, reciclagem, ACL e módulo

- **Tabelas:** `file_folders`, `stored_files` e `stored_file_shortcuts`. As pastas vivem só na base de dados.
- **Tipos e limites:**
  - tipos permitidos, com verificação pelo conteúdo;
  - os executáveis são recusados;
  - o SVG nunca abre como página;
  - documentos até 50 MB, a contar na quota.
- **Disco:** privado, com endereços assinados.
- **Reciclagem:** 30 dias.
- **ACL:** área `ficheiros` com ver, criar, editar e apagar.
- **Registo dos acessos do root** a outras empresas.

### 1.2 F2: a página Ficheiros

- Feita a partir do File Manager do Velzon, com três painéis: pastas, conteúdo e pré-visualização.
- Vista em lista (DataTable) ou em grelha, envio com progresso, reciclagem.
- Entrada "Ficheiros" no menu.

### 1.3 F3: pastas do sistema e registo

- **Pastas do sistema:** "Da plataforma", cada uma com a permissão da sua área.
- **Comando:** `files:catalog` (simulação por omissão).
- **Registo automático:** ao abrir a página e todas as noites.
- **Nenhum ficheiro é copiado nem movido.**

### 1.4 F4: a Linha Editorial nos dois sentidos

- **Da Linha Editorial para os Ficheiros:** a media das publicações aparece na pasta do mês, e "Guardar numa pasta" funciona sem cópia.
- **Dos Ficheiros para a Linha Editorial:** "Escolher dos Ficheiros" no editor da versão.
  - O original fica onde está; as variantes geram-se uma vez.
  - O ficheiro passa a mostrar onde está em uso e não se apaga enquanto estiver.

### 1.5 F5: verificação no ecrã

- Percurso completo com a media e os documentos no MinIO e com pessoas temporárias.
- Três correções (ver o ponto 4).
- 41 capturas.

### 1.6 Testes

- **Backend, suite completa:**
  - de 1822 para 1842 testes;
  - as 24 falhas conhecidas mantiveram-se em todas as fases, sem aumento.
  - Na F4 apareceu uma falha nova (`TenancyArchitectureTest`: um 403 fora do middleware das permissões). Foi corrigida antes do commit; a verificação do módulo passou para o `Access`.
- **Frontend:**
  - 81 testes a passar;
  - `App.test.tsx` é a falha conhecida;
  - TypeScript e ESLint sem erros.

## 2. Decisões tomadas (pequenas e reversíveis)

### 2.1 Permissões

1. A pasta "Faturas (OCR)" exige `restauracao.ver` e `financas.ver` (pergunta 2).
2. As pastas "Relatórios de satisfação" e "Faturação XPLENDOR" estão fechadas à agência gestora (pergunta 3).
3. Restaurar da reciclagem exige `ficheiros.apagar`: quem não pode apagar também não restaura.
4. O root só vê uma pasta do sistema se o respetivo módulo estiver ligado na empresa.
5. Os acessos do root a outras empresas ficam na tabela `audits` já existente (evento `ficheiros.acesso_root`), sem tabela nova.
6. "Escolher dos Ficheiros" (rota `POST {id}/editorial/media/from-file`):
   - pede `editorial.criar`, como o envio de media da Linha Editorial, mais o módulo "Ficheiros" e `ficheiros.ver`;
   - aceita só imagens e vídeos;
   - escolher o mesmo ficheiro duas vezes devolve a mesma media.

### 2.2 Tipos e quota

7. Documentos aceites:
   - PDF, Word, Excel e PowerPoint;
   - OpenDocument (texto, folha de cálculo, apresentação);
   - GIF e SVG. O SVG é sempre descarregado, nunca aberto como página.
8. Os documentos enviados contam na quota como "Ficheiros (documentos enviados)". As imagens e os vídeos enviados contam como media.

### 2.3 Registo e retenção

9. A pasta mensal da Linha Editorial é a do mês em que o ficheiro foi enviado.
10. As fotografias das contas ligadas (Meta) ficam fora do catálogo.
11. O registo só inclui ficheiros que existem no disco. Os registos sem ficheiro contam-se como "sem ficheiro". Em dev foram 1115, de dados antigos.
12. O registo ao abrir a página corre no máximo de 5 em 5 minutos por empresa. Corre também todas as noites às 04:40.
13. Um original que pertence a outro módulo (por exemplo, a imagem de uma viatura escolhida para uma publicação):
    - nunca é apagado pela retenção da media;
    - nunca é copiado pela migração do armazenamento;
    - as variantes geram-se no disco da media.

### 2.4 Ecrã

14. Nas pastas do sistema não há "Mudar o nome", "Mover" nem "Apagar". "Guardar numa pasta" cria um atalho, sem cópia.
15. Uma imagem fora da media (por exemplo, a de uma viatura) tem como miniatura o próprio ficheiro, até 3 MB.
16. A vista em lista é a predefinida. A escolha entre lista e grelha fica guardada por pessoa, no browser.

## 3. Diferença na fotografia da ACL

A comparação é entre antes da F1 (`50f0a55`) e agora.

**Rotas:** de 371 para 388. São as 16 rotas novas `{id}/files…` e a rota `editorial/media/from-file`. Nenhum ator perde nem ganha acesso a rotas que já existiam.

| Ator | 403 antes | 403 depois | Nas rotas novas |
|---|---|---|---|
| Administrador do cliente | 3 | 3 | nenhum 403 |
| Utilizador do cliente | 76 | 82 | 403 em apagar e restaurar (pastas, ficheiros, atalhos) |
| Utilizador aprovador | 70 | 76 | o mesmo que o utilizador |
| Root na própria empresa | 0 | 0 | nenhum 403 |
| Root noutra empresa | 21 | 21 | nenhum 403 |
| Administrador da agência | 36 | 44 | 403 em mudar o nome, mover, apagar e restaurar (o teto dá ver e criar) |
| Membro da agência | 60 | 68 | o mesmo que o administrador da agência |
| Membro da agência, empresa criada pela agência | 59 | 67 | o mesmo |
| Root a impersonar o administrador | 61 | 61 | nenhum 403 |
| Root a impersonar um utilizador | 70 | 76 | o mesmo que o utilizador |
| Administrador de uma empresa sem módulos | 225 | 242 | 403 nas 17 (módulo desligado) |

A rota `editorial/media/from-file` comporta-se como `editorial/media/uploads`, ator a ator.

O registo está em `documents/acl/F3-DECISOES.md`, secção 6c.

## 4. Verificação no ecrã (F5)

### 4.1 Como foi feita

- **Discos de dev apontados ao MinIO** (`MEDIA_DISK=r2` e `PRIVATE_FILES_DISK=r2`). No fim, o `.env` foi reposto (idêntico à cópia) e o worker reiniciado.
- **Empresa e pessoas temporárias, só com dados fictícios:**
  - a empresa "Stand Exemplo, Lda.";
  - Ana (administradora), Rui (utilizador), Marta (Só leitura), Joana (Agência convidada) e um root de teste;
  - uma viatura com imagens geradas, uma fatura de exemplo e documentos gerados.
- **No fim, foi tudo apagado:**
  - a empresa, as pessoas, os perfis e os tokens;
  - os objetos no MinIO e os ficheiros no disco público;
  - a pasta e os dois documentos de demonstração da F2.

### 4.2 O que se confirmou

- **Envio:** os documentos ficam no MinIO em `files/company_{id}/`, e a imagem é processada pelo worker.
- **Pré-visualização:** usa endereços assinados, redirecionados para o MinIO (PDF em linha, imagem pela variante).
- **"Escolher dos Ficheiros"** com a imagem da viatura:
  - no MinIO ficam só as variantes, sem cópia do original;
  - o original continua no disco público;
  - nos Ficheiros, a imagem passa a "Em uso em 1 publicação", com a ligação para a publicação.
- **Apagar:** um ficheiro em uso tem "Apagar" desativado, com o motivo. A reciclagem e o restauro funcionam.
- **Por pessoa:**
  - **Utilizador:** não vê a faturação da XPLENDOR e não apaga.
  - **Só leitura:** só descarrega e não vê "Viaturas" (pergunta 4).
  - **Agência convidada:** envia e cria pastas, mas não muda o nome, não move e não apaga; não vê a faturação.
  - **Root noutra empresa:** tudo exceto a faturação da XPLENDOR (regra "só do Administrador"). Os acessos ficaram registados.

### 4.3 Correções feitas na F5

1. **Miniaturas das imagens fora da media:** as imagens da viatura apareciam só com o ícone.
2. **Seletor "Escolher dos Ficheiros":** ao passar o rato, o cartão ficava azul e o tamanho ilegível. Passou ao estilo neutro das pastas.
3. **Pré-visualização abaixo de 1400 px:** não tinha deslocação e as ações ficavam cortadas ou por trás do botão flutuante do suporte.

### 4.4 Capturas

Estão em `documents/ficheiros/capturas/`, em modo claro e escuro, no computador e no telemóvel:
- **01 a 10:** o administrador (início, envio, lista, grelha, PDF, imagem, viatura, faturação, telemóvel);
- **11 a 15:** a Linha Editorial;
- **16 a 18:** apagar e a reciclagem;
- **19 e 20:** as outras pessoas;
- **21 e 22:** o telemóvel.

A pré-visualização do PDF (05) foi capturada com o browser visível, porque o modo sem janela não tem visualizador de PDF.

## 5. Lista para o deploy dos Ficheiros

1. **Código:** commits `50f0a55` a `1bd3ce3`. É preciso o build do frontend.
2. **Migrações**, só aditivas, por esta ordem:
   - `2026_12_28_100000_create_files_catalog`: as três tabelas novas, `original_disk` e `original_path` em `media_assets`, e `for_files` e `files_folder_id` em `media_uploads`;
   - `2026_12_28_100001_files_permissions`: a área "ficheiros" nos perfis de sistema e nas sugestões. É idempotente.
3. **Variáveis:**
   - `SCHEDULE_EXTERNAL_JOBS`: em produção não é preciso definir, porque fica ligada por omissão com `APP_ENV=production`;
   - os Ficheiros não têm variáveis novas: usam `PRIVATE_FILES_DISK` e `MEDIA_DISK`, que já existem.
4. **Tarefas agendadas novas:**
   - `files-purge-recycle` às 04:00: apaga de vez o que está na reciclagem há mais de 30 dias;
   - `files-catalog-nightly` às 04:40: só nas empresas com o módulo ligado.
5. **Módulo "Ficheiros":** desligado por omissão em todas as empresas. O root liga-o por empresa, na janela dos módulos (pergunta 5).
6. **Depois de ligar numa empresa (opcional):**
   - `php artisan files:catalog --company=ID` (simulação), para ver o que vai ser registado;
   - depois `--execute`. O primeiro abrir da página faz o mesmo.
7. **Worker das filas:** já existe. "Escolher dos Ficheiros" usa o processamento de media habitual.
8. **Voltar atrás:** desligar o módulo. Nada é movido nem copiado, e as migrações só acrescentam.

## 6. Perguntas

Estão em `documents/ficheiros/NOITE-PERGUNTAS.md`:
1. o domínio da R2-2 (A ou B);
2. a permissão da pasta das faturas do OCR;
3. a agência e as fotografias de satisfação;
4. o "Só leitura" e o stock;
5. em que empresas ligar primeiro o módulo.

## 7. Observações fora do âmbito

- **Cabeçalho:** debaixo do nome aparece sempre "Utilizador", também para administradores e root. É texto fixo em `web/src/Components/Common/ProfileDropdown.tsx`.
- **Visualizador de PDF do Chrome:** quando o ficheiro vem do R2, o título mostra o nome do objeto (um identificador) em vez do nome do ficheiro. Ao descarregar, o nome é o certo.
- **OCR, ponto 5:** continua à espera da sua escolha do modelo, como combinado. Não foi iniciado.
