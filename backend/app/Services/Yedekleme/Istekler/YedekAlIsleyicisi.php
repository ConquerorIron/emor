<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Elle ya da yayın öncesi yedek (`yedek_al`; README §4.1, §5.5). Yedekten
 * sonra temizlik çalışır (README §4.3); temizlik hatası yedeği bozmaz.
 * Kullanıcısız istek yalnız yayın öncesi kaynaklı olabilir (çalıştırıcı denetler).
 */
final class YedekAlIsleyicisi implements IstekIsleyicisi
{
    public function __construct(
        private readonly YedekAlici $alici,
        private readonly SaklamaPolitikasi $saklama,
    ) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerAl;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        try {
            $yedek = $this->alici->al($kilit, YedekKaynagi::from((string) $istek->metin('kaynak')), $istek->metin('aciklama'), $istek->kullaniciId);
        } catch (YedekAlmaHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }

        $ek = ['yedek_kimligi' => $yedek->kimlik];
        if ($yedek->durum !== YedekDurumu::Tamam) {
            return IstekSonucu::basarisiz((string) ($yedek->hata_kodu ?? 'YEDEK_BEKLENMEYEN_HATA'), $yedek->hata_mesaji, $ek);
        }

        try {
            $this->saklama->uygula($istek->kullaniciId);
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->error('Yedekten sonraki temizlik çalışmadı', ['hata' => $hata->getMessage()]);
        }

        return IstekSonucu::tamam($ek);
    }
}
