<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Yetki\Izin;

/**
 * Üstüne geri yükleme (`ustune_geri_yukle`; README §4.5-B, §6.4; BY-028).
 * Yalnız sistem yöneticisi (izinle verilmez). İşlemin kimliği isteğin
 * kimliğidir; erişim anahtarının bitişi isteğin zamanından sayılır.
 * İşleyici onay adımında DÖNER: kilit bırakılır, onay / geri dön istekleri
 * sonraki turlarda işlenir.
 *
 * Yayın kilidi (BY-013-R1): ilk durum yazılmadan önce beklemeden alınır;
 * `deploy.sh` tutuyorsa işlem başlamaz (`GERI_YUKLEME_YAYIN_SURUYOR`). İşleyici
 * dönene kadar tutulur; sonra yayın açık işlemi durum dosyasından görür.
 */
final class UstuneGeriYukleIsleyicisi implements IstekIsleyicisi
{
    use YedegiBulur;

    public const YAYIN_SURUYOR = 'GERI_YUKLEME_YAYIN_SURUYOR';

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
        $yedek = $this->yedegiBul($istek);

        $yayin = $this->yapilandirma->yayinKilidi();
        if (! $yayin->al()) {
            throw new IstekReddi(self::YAYIN_SURUYOR, 'Bir yayın (deploy.sh) sürüyor; üstüne geri yükleme başlatılmadı. Yayın bitince yeniden isteyin.');
        }

        try {
            $kayit = $this->ustune->baslat(
                $kilit,
                $yedek,
                (string) $istek->metin('onay_metni'),
                (int) $istek->kullaniciId,
                $istek->kimlik,
                (string) $istek->metin('erisim_anahtari_ozeti'),
                $istek->zaman->addHours(ErisimAnahtari::GECERLILIK_SAAT),
            );
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        } finally {
            $yayin->birak();
        }

        $ek = ['islem_kimligi' => $kayit->kimlik, 'adim' => $kayit->adim];

        return $kayit->adim === UstuneGeriYuklemeAdimi::OnayBekliyor->value
            ? IstekSonucu::tamam($ek)
            : IstekSonucu::basarisiz($kayit->hata['kod'] ?? GeriYuklemeHatasi::YARIDA_KALDI, $kayit->hata['mesaj'] ?? null, $ek);
    }
}
