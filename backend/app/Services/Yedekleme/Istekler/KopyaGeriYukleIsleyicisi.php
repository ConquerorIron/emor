<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYukleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/** Farklı ada geri yükleme (`kopya_geri_yukle`; README §4.5-A, §6.3). */
final class KopyaGeriYukleIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(private readonly GeriYukleyici $geriYukleyici) {}

    public function izin(): ?Izin
    {
        return Izin::GeriYuklemeKopya;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $yedek = $this->yedegiBul($istek);

        try {
            $geri = $this->geriYukleyici->kopyaya($kilit, $yedek, $istek->metin('ek'), $istek->kullaniciId);
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }

        $ek = ['yedek_kimligi' => $yedek->kimlik, 'geri_yukleme_kimligi' => $geri->kimlik, 'hedef_veritabani' => $geri->hedef_veritabani];

        return $geri->durum === GeriYuklemeDurumu::Tamam
            ? IstekSonucu::tamam($ek)
            : IstekSonucu::basarisiz((string) $geri->hata_kodu, $geri->hata_mesaji, $ek);
    }
}
