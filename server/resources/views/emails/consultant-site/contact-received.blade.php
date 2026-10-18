@component('mail::message')
# Novo contacto do site

Chegou um pedido pelo site.

- **Empresa:** {{ $companyName }}
- **Pedido:** {{ $summary }}

Os dados de contacto estão na XPLENDOR, para proteger os dados pessoais de quem escreveu.

@component('mail::button', ['url' => $url])
Ver o contacto
@endcomponent

Obrigado,
{{ config('app.name') }}
@endcomponent
