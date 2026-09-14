@component('mail::message')
# Orçamento {{ $approved ? 'aprovado' : 'rejeitado' }}

@if($approved)
O stand **aprovou** o orçamento — podes faturar e avançar com o trabalho.
@else
O stand **rejeitou** o orçamento. O pedido foi fechado (sem renegociação).
@endif

- **Empresa:** {{ $companyName }}
- **Decidido por:** {{ $authorName }}
- **Pedido:** {{ $ticketTitle }} (#{{ $ticketId }})
- **Valor:** {{ number_format($quotedAmount, 2, ',', '.') }}€ _(acresce IVA à taxa legal)_

@if($approved)
No painel de administração podes anexar a fatura, marcar como pago e, no fim, concluir.
@endif

Obrigado,
{{ config('app.name') }}
@endcomponent
