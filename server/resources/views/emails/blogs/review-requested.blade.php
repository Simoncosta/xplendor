@component('mail::message')
# Artigo para rever

Foi enviado um artigo do blog para aprovação.

- **Empresa:** {{ $companyName }}
- **Enviado por:** {{ $authorName }}

**{{ $title }}**

Reveja o texto, o checklist de SEO e a data de publicação. Só um administrador da empresa pode aprovar.

@component('mail::button', ['url' => $url])
Rever o artigo
@endcomponent

Obrigado,
{{ config('app.name') }}
@endcomponent
