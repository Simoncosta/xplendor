@component('mail::message')
# Orçamento {{ $number }} para decidir

A XPLENDOR enviou-lhe um orçamento.

- **Orçamento:** {{ $title }}
@if($totalMonthly > 0)
- **Total mensal:** {{ number_format($totalMonthly, 2, ',', '.') }} €/mês
@endif
@if($totalOneOff > 0)
- **Total valor único:** {{ number_format($totalOneOff, 2, ',', '.') }} €
@endif
@if($validUntil)
- **Válido até:** {{ $validUntil }}
@endif

Acresce IVA à taxa legal em vigor.

Entre no XPLENDOR, em **Orçamentos**, para aceitar ou recusar. Nenhum trabalho começa sem a sua aceitação.

Com os melhores cumprimentos,
{{ config('app.name') }}
@endcomponent
