@component('mail::message')
@if($kind === 'approved')
# Empresa gerida aprovada

A empresa **{{ $companyName }}** foi criada e já é gerida por **{{ $agencyName }}**. Pode começar a trabalhar nela no seletor "A trabalhar em".
@elseif($kind === 'declined')
# Pedido recusado

O pedido de nova empresa gerida **{{ $companyName }}** não foi aprovado.
@else
# Pedido de nova empresa gerida

**{{ $agencyName }}** pediu uma nova empresa gerida: **{{ $companyName }}**.
@endif

@if($note)
**{{ $kind === 'new' ? 'Nota da agência' : 'Motivo' }}:** {{ $note }}

@endif
@component('mail::button', ['url' => $url])
{{ $kind === 'new' ? 'Aprovar ou recusar' : 'Abrir a XPLENDOR' }}
@endcomponent

{{ config('app.name') }}
@endcomponent
