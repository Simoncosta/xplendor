@component('mail::message')
# Fatura disponível

A XPLENDOR emitiu a fatura do seu pedido de **alteração ao site**.

- **Pedido:** {{ $ticketTitle }} (#{{ $ticketId }})

@component('mail::button', ['url' => $ticketUrl])
Ver o pedido e a fatura
@endcomponent

A fatura abre-se no pedido, em **Suporte**.

Obrigado,
{{ config('app.name') }}
@endcomponent
