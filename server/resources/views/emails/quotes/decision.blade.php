@component('mail::message')
# Orçamento {{ $approved ? 'aprovado' : 'rejeitado' }}

@if($approved)
A empresa **aprovou** o orçamento — podes faturar e marcar como pago.
@else
A empresa **rejeitou** o orçamento.
@endif

- **Empresa:** {{ $companyName }}
- **Descrição:** {{ $description }}
- **Valor:** {{ number_format($amount, 2, ',', '.') }}€ _(acresce IVA à taxa legal)_

Obrigado,
{{ config('app.name') }}
@endcomponent
