<x-mail::message>
# {{ $title }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
<x-mail::table>
| Documento | Fornecedor | Data | Valor |
|:--|:--|:--|--:|
@foreach ($documents as $d)
| {{ $d['number'] }} | {{ $d['supplier'] }} | {{ $d['date'] }} | {{ $d['value'] }} |
@endforeach
</x-mail::table>

{{ $closing }}
</x-mail::message>
