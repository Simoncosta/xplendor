@component('mail::message')
# Pagamento indicado

**{{ $companyName }}** indicou que pagou uma cobrança ({{ $via === 'link' ? 'pelo link do email' : 'na plataforma' }}).

- **Descrição:** {{ $description }}
- **Valor:** {{ number_format($amount, 2, ',', '.') }} €
- **Vencimento:** {{ $dueDate }}
- **Comprovativo:** {{ $hasProof ? 'sim' : 'não' }}
@if($note)
- **Nota:** {{ $note }}
@endif

Os lembretes pararam. Confirme o pagamento ou recuse com uma nota em **Administração › Cobranças**.

@component('mail::button', ['url' => $adminUrl])
Abrir Cobranças
@endcomponent

{{ config('app.name') }}
@endcomponent
