<x-mail::message>
# {{ $baslik }}

@if ($ortam === 'test')
**{{ __('mail.alarm.test_ortami') }}**

@endif
@foreach ($satirlar as $satir)
{{ $satir }}

@endforeach
<x-mail::button :url="$baglanti">
{{ $baglantiMetni }}
</x-mail::button>

{{ __('mail.alarm.alt_not') }}
</x-mail::message>
