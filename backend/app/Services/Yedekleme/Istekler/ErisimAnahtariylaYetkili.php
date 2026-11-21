<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;

/**
 * Yetkisi kullanıcı tablosundan değil, üstüne geri yüklemenin ERİŞİM
 * ANAHTARINDAN gelen istekler: onay ve geri dönüş (README §7.2; BY-029).
 * Yer değiştirmeden sonra kullanıcı tablosu yedeğin anındadır (yöneticilik
 * değişmiş olabilir) ve veritabanı erişilemez de olabilir; bu yüzden
 * çalıştırıcı bu türlerde veritabanına bakmaz.
 *
 * İstekte anahtar YOKTUR (BY-030-R1): web ucu anahtarı doğrulayıp kısa
 * ömürlü karar kanıtı yazar. İşleyici kanıtı işlem durumu dosyasındaki
 * özetle yeniden hesaplar (ErisimAnahtari::kanitDenetle), anahtarın ve
 * kanıtın süresine bakar, nonce'u işlem durumunda tüketir (tek kullanım).
 * İstekteki kullanıcı, işlemi isteyenle aynı olmalı. Kullanan işleyici
 * AnahtarlaYetkilendirilir'i uygular (çalıştırıcıya işaret).
 */
trait ErisimAnahtariylaYetkili
{
    /**
     * @param  'onayla'|'geri_don'  $karar
     *
     * @throws IstekReddi Kanıt geçersiz, süresi geçmiş, kullanılmış ya da başka işlemin
     */
    private function kanitDenetle(Istek $istek, string $karar, YedeklemeYapilandirmasi $yapilandirma, UstuneGeriYukleyici $ustune, YedekKilidi $kilit): void
    {
        try {
            $kayit = $yapilandirma->islemDurumu()->oku();
        } catch (IslemDurumuBozuk) {
            throw new IstekReddi(GeriYuklemeHatasi::ISLEM_DURUMU_OKUNAMADI, 'İşlem durumu okunamadı.');
        }

        $islem = (string) $istek->metin('islem_kimligi');
        if ($kayit === null || $kayit->tur !== IslemTuru::GeriYuklemeUstune || $kayit->kimlik !== $islem
            || $kayit->isteyenKullaniciId !== $istek->kullaniciId) {
            throw new IstekReddi(GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ, 'Karar kanıtı bu işlemin değil.');
        }

        $son = (int) $istek->parametreler['son_gecerlilik'];
        $nonce = (string) $istek->metin('nonce');
        $sonuc = ErisimAnahtari::kanitDenetle($istek->metin('kanit'), $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis, $islem, $karar, $son, $nonce, (string) $istek->metin('ayar_secimleri'));

        match ($sonuc) {
            ErisimAnahtari::GECERLI => null,
            ErisimAnahtari::SURESI_DOLDU => throw new IstekReddi(GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU, 'Erişim anahtarının süresi doldu; komut satırından (yedek:acil) yapın.'),
            ErisimAnahtari::KANIT_SURESI_DOLDU => throw new IstekReddi(GeriYuklemeHatasi::KARAR_SURESI_DOLDU, 'Kararın süresi geçti (en çok '.ErisimAnahtari::KANIT_OMRU_SN.' sn); uygulanmadı, yeniden karar verin.'),
            default => throw new IstekReddi(GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ, 'Karar kanıtı geçersiz.'),
        };

        try {
            $ustune->kanitTuket($kilit, $islem, $nonce, $son);
        } catch (GeriYuklemeHatasi $hata) {
            throw new IstekReddi($hata->kod, $hata->getMessage());
        }
    }
}
