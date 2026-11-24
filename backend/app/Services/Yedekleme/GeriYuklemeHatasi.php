<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Farklı ada / sınama geri yüklemesi yapılamadı (BY-026). `kod` makine
 * okunurdur (geri_yuklemeler.hata_kodu, istek sonucu, API sözleşmesi);
 * mesaj sır ve satır verisi taşımaz.
 */
final class GeriYuklemeHatasi extends RuntimeException
{
    /** Ek kalıp dışı ya da ad üretilemedi; işlem başlamadı */
    public const HEDEF_ADI_GECERSIZ = 'GERI_YUKLEME_HEDEF_ADI_GECERSIZ';

    /** Bu adla bir veritabanı zaten var; ona dokunulmadı */
    public const HEDEF_VAR = 'GERI_YUKLEME_HEDEF_VAR';

    /** İmzasız ("güvenilmeyen") ya da tamamlanmamış yedek (README §4.5 güven sınırı) */
    public const YEDEK_GERI_YUKLENEMEZ = 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ';

    /** Manifest yok, imzası tutmuyor ya da yedek kaydıyla eşleşmiyor */
    public const IMZA_GECERSIZ = 'GERI_YUKLEME_IMZA_GECERSIZ';

    /** Yerel dosya manifestteki boyut / SHA-256 ile aynı değil */
    public const OZET_TUTMUYOR = 'GERI_YUKLEME_OZET_TUTMUYOR';

    /** Yerel dosya yok, okunabilen şifresiz kopya da yok */
    public const YEDEK_DOSYASI_YOK = 'GERI_YUKLEME_YEDEK_DOSYASI_YOK';

    /** Yalnız şifreli (sunucu dışı) kopya var: özel anahtar gerekir, ekrandan yapılamaz (README §4.7) */
    public const OZEL_ANAHTAR_GEREKLI = 'GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI';

    /** Manifestteki veritabanı özelliği tanınmıyor ya da desteklenmiyor */
    public const VERITABANI_OZELLIGI = 'GERI_YUKLEME_VERITABANI_OZELLIGI';

    public const VERITABANI_OLUSTURULAMADI = 'GERI_YUKLEME_VERITABANI_OLUSTURULAMADI';

    public const PG_RESTORE = 'GERI_YUKLEME_PG_RESTORE_HATASI';

    /** Satır sayıları, migration listesi ya da nesne sahibi manifestle tutmuyor */
    public const KARSILASTIRMA_TUTMADI = 'GERI_YUKLEME_KARSILASTIRMA_TUTMADI';

    public const VERITABANI_SILINEMEDI = 'GERI_YUKLEME_VERITABANI_SILINEMEDI';

    public const ACIK_GERI_YUKLEME = 'GERI_YUKLEME_ACIK_GERI_YUKLEME';

    public const ISLEM_DURUMU_OKUNAMADI = 'GERI_YUKLEME_ISLEM_DURUMU_OKUNAMADI';

    /** Silinmek istenen ad, çalıştırıcının açıp hâlâ beklettiği bir kopya değil */
    public const KOPYA_YOK = 'GERI_YUKLEME_KOPYA_YOK';

    /** Süreç öldü; işlem yarıda kaldı (temizlikte kapatılır) */
    public const YARIDA_KALDI = 'GERI_YUKLEME_YARIDA_KALDI';

    public const YAPILANDIRMA = 'GERI_YUKLEME_YAPILANDIRMA_HATASI';

    // Üstüne geri yükleme (BY-028 / BY-029; README §6.4)

    /** Servis hesabının çalıştırıcısı yok (`calistirici` = servis değil): README §5.2 */
    public const SERVIS_GEREKLI = 'GERI_YUKLEME_SERVIS_GEREKLI';

    /** Onay metni canlı veritabanının adıyla aynı değil */
    public const ONAY_METNI_TUTMUYOR = 'GERI_YUKLEME_ONAY_METNI_TUTMUYOR';

    /** İçe alınmış eski yedek: migration listesi yok, şema uyumu denetlenemez */
    public const SEMA_BILINMIYOR = 'GERI_YUKLEME_SEMA_BILINMIYOR';

    /** Yedekte olup kodda bulunmayan migration var (README §4.5-B) */
    public const SEMA_UYUMSUZ = 'GERI_YUKLEME_SEMA_UYUMSUZ';

    public const DISK_YETERSIZ = 'GERI_YUKLEME_DISK_YETERSIZ';

    /** Bakım modu dosyası yazılamadı / kaldırılamadı, bağlantı ya da uygulama zaten bakım modunda */
    public const BAKIM_MODU = 'GERI_YUKLEME_BAKIM_MODU_HATASI';

    public const KUYRUK_SERVISI = 'GERI_YUKLEME_KUYRUK_SERVISI_HATASI';

    public const ONCESI_YEDEK = 'GERI_YUKLEME_ONCESI_YEDEK_BASARISIZ';

    public const MIGRATION = 'GERI_YUKLEME_MIGRATION_HATASI';

    public const BAGLANTI_KAPATILAMADI = 'GERI_YUKLEME_BAGLANTI_KAPATILAMADI';

    public const YER_DEGISTIRILEMEDI = 'GERI_YUKLEME_YER_DEGISTIRILEMEDI';

