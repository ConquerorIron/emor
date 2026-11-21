<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;
use Throwable;

/** "Klasörü Tara" (`tara`; BY-011): dizini manifestlerden kurar / günceller. */
final class TaraIsleyicisi implements IstekIsleyicisi
{
    public function __construct(
        private readonly DizinKurucu $kurucu,
        private readonly YedekDenetimi $denetim,
    ) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerAl;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        try {
            $sonuc = $this->kurucu->tara();
        } catch (Throwable $hata) {
            $this->denetim->kaydet('klasor_tara', IslemSonucu::Basarisiz, kullaniciId: $istek->kullaniciId, hata: $hata->getMessage());

            return IstekSonucu::basarisiz('YEDEK_TARAMA_BASARISIZ', 'Yedek klasörü taranamadı.');
        }

        $this->denetim->kaydet('klasor_tara', IslemSonucu::Basarili, kullaniciId: $istek->kullaniciId, ayrinti: $sonuc);

        return IstekSonucu::tamam(['tarama' => $sonuc]);
    }
}
