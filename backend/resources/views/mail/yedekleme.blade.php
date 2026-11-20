<x-mail::message>
# {{ $baslik }}

@foreach ($satirlar as $satir)
{{ $satir }}

@endforeach
<x-mail::button :url="$baglanti">
{{ __('yedekleme.mail.ekrani_ac') }}
</x-mail::button>

{{ __('yedekleme.mail.alt_not') }}
</x-mail::message>
