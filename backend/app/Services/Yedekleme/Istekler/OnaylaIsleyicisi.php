<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Yetki\Izin;

/** Üstüne geri yüklemenin onayı (`onayla`; README §6.4 10. adım; BY-029). */
final class OnaylaIsleyicisi implements AnahtarlaYetkilendirilir
{
    use ErisimAnahtariylaYetkili;

    public function __construct(
        private readonly UstuneGeriYukleyici $ustune,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public function izin(): ?Izin
    {
        return null;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $this->anahtarDenetle($istek, $this->yapilandirma);

        try {
            $kayit = $this->ustune->onayla($kilit, (string) $istek->metin('islem_kimligi'));
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }

        $ek = ['islem_kimligi' => $kayit->kimlik, 'adim' => $kayit->adim];

        return $kayit->adim === UstuneGeriYuklemeAdimi::Onaylandi->value
            ? IstekSonucu::tamam($ek)
            : IstekSonucu::basarisiz($kayit->hata['kod'] ?? GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, $kayit->hata['mesaj'] ?? null, $ek);
    }
}
