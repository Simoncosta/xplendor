@component('mail::message')
# Orçamento {{ $number }} {{ $accepted ? 'aceite' : 'recusado' }}

A empresa **{{ $companyName }}** {{ $accepted ? 'aceitou' : 'recusou' }} o orçamento.

- **Orçamento:** {{ $title }}
@if($totalMonthly > 0)
- **Total mensal:** {{ number_format($totalMonthly, 2, ',', '.') }} €/mês
@endif
@if($totalOneOff > 0)
- **Total valor único:** {{ number_format($totalOneOff, 2, ',', '.') }} €
@endif

Valores sem IVA.

{{ config('app.name') }}
@endcomponent
