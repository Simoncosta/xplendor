@component('mail::message')
@if($kind === 'new')
# Nova fatura da XPLENDOR

A XPLENDOR enviou-lhe uma fatura.
@elseif($kind === 'paid')
# Pagamento confirmado

Confirmámos o pagamento desta fatura. Obrigado.
@elseif($kind === 'refused')
# Pagamento ainda não confirmado

Ainda não conseguimos confirmar o pagamento desta fatura.
@else
# {{ $overdue ? 'Fatura vencida' : 'A fatura vence hoje' }}

{{ $overdue ? 'Lembramos que esta fatura da XPLENDOR está vencida.' : 'Lembramos que esta fatura da XPLENDOR vence hoje.' }}
@endif

- **Empresa:** {{ $companyName }}
- **Descrição:** {{ $description }}
- **Valor:** {{ number_format($amount, 2, ',', '.') }} €
- **Vencimento:** {{ $dueDate }}

@if($note)
**Nota da XPLENDOR:** {{ $note }}

@endif
@component('mail::button', ['url' => $url])
{{ $kind === 'paid' ? 'Ver a fatura' : 'Ver a fatura e indicar o pagamento' }}
@endcomponent

@if($kind !== 'paid')
Depois de pagar, carregue em **"Já paguei"** na mesma página (pode juntar o comprovativo). Os lembretes param de imediato.
@endif

Se já pagou, ignore esta mensagem.

Com os melhores cumprimentos,
{{ config('app.name') }}
@endcomponent
