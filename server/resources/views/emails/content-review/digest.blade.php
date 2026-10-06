@component('mail::message')
# Aprovações de conteúdos

Novidades nos links de aprovação desde o último resumo:

@foreach ($lines as $line)
- **{{ $line['company'] }}**{{ $line['severity'] === 'high' ? ' (urgente)' : '' }}: {{ $line['title'] }}. {{ $line['message'] }}
@endforeach

Os mesmos avisos estão no sino da XPLENDOR.

{{ config('app.name') }}
@endcomponent
