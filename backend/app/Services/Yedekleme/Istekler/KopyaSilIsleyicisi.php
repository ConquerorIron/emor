<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\KopyaVeritabanlari;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/**
 * Geri yüklenmiş kopyayı sil (`kopya_sil`; README §4.5-A). Yalnız kopya
 * kalıbındaki ve bu sistemin açtığı (geri_yuklemeler'de kaydı olan)
 * veritabanı; `erp` ya da başka bir ad asla.
 */
final class KopyaSilIsleyicisi implements IstekIsleyicisi
{
    public function __construct(private readonly KopyaVeritabanlari $kopyalar) {}

    public function izin(): ?Izin
    {
        return Izin::GeriYuklemeKopya;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        try {
            $geri = $this->kopyalar->sil($kilit, (string) $istek->metin('veritabani'), $istek->kullaniciId);
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }

        return IstekSonucu::tamam(['geri_yukleme_kimligi' => $geri->kimlik, 'hedef_veritabani' => $geri->hedef_veritabani]);
    }
}
