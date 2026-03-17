@component('mail::message')
# 🚗 Novo lead recebido

**Viatura:** {{ $carName }}

---

## 👤 Cliente
- Nome: {{ $customerName }}
- Telefone: {{ $customerPhone }}
- Email: {{ $customerEmail }}

@if($message)
---

## 💬 Mensagem
{{ $message }}
@endif

---

@component('mail::button', ['url' => 'tel:' . $customerPhone])
Ligar agora
@endcomponent

@component('mail::button', ['url' => 'https://wa.me/' . preg_replace('/\D/', '', $customerPhone)])
Enviar WhatsApp
@endcomponent

Obrigado,  
{{ config('app.name') }}
@endcomponent