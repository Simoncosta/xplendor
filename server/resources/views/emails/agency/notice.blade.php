@component('mail::message')
# {{ $title }}

@foreach($lines as $line)
{{ $line }}

@endforeach
@if($actionLabel && $url)
@component('mail::button', ['url' => $url])
{{ $actionLabel }}
@endcomponent
@endif

{{ config('app.name') }}
@endcomponent