    /** Veritabanı adları işlem durumuyla tutmuyor; otomatik karar verilmedi, yönetici bakmalı */
    public const ADLAR_TUTARSIZ = 'GERI_YUKLEME_ADLAR_TUTARSIZ';

    /** Veritabanı adları okunamadı (PostgreSQL erişilemiyor); adım değişmedi, çalıştırıcı yeniden dener (YF-41) */
    public const ADLAR_OKUNAMADI = 'GERI_YUKLEME_ADLAR_OKUNAMADI';

    /** Yedeği alan pg_dump'ın ana sürümü bu sunucunun pg_restore'undan ya da PostgreSQL'inden yeni (README §7.7; YF-14) */
    public const PG_SURUMU = 'GERI_YUKLEME_PG_SURUMU_UYUMSUZ';

    /** Yedek bugünkü APP_KEY ve APP_PREVIOUS_KEYS dışındaki bir anahtarla alınmış: şifreli alanlar çözülemez (README §7.5; YF-13) */
    public const APP_KEY_UYUMSUZ = 'GERI_YUKLEME_APP_KEY_UYUMSUZ';

    /** Bekleyen farklı ad kopyası sayısı sınırda (yedekleme.bekleyen_kopya_siniri; YF-43) */
    public const KOPYA_SINIRI = 'GERI_YUKLEME_KOPYA_SINIRI';

    public const DIZIN_KURULAMADI = 'GERI_YUKLEME_DIZIN_KURULAMADI';

    public const AYAR_UZLASTIRILAMADI = 'GERI_YUKLEME_AYAR_UZLASTIRILAMADI';

    /** Onay / geri dönüş bu adımda verilemez ya da işlem kimliği tutmuyor */
    public const ISLEM_DURUMU_UYGUN_DEGIL = 'GERI_YUKLEME_ISLEM_DURUMU_UYGUN_DEGIL';

    /** Durum dosyasındaki adım bu sürümün bildiği bir adım değil */
    public const BILINMEYEN_ADIM = 'GERI_YUKLEME_BILINMEYEN_ADIM';

    public const ERISIM_ANAHTARI_YOK = 'GERI_YUKLEME_ERISIM_ANAHTARI_YOK';

    public const ERISIM_ANAHTARI_GECERSIZ = 'GERI_YUKLEME_ERISIM_ANAHTARI_GECERSIZ';

    public const ERISIM_ANAHTARI_SURESI_DOLDU = 'GERI_YUKLEME_ERISIM_ANAHTARI_SURESI_DOLDU';

    // BY-026-R1, BY-028-R1/R2, BY-030-R1

    /** Ayrı servis rolü (YEDEKLEME_ROL ≠ uygulama rolü) ya da parola dosyası yok: yazıcı bariyeri kurulamaz */
    public const SERVIS_ROLU_GEREKLI = 'GERI_YUKLEME_SERVIS_ROLU_GEREKLI';

    /** Canlının bağlanma yetkileri daraltılamadı ya da yazıcı oturumları sonlandırılamadı */
    public const YAZICI_BARIYERI = 'GERI_YUKLEME_YAZICI_BARIYERI';

    /** Manifestteki / özgün bağlanma yetkileri veritabanına eşdeğer uygulanamadı (ör. rol yok); yetkiler değişmedi */
    public const VERITABANI_YETKILERI = 'GERI_YUKLEME_VERITABANI_YETKILERI';

    /** Farkı olan bir ayar grubu için seçim yapılmadı (varsayılan seçim yok) */
    public const AYAR_SECIMI_EKSIK = 'GERI_YUKLEME_AYAR_SECIMI_EKSIK';

    /** "Güncel" seçilen ayarlar geri yüklenen veritabanına yazılamadı; hiçbiri yazılmadı */
    public const AYAR_SECIMI_UYGULANAMADI = 'GERI_YUKLEME_AYAR_SECIMI_UYGULANAMADI';

    /** Onay / geri dönüş kararının kanıtının süresi geçti (en çok 5 dakika) */
    public const KARAR_SURESI_DOLDU = 'GERI_YUKLEME_KARAR_SURESI_DOLDU';

    /** Bu karar kanıtı daha önce kullanıldı (tek kullanım) */
    public const KARAR_KANITI_KULLANILDI = 'GERI_YUKLEME_KARAR_KANITI_KULLANILDI';

    /** Süresi geçmemiş tüketilmiş kanıt listesi dolu (IslemKaydi::EN_COK_KANIT): yeni karar uygulanmadı; kanıtlar en çok 5 dakikada düşer */
    public const KARAR_KAPASITESI_DOLU = 'GERI_YUKLEME_KARAR_KAPASITESI_DOLU';

    /**
     * Yedeğin kendisinden kaynaklanan hatalar: sınamada bunlar yedeği
     * "doğrulama başarısız" yapar. Diğerleri (bağlantı, yetki, yapılandırma)
     * yedeğin durumunu değiştirmez, yalnız bildirilir.
     */
    public const YEDEK_KAYNAKLI = [
        self::IMZA_GECERSIZ,
        self::OZET_TUTMUYOR,
        self::YEDEK_DOSYASI_YOK,
        self::PG_RESTORE,
        self::KARSILASTIRMA_TUTMADI,
    ];

    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
