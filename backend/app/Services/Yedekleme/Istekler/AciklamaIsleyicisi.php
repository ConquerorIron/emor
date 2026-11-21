<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\YedekIsaretleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/** `aciklama`: yedeğin notu (boş = notu kaldır). */
final class AciklamaIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(private readonly YedekIsaretleyici $isaretleyici) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerAl;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $yedek = $this->yedegiBul($istek);
        $this->isaretleyici->aciklamaYaz($yedek, $istek->metin('aciklama'), $istek->kullaniciId);

        return IstekSonucu::tamam(['yedek_kimligi' => $yedek->kimlik]);
    }
}
