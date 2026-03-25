@component('mail::message')
# Mais um carro foi vendido

Foi vendido mais um carro na empresa {{ $companyName }}.

**Detalhes:**

- Marca: {{ $brand }}
- Modelo: {{ $model }}
- Versão: {{ $version }}
@if($licensePlate)
- Matrícula: {{ $licensePlate }}
@endif

Obrigado,  
{{ config('app.name') }}
@endcomponent
