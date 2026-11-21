<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\YedekKilidi;
use App\Yetki\Izin;

/**
 * Bir istek türünün işleyicisi (BY-025). Yeni tür = bu arayüzü uygulayan
 * bir sınıf + IsleyiciKatalogu'nda bir satır + IstekKutusu::TURLER'de şema;
 * çalıştırıcıda değişiklik gerekmez.
 *
 * Çalıştırıcı işleyiciyi çağırmadan önce: isteği şemayla doğrular,
 * kullanıcının yetkisini (izin()) veritabanından yeniden denetler,
 * yedekleme kilidini alır ve açık bir üstüne geri yükleme olmadığını
 * görür. İşleyici bunları tekrarlamaz; yalnız türe özgü ön koşulları
 * (yedek var mı, sabit mi …) IstekReddi ile reddeder.
 */
interface IstekIsleyicisi
{
    /**
     * İsteği yapabilmek için gereken izin; null = izinle verilmez, yalnız
     * sistem yöneticisi (README §4.9: üstüne geri yükleme).
     */
    public function izin(): ?Izin;

    /**
     * @param  YedekKilidi  $kilit  Tutulan yedekleme kilidi (işlem durumu dosyasını yazmak için)
     *
     * @throws IstekReddi Türe özgü ön koşul tutmuyor
     */
    public function isle(Istek $istek, YedekKilidi $kilit): IstekSonucu;
}
