<x-mail::message>
# {{ __('mail.test_baslik') }}

{{ __('mail.test_govde', ['kullanici' => $gonderenKullanici]) }}

{{ __('mail.test_not') }}
</x-mail::message>
