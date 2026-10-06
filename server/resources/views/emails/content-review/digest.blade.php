@component('mail::message')
# Linha Editorial

Novidades desde o último resumo (aprovações, publicações e atrasos):

@foreach ($lines as $line)
- **{{ $line['company'] }}**{{ $line['severity'] === 'high' ? ' (urgente)' : '' }}: {{ $line['title'] }}. {{ $line['message'] }}
@endforeach

Os mesmos avisos estão no sino da XPLENDOR.

{{ config('app.name') }}
@endcomponent
