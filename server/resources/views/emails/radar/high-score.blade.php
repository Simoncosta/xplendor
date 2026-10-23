@component('mail::message')
# Está a subir: {{ $topic }}

{{ $recipientName ? "Olá, {$recipientName}." : 'Olá.' }}

O Radar encontrou um tema com nota alta para **{{ $companyName }}**.

{{ $why }}

Vale até {{ $validUntil }} (hora de Lisboa). O Radar nunca publica nem escreve o texto: "Reagir" cria uma ideia na Linha Editorial.

@component('mail::button', ['url' => $url])
Abrir a Bússola
@endcomponent

Recebe no máximo um aviso destes por dia. Pode desligá-los no cartão do Radar, na Bússola.

Obrigado,
{{ config('app.name') }}
@endcomponent
