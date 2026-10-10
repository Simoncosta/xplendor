# Comparação dos modelos do OCR das faturas

Corrida a 10 de outubro de 2026, em dev, com autorização expressa. Comando: `php artisan ocr:compare --execute` (no contentor `worker`). Cada modelo leu cada fatura **sozinho, sem o QR** (o prompt completo de hoje), para medir o modelo. Nenhuma fatura gravada foi alterada; nenhum ficheiro de fatura está no git (a amostra está fora do repositório e os resultados brutos em `server/storage/app/private/ocr-compare/`).

## Limites da amostra (ler antes do resto)

- **4 faturas distintas**, todas **PDF com QR**: as 3 da pasta (MAKRO 24366, Forno Tradicional 379, Carnes Sá da Bandeira 1681) são, pelo conteúdo, as mesmas que já existiam em dev (faturas #7, #1 e #3), e a 4.ª é a fatura #6. Comparou-se pelo conteúdo, sem repetir.
- **Sem fotografias e sem faturas sem QR.** O resultado só vale para os PDFs com QR. São precisamente as fotografias e as faturas sem QR que mais separam os modelos: convém uma segunda volta com elas antes de decidir em definitivo.
- **Nenhuma fatura tem a revisão gravada.** Em vez dela, os campos do cabeçalho mediram-se contra o **QR da AT** (a verdade oficial, que os modelos não viram), e as linhas compararam-se entre os modelos.
- Formatos: 2 PDFs com camada de texto e 2 digitalizados; 1, 11, 19 e 82 linhas; 4 fornecedores diferentes.

## Medido contra o QR (a verdade oficial da AT)

Campos: NIF do emitente, número, data, total, base tributável, IVA total e IVA por taxa (7 campos × 4 faturas = 28). "Linhas conferem" = a soma das linhas por taxa bate com a base do QR por taxa (a mesma conferência da produção).

| Modelo | Campos certos | Número exatamente como no QR | Linhas conferem com o QR | Linhas diferentes da maioria | Tempo médio | Custo médio por fatura | Custo total |
|---|---|---|---|---|---|---|---|
| claude-fable-5-1 (esforço do modelo) | 28 de 28 | 3 de 4 | 3 de 4 | 0 | 42,6 s | 0,307 USD | 1,23 USD |
| claude-opus-5-5 (médio) | 28 de 28 | 3 de 4 | 3 de 4 | 2 (descrições) | 28,0 s | 0,110 USD | 0,44 USD |
| claude-opus-5-5 (alto) | 28 de 28 | 4 de 4 | 3 de 4 | 1 (descrição) | 28,9 s | 0,111 USD | 0,44 USD |
| gpt-6.1-sol (como hoje) | 28 de 28 | 4 de 4 | 3 de 4 | 1 (descrição) | 24,4 s | 0,036 USD | 0,14 USD |

- **Número da fatura #6:** o QR diz "1 2026/3"; o Fable e o Opus médio escreveram "Factura nº1 2026/3" (o mesmo número com o rótulo à frente). O NIF, a data e os valores estão certos em todos.
- **MAKRO (82 linhas lidas):** os quatro modelos leram **exatamente as mesmas 82 linhas**, com as mesmas somas, e nenhuma confere com o QR: a soma por taxa fica acima da base do QR nas três taxas (6%: +2,36 €; 13%: +9,00 €; 23%: +33,32 €). A fatura gravada em dev, lida na produção com o QR, tem 89 linhas e confere ao cêntimo: faltam cerca de 7 linhas de desconto ou promoção (valores negativos), que **nenhum** modelo leu sem o QR. É um limite da leitura sem o QR, igual em todos, não uma diferença entre modelos; na produção, a auto-conferência e a 2.ª tentativa pelo QR já o resolvem.
- **Linhas entre modelos:** em 113 linhas, só 2 discordâncias, ambas na descrição da MAKRO: a linha 13 com ou sem "Gadus morhua" (o Fable e o Opus alto incluíram-no; o Opus médio e o GPT não) e a linha 69 com "18°" ou "18º". Quantidades, preços unitários, taxas e totais das linhas iguais nos quatro.

## Tokens e tempo

A Anthropic recebe o PDF inteiro (texto e imagem de cada página): a MAKRO (5 páginas) custou 10 872 tokens de entrada em cada modelo da Anthropic, contra 15 844 no GPT (as imagens das páginas). A saída do Fable foi a maior (13 822 tokens na MAKRO, com o raciocínio) e é a mais cara por token (50 USD por milhão, contra 20 no Opus e 10 no GPT).

## Por fatura (gerado pelo comando)


| Fatura | Tipo | QR | claude-fable-5-1 (esforço do modelo) | claude-opus-5-5 (médio) | claude-opus-5-5 (alto) | gpt-6.1-sol (como hoje) |
|---|---|---|---|---|---|---|
| pasta: Carnes Sá da Bandeira 1681.pdf (= fatura #3) | PDF | sim | 11 linhas, 23,2 s, 0,1393 USD | 11 linhas, 10,4 s, 0,0442 USD | 11 linhas, 14,5 s, 0,0476 USD | 11 linhas, 13,3 s, 0,0190 USD |
| pasta: Forno Tradicional 379.pdf (= fatura #1) | PDF | sim | 19 linhas, 21,2 s, 0,2021 USD | 19 linhas, 14,4 s, 0,0744 USD | 19 linhas, 14,8 s, 0,0767 USD | 19 linhas, 15,6 s, 0,0274 USD |
| pasta: MAKRO 24366.pdf (= fatura #7) | PDF | sim | 82 linhas, 119,8 s, 0,7998 USD | 82 linhas, 83,2 s, 0,2870 USD | 82 linhas, 82,4 s, 0,2845 USD | 82 linhas, 63,9 s, 0,0870 USD |
| fatura #6 | PDF | sim | 1 linhas, 6,1 s, 0,0882 USD | 1 linhas, 3,9 s, 0,0353 USD | 1 linhas, 3,8 s, 0,0352 USD | 1 linhas, 4,8 s, 0,0112 USD |


## Os campos em que os modelos discordam (gerado pelo comando)

Só a fatura #6 tem discordâncias nos campos principais (o número, ver acima). Nas outras três, os quatro modelos leram o mesmo.
: os campos em que os modelos discordam


### pasta: Carnes Sá da Bandeira 1681.pdf (= fatura #3)

Todos os modelos leram o mesmo nos campos principais.

### pasta: Forno Tradicional 379.pdf (= fatura #1)

Todos os modelos leram o mesmo nos campos principais.

### pasta: MAKRO 24366.pdf (= fatura #7)

Todos os modelos leram o mesmo nos campos principais.

### fatura #6

| Campo | claude-fable-5-1 (esforço do modelo) | claude-opus-5-5 (médio) | claude-opus-5-5 (alto) | gpt-6.1-sol (como hoje) |
|---|---|---|---|---|
| Número | Factura nº1 2026/3 | Factura nº1 2026/3 | 1 2026/3 | 1 2026/3 |


## Um erro encontrado e corrigido nesta comparação

Na primeira corrida, a Anthropic recusou todos os pedidos: "too many parameters with union types (22; limit: 16)". O esquema da saída estruturada usava tipos anuláveis em 22 campos. Corrigido (`OcrSchemas`): sem tipos anuláveis, com os campos desconhecidos como opcionais (como o prompt já pedia, "omite-o"), 16 opcionais no esquema completo e 8 no das linhas, abaixo do limite de 24; há um teste que o garante. Sem esta correção, o OCR na Anthropic falhava em produção em todas as faturas. A primeira corrida não gerou texto na Anthropic (o pedido foi recusado antes) e custou cerca de 0,14 USD (só as 4 leituras do GPT).

**Custo real das duas corridas:** cerca de 2,40 USD (estimativa mostrada antes: 4,10 USD).

## Recomendação

**Nesta amostra, os quatro modelos acertam o mesmo**: todos os campos do cabeçalho certos contra o QR, as mesmas linhas, e a mesma falha na MAKRO (sem o QR). A diferença está no custo e no tempo:

- **gpt-6.1-sol (como hoje):** o mais barato (0,036 USD por fatura) e o mais rápido, sem perder exatidão nesta amostra.
- **claude-opus-5-5 (médio):** cerca de 3 vezes o custo do GPT (0,110 USD por fatura), a mesma exatidão. O esforço **alto** não acrescentou nada (o mesmo custo e tempo, uma diferença cosmética no número da fatura #6).
- **claude-fable-5-1:** cerca de 8,5 vezes o custo do GPT (0,307 USD por fatura) e o mais lento, **sem ganho de exatidão** nesta amostra. Não se justifica para o OCR, pelo menos nos PDFs com QR.

**Proposta:** manter o **gpt-6.1-sol** como reserva no `.env` e em Modelos de IA para os PDFs com QR, por custo. Se preferir um só fornecedor (a Anthropic) ou ler o PDF diretamente, o **claude-opus-5-5 com esforço médio** é a escolha, a cerca de 0,11 USD por fatura. Nota: hoje, os PDFs com texto vão ao `gpt-4o-mini` pelo texto extraído (a reserva `OCR_MODEL_TEXT`, ainda mais barata), um caminho que esta comparação não mediu (mediu o `gpt-6.1-sol` pelas imagens das páginas). **Antes de decidir em definitivo,** uma segunda volta com fotografias e faturas sem QR (onde os modelos mais se separam), a custo semelhante. A escolha é sua.
