# Bússola do stand: ajustes antes do deploy

Escrito a 11 de outubro de 2026, com as respostas do Simon às perguntas da noite (`NOITE-PERGUNTAS.md`). Um commit por ponto, com a suite completa.

## 1. Gasto sem contactos (perguntas 1 e 8)

A regra das Recomendações `SpendWithoutLeadRule` e a jogada "Parar o anúncio" da Bússola usam a mesma condição. A chave interna da regra (`automotive_spend_without_lead`) ficou igual, porque as definições de cada empresa estão gravadas por ela.

| | Antes | Depois |
|---|---|---|
| Gasto que conta | todos os anúncios com a etiqueta do veículo, também os pausados | só os anúncios com a etiqueta **ainda ativos** |
| O que tira o veículo da regra | uma lead com origem paga | **qualquer contacto** nos mesmos 14 dias: uma lead de qualquer origem, ou um clique no WhatsApp, uma chamada, ou o telefone visto ou copiado |
| Título da recomendação | "Gasto sem lead" | "Gasto sem contactos" |
| Frase | "Os anúncios com a etiqueta desta viatura registaram 35,00 € de gasto nos últimos 14 dias e nenhuma lead com origem paga no mesmo período (limiar: 30 €)." | "Os anúncios ativos com a etiqueta desta viatura registaram 35,00 € de gasto nos últimos 14 dias e nenhum contacto no mesmo período (nem leads, nem cliques no WhatsApp, chamadas ou telefone visto) (limiar: 30 €)." |
| Na Bússola | "com gasto e sem leads pagas" | "com gasto e sem contactos" |

**Exemplo.** Um Peugeot 2008 teve 120 € de gasto nos últimos 14 dias, 4 cliques no WhatsApp e nenhuma lead.
- **Antes:** aparecia em "Gasto sem lead" e em "Parar o anúncio", porque não tinha leads pagas.
- **Depois:** já não aparece, porque teve contactos.

**Outro exemplo.** Um Golf com 38 € de gasto num anúncio entretanto pausado:
- **antes:** aparecia;
- **depois:** não aparece, porque o anúncio já não está ativo.

Os veículos vendidos continuam na regra "anúncio de viatura vendida ainda ativo", que não mudou.
