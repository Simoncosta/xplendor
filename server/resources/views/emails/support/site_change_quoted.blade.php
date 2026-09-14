@component('mail::message')
# Orçamento para aprovar

A XPLENDOR enviou o orçamento do teu pedido de **alteração ao site**.

- **Pedido:** {{ $ticketTitle }} (#{{ $ticketId }})
- **Estimativa:** {{ rtrim(rtrim(number_format($estimatedHours, 2, ',', '.'), '0'), ',') }}h × {{ number_format($hourlyRate, 0, ',', '.') }}€
- **Total:** {{ number_format($quotedAmount, 2, ',', '.') }}€ _(acresce IVA à taxa legal)_

Entra no XPLENDOR, em **Suporte**, para aprovar ou rejeitar. Nenhum trabalho começa sem a tua aprovação e pagamento.

Obrigado,
{{ config('app.name') }}
@endcomponent
