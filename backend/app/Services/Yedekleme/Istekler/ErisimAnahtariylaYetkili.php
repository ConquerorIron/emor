<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;

/**
 * Yetkisi kullanıcı tablosundan değil, üstüne geri yüklemenin ERİŞİM
 * ANAHTARINDAN gelen istekler: onay ve geri dönüş (README §7.2; BY-029).
 * Yer değiştirmeden sonra kullanıcı tablosu yedeğin anındadır (yöneticilik
 * değişmiş olabilir) ve veritabanı erişilemez de olabilir; bu yüzden
 * çalıştırıcı bu türlerde veritabanına bakmaz, işleyici anahtarı işlem
 * durumu dosyasındaki özetle ve süresiyle doğrular. İstekteki kullanıcı,
 * işlemi isteyenle aynı olmalı. Kullanan işleyici AnahtarlaYetkilendirilir'i
 * uygular (çalıştırıcıya işaret).
 */
trait ErisimAnahtariylaYetkili
{
    /**
     * @throws IstekReddi Anahtar geçersiz, süresi dolmuş ya da başka işlemin
     */
    private function anahtarDenetle(Istek $istek, YedeklemeYapilandirmasi $yapilandirma): void
    {
        try {
            $kayit = $yapilandirma->islemDurumu()->oku();
        } catch (IslemDurumuBozuk) {
            throw new IstekReddi(GeriYuklemeHatasi::ISLEM_DURUMU_OKUNAMADI, 'İşlem durumu okunamadı.');
        }

        if ($kayit === null || $kayit->tur !== IslemTuru::GeriYuklemeUstune || $kayit->kimlik !== $istek->metin('islem_kimligi')
            || $kayit->isteyenKullaniciId !== $istek->kullaniciId) {
            throw new IstekReddi(GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ, 'Erişim anahtarı bu işlemin değil.');
        }

        $sonuc = ErisimAnahtari::denetle($istek->metin('erisim_anahtari'), $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis);
        if ($sonuc !== ErisimAnahtari::GECERLI) {
            throw new IstekReddi(
                $sonuc === ErisimAnahtari::SURESI_DOLDU ? GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU : GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ,
                $sonuc === ErisimAnahtari::SURESI_DOLDU ? 'Erişim anahtarının süresi doldu; komut satırından (yedek:acil) yapın.' : 'Erişim anahtarı geçersiz.',
            );
        }
    }
}
