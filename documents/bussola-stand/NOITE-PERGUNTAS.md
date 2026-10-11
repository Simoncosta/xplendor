# Bússola do stand: perguntas da noite

Decisões de produto que não estavam no pedido. O trabalho seguiu com o valor indicado em "Até decidir", que se muda sem obras grandes.

## 1. "Gasto sem contactos" ou "gasto sem leads"?

O pedido diz "gasto sem contactos (as regras da 2D)". A regra da 2D (`SpendWithoutLeadRule`) conta **leads pagas**, não contactos:
- gasto com a etiqueta de pelo menos 30 euros em 14 dias;
- 0 leads com origem paga.

Um veículo com WhatsApps e chamadas, mas sem leads, entra na regra.

**Até decidir:** a regra da 2D, tal como está, para a Bússola e as Recomendações dizerem o mesmo. O texto da jogada diz "sem leads pagas".

**Resposta (com a 8): "gasto sem contactos", igual na Bússola e nas Recomendações.**
- Só entram os anúncios ainda ativos.
- Conta como resultado qualquer contacto nos mesmos 14 dias: leads de qualquer origem e interações (WhatsApp, chamada, ver telefone).
- Feito no pré-deploy (`AJUSTES-PRE-DEPLOY.md`, ponto 1).

## 2. Custo por contacto

Não existe hoje um "custo por contacto". Só existe o custo por lead paga. Além disso, as interações (WhatsApp, chamada, ver telefone) não guardam se vieram de um anúncio.

**Até decidir:** o gasto das 4 semanas a dividir pelos contactos (de todas as origens) dos veículos com gasto, nas mesmas 4 semanas.

**Alternativa:** custo por lead paga, que já existe e tem a origem.

## 3. Caravanas e motos no seletor

O seletor do pedido é "Todos, Carros, Autocaravanas".

**Até decidir:**
- as caravanas contam como "Autocaravanas", porque têm o mesmo limiar de 120 dias;
- as motos (nenhuma em dev) só contam em "Todos".

## 4. Onde ficam "As 3 jogadas da semana" no dashboard

O pedido diz "no FIM do separador Marketing e resultados, junto às recomendações". No dashboard do stand, as Recomendações estão no separador **Stock**.

**Até decidir:** no fim do separador "Marketing e resultados", como diz a instrução explícita.

**Alternativa:** no separador Stock, logo a seguir às Recomendações.

## 5. "Sem publicação nos últimos 30 dias"

As publicações da Linha Editorial não sabem a que veículo se referem.

**Até decidir:** contam só as publicações criadas a partir da Bússola (ficam ligadas ao veículo).

**Mais tarde:** um campo "veículo" na publicação da Linha Editorial, para ligar também as criadas à mão.

## 6. Empresa com restauração e stand

Uma empresa com os módulos PingWin e stock teria as duas Bússolas.

**Até decidir:** a página mostra a da restauração e, por baixo do título, um seletor "Restauração / Stand"; o dashboard de cada ramo mostra a sua. Em dev não há nenhuma empresa assim.

## 7. A área de permissão "Bússola" no editor de perfis

A área `bussola` está ligada ao módulo PingWin. Numa empresa só de stand, o editor de perfis avisa "Bússola: módulo inativo" ao dar a permissão, o que deixa de ser verdade.

**Até decidir:** fica como está. O aviso é só informativo e não muda nenhum acesso, porque o acesso usa o módulo de cada rota.

**Para corrigir:** a área passa a valer para os dois módulos. Isto muda o catálogo das áreas que o editor de perfis recebe.

## 8. "Parar o anúncio" quando o anúncio já está pausado

A regra da 2D do gasto sem leads (`SpendWithoutLeadRule`) conta o gasto dos últimos 14 dias sem olhar ao estado do anúncio. Na verificação no ecrã, um veículo com o anúncio já pausado (mas com gasto nos 14 dias) entrou em "Parar o anúncio".

**Até decidir:** a regra tal como está, igual nas Recomendações.

**Alternativa:** a Bússola, e a regra, só contam os anúncios ainda ativos.

**Resposta:** só os anúncios ainda ativos (ver a resposta da pergunta 1).
