@component('mail::message')
# Nova resposta num ticket

Um stand escreveu no ticket **#{{ $ticketId }}** — {{ $ticketTitle }}.

- **Empresa:** {{ $companyName }}
- **Escrito por:** {{ $authorName }}

> {{ $body }}

Obrigado,
{{ config('app.name') }}
@endcomponent
