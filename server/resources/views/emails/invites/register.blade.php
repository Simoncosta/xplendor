@component('mail::message')
# Convite para se registar

Foi convidado para aceder à empresa **{{ $companyName }}**.

Clique no botão abaixo para criar a sua conta:

@component('mail::button', ['url' => $inviteUrl])
Criar Conta
@endcomponent

Se não solicitou este acesso, ignore este e-mail.

⏳ Este convite expira em 7 dias.
Obrigado,<br>
Equipa {{ config('app.name') }}
@endcomponent