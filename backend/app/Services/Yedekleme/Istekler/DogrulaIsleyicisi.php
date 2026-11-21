<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYukleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/**
 * Elle doğrulama (`dogrula`; README §4.4 3. düzey): sınama geri yüklemesi
 * (2. düzey). Yalnız imzalı, tamamlanmış yedek.
 */
final class DogrulaIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public function __construct(private readonly GeriYukleyici $geriYukleyici) {}

    public function izin(): ?Izin
    {
        return Izin::YedeklerAl;
    }

    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu
    {
        $yedek = $this->yedegiBul($istek);

        if (! $yedek->geriYuklenebilirMi()) {
            throw new IstekReddi('YEDEK_DOGRULANAMAZ', 'Yalnız imzalı, tamamlanmış yedek doğrulanır.');
        }

        try {
            $kod = $this->geriYukleyici->sina($kilit, $yedek, $istek->kullaniciId);
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }

        $yedek->refresh();
        $ek = ['yedek_kimligi' => $yedek->kimlik, 'dogrulama_durumu' => $yedek->dogrulama_durumu->value];

        return $kod === null
            ? IstekSonucu::tamam($ek)
            : IstekSonucu::basarisiz($kod, 'Sınama geri yüklemesi başarısız.', $ek);
    }
}
