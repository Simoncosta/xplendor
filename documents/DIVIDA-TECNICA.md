# Dívida técnica

Registo de dívida técnica conhecida, com o contexto para a resolver mais tarde.

## Comparação mensal duplicada (restauração e automóvel)

- **Onde:** `server/app/Services/RestaurantMarketingService.php` tem a sua própria cópia das regras de comparação mensal (janelas, escalões, deltas, grupos GA4). O bloco "Marketing e resultados" do automóvel usa a classe partilhada `server/app/Support/MonthComparison.php`, com as mesmas regras.
- **Porque ficou assim:** a camada do automóvel foi feita sem tocar na restauração.
- **O que fazer:** a restauração passar a usar `MonthComparison` e apagar os métodos privados duplicados (`window`, `firstTier`, `compare`, `noComparison`, `groupChannels`, `dates`, `seriesFrom`). Os testes de `RestaurantMarketingTest` têm de continuar a passar sem alterações.
- **Registado em:** outubro de 2026.
