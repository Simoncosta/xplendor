@component('mail::message')
# Novo ticket de suporte

Um stand abriu um novo pedido no XPLENDOR.

- **Empresa:** {{ $companyName }}
- **Aberto por:** {{ $authorName }}
- **Tipo:** {{ $typeLabel }}
- **Ticket:** #{{ $ticketId }}

**{{ $title }}**

{{ $description }}

@if($hasScreenshot)
_Tem um print anexado (visível no painel de administração)._
@endif

Obrigado,
{{ config('app.name') }}
@endcomponent
