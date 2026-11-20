<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekAyari;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Uygulama dışındaki izleyiciye "yaşıyorum" sinyali (README §4.8; BY-012).
 * Üç ayrı sinyal: yerel yedek, sunucu dışı kopya, sınama geri yüklemesi.
 * Sinyal gecikirse uyarıyı izleyici üretir; zamanlayıcı ya da sunucu dursa da
 * "sessizlik" yakalanır.
 *
 * İstek gövdesiz GET'tir: veri, parola, dosya adı taşımaz. Adres boşsa
 * atlanır. Sinyal hatası yedeği bozmaz; günlüğe yazılır.
 */
final class YasamSinyali
{
    public const YEREL = 'yerel';

    public const UZAK = 'uzak';

    public const SINAMA = 'sinama';

    public function gonder(string $tur): void
    {
        try {
            $adres = YedekAyari::guncel()->getAttribute('sinyal_'.$tur.'_adresi');
        } catch (Throwable) {
            return;
        }

        if (! is_string($adres) || $adres === '' || ! str_starts_with($adres, 'https://')) {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->get($adres)->throw();
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->warning('Yaşam sinyali gönderilemedi', ['tur' => $tur, 'hata' => $hata::class]);
        }
    }
}
