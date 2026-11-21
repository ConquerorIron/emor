<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\YedekIsaretleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/** `sabit_coz`: yedek yeniden saklama kurallarına girer. */
final class SabitCozIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(private readonly YedekIsaretleyici $isaretleyici) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerSil;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $yedek = $this->yedegiBul($istek);
        $this->isaretleyici->sabitle($yedek, false, $istek->kullaniciId);

        return IstekSonucu::tamam(['yedek_kimligi' => $yedek->kimlik]);
    }
}
