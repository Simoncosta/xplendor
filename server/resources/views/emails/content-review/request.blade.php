@component('mail::message')
# {{ $reminder ? 'Publicações à espera de aprovação' : 'Publicações para aprovar' }}

{{ $recipientName ? "Olá, {$recipientName}." : 'Olá.' }}

@if ($reminder)
Ainda há {{ $pendingCount }} {{ $pendingCount === 1 ? 'publicação' : 'publicações' }} de **{{ $companyName }}** à espera da sua decisão ({{ $title }}).
@else
Preparámos {{ $pendingCount }} {{ $pendingCount === 1 ? 'publicação' : 'publicações' }} de **{{ $companyName }}** para aprovar ({{ $title }}).
@endif

Em cada uma pode ver como fica na rede, aprovar ou pedir alterações. Não precisa de conta.

@component('mail::button', ['url' => $url])
Ver e aprovar
@endcomponent

O link é válido até {{ $expiresOn }}. Não o reencaminhe: quem o tiver pode aprovar em seu nome.

Obrigado,
{{ config('app.name') }}
@endcomponent
