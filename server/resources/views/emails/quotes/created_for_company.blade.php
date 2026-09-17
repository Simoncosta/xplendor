@component('mail::message')
# Orçamento novo para aprovar

A XPLENDOR enviou-te um orçamento para o teu stand.

- **Descrição:** {{ $description }}
- **Valor:** {{ number_format($amount, 2, ',', '.') }}€ _(acresce IVA à taxa legal)_

Entra no XPLENDOR, em **Orçamentos**, para aprovar ou rejeitar. Nenhum trabalho começa sem a tua aprovação e pagamento.

Obrigado,
{{ config('app.name') }}
@endcomponent
