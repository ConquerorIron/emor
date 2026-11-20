<?php

declare(strict_types=1);

namespace App\Yetki;

/**
 * İzin kataloğu (EFAT-18). İzinler KODDA tanımlıdır — rollere ekrandan
 * atanır. Her değer aynı adla bir Gate'tir (AppServiceProvider); sistem
 * yöneticisi tüm izinlere sahiptir.
 *
 * Sol menüdeki her ekranın bir görüntüleme ve (varsa) güncelleme izni vardır
 * (kullanıcı isteği 2026-09-24); güncelleme ve ek izinler görüntülemeyi
 * içerir (RolServisi kaydederken ekler). Ana Sayfa herkese açıktır.
 */
enum Izin: string
{
    case SatinalmaTalebiGoruntule = 'satinalma_talebi.goruntule';
    case SatinalmaTalebiGuncelle = 'satinalma_talebi.guncelle';

    /**
     * Tüm Faturalar — İzibiz ve Protel'e gelen faturaların birleşik listesi
     * (kullanıcı isteği 2026-11-20). Kendi izinleri: sağlayıcı ekranlarının
     * izni bunu açmaz. Senkron ve gizleme sağlayıcı ekranlarındadır.
     */
    case TumFaturalarGoruntule = 'tum_faturalar.goruntule';
    case TumFaturalarPdf = 'tum_faturalar.pdf';
    case TumFaturalarDisariAktar = 'tum_faturalar.disari_aktar';

    case EfaturaGoruntule = 'efatura.goruntule';
    case EfaturaPdf = 'efatura.pdf';
    case EfaturaDisariAktar = 'efatura.disari_aktar';
    case EfaturaSenkron = 'efatura.senkron';
    case EfaturaGizle = 'efatura.gizle';

    /**
     * Protel Gelen Faturalar — otelin posta kutusu (PRT-10). e-Fatura'dan ayrı
     * izinler (kullanıcı kararı); "güncelle"si elle senkrondur.
     */
    case ProtelFaturalariGoruntule = 'protel_faturalari.goruntule';
    case ProtelFaturalariSenkron = 'protel_faturalari.senkron';
    case ProtelFaturalariPdf = 'protel_faturalari.pdf';
    case ProtelFaturalariDisariAktar = 'protel_faturalari.disari_aktar';

    case KesintiTurleriGoruntule = 'kesinti_turleri.goruntule';
    case KesintiTurleriGuncelle = 'kesinti_turleri.guncelle';

    case ProjelerGoruntule = 'projeler.goruntule';
    case ProjelerGuncelle = 'projeler.guncelle';

    case FirmalarGoruntule = 'firmalar.goruntule';
    case FirmalarGuncelle = 'firmalar.guncelle';

    case ParaBirimleriGoruntule = 'para_birimleri.goruntule';
    case ParaBirimleriGuncelle = 'para_birimleri.guncelle';

    case IsgucuKesintileriGoruntule = 'isgucu_kesintileri.goruntule';
    case IsgucuKesintileriGuncelle = 'isgucu_kesintileri.guncelle';

    case KampKesintileriGoruntule = 'kamp_kesintileri.goruntule';
    case KampKesintileriGuncelle = 'kamp_kesintileri.guncelle';

    case MakinaEkipmanKesintileriGoruntule = 'makina_ekipman_kesintileri.goruntule';
    case MakinaEkipmanKesintileriGuncelle = 'makina_ekipman_kesintileri.guncelle';

    case KampMalzemeKesintileriGoruntule = 'kamp_malzeme_kesintileri.goruntule';
    case KampMalzemeKesintileriGuncelle = 'kamp_malzeme_kesintileri.guncelle';

    /** Yalnız rapor: güncelleme izni yok */
    case TumKesintilerGoruntule = 'tum_kesintiler.goruntule';

    /** Yalnız rapor: güncelleme izni yok */
    case KesintiOzetiGoruntule = 'kesinti_ozeti.goruntule';

    /** Yalnız rapor: güncelleme izni yok */
    case PivotGoruntule = 'pivot.goruntule';

    case TutanakKesintileriGoruntule = 'tutanak_kesintileri.goruntule';
    case TutanakKesintileriGuncelle = 'tutanak_kesintileri.guncelle';

    case YemekKesintileriGoruntule = 'yemek_kesintileri.goruntule';
    case YemekKesintileriGuncelle = 'yemek_kesintileri.guncelle';

    case OsgbKesintileriGoruntule = 'osgb_kesintileri.goruntule';
    case OsgbKesintileriGuncelle = 'osgb_kesintileri.guncelle';

    case TetkikKesintileriGoruntule = 'tetkik_kesintileri.goruntule';
    case TetkikKesintileriGuncelle = 'tetkik_kesintileri.guncelle';

    case IsgCezaKesintileriGoruntule = 'isg_ceza_kesintileri.goruntule';
    case IsgCezaKesintileriGuncelle = 'isg_ceza_kesintileri.guncelle';

    case MalzemeKesintileriGoruntule = 'malzeme_kesintileri.goruntule';
    case MalzemeKesintileriGuncelle = 'malzeme_kesintileri.guncelle';

    case KartBasimKesintileriGoruntule = 'kart_basim_kesintileri.goruntule';
    case KartBasimKesintileriGuncelle = 'kart_basim_kesintileri.guncelle';

    /** Yalnız rapor: güncelleme izni yok */
    case ErpKesintiKontroluGoruntule = 'erp_kesinti_kontrolu.goruntule';

    /** Yalnız rapor: güncelleme izni yok */
    case TaseronHakedisleriGoruntule = 'taseron_hakedisleri.goruntule';

    /** Yalnız rapor: güncelleme izni yok */
    case GiderYansitmaFaturalariGoruntule = 'gider_yansitma_faturalari.goruntule';

    case SqlBaglantilariGoruntule = 'sql_baglantilari.goruntule';
    case SqlBaglantilariGuncelle = 'sql_baglantilari.guncelle';

    case EntegratorBaglantilariGoruntule = 'entegrator_baglantilari.goruntule';
    case EntegratorBaglantilariGuncelle = 'entegrator_baglantilari.guncelle';

    case MailAyarlariGoruntule = 'mail_ayarlari.goruntule';
    case MailAyarlariGuncelle = 'mail_ayarlari.guncelle';

    case AlarmKurallariGoruntule = 'alarm_kurallari.goruntule';
    case AlarmKurallariGuncelle = 'alarm_kurallari.guncelle';

    case KullanicilarGoruntule = 'kullanicilar.goruntule';
    case KullanicilarGuncelle = 'kullanicilar.guncelle';

    case RollerGoruntule = 'roller.goruntule';
    case RollerGuncelle = 'roller.guncelle';

    case EkranTasarimiGoruntule = 'ekran_tasarimi.goruntule';
    case EkranTasarimiGuncelle = 'ekran_tasarimi.guncelle';

    /**
     * Yedekleme (docs/yedekleme README §4.9). Görüntüleme: yedek listesi,
     * geri yükleme geçmişi, işlem günlüğü. "Güncelle": elle yedek alma ve
     * doğrulama başlatma. Üstüne geri yükleme izinle VERİLMEZ — yalnız
     * sistem yöneticisi.
     */
    case YedeklerGoruntule = 'yedekler.goruntule';
    case YedeklerAl = 'yedekler.al';
    case YedeklerSil = 'yedekler.sil';
    /** Yedek dosyasını tarayıcıya indirme (bütün veri; README §8) */
    case YedeklerIndir = 'yedekler.indir';
    case GeriYuklemeKopya = 'geri_yukleme.kopya';

    /** Planlar, hedef başına saklama kuralları ve yedek hedefleri */
    case YedekPlanlariGoruntule = 'yedek_planlari.goruntule';
    case YedekPlanlariGuncelle = 'yedek_planlari.guncelle';

    /**
     * @return list<string>
     */
    public static function degerler(): array
    {
        return array_map(fn (self $izin): string => $izin->value, self::cases());
    }

    /**
     * Roller ekranındaki matris — sol menü sırasıyla. e-Fatura'nın
     * "güncelle"si elle senkrondur; PDF, Excel ve gizleme ek izinlerdir.
     *
     * @return list<array{ekran: string, goruntule: string, guncelle: string|null, ekler: list<string>}>
     */
    public static function ekranlar(): array
    {
        $ekran = fn (string $ad, self $goruntule, ?self $guncelle, self ...$ekler): array => [
            'ekran' => $ad,
            'goruntule' => $goruntule->value,
            'guncelle' => $guncelle?->value,
            'ekler' => array_map(fn (self $izin): string => $izin->value, $ekler),
        ];

        return [
            $ekran('satinalma_talebi', self::SatinalmaTalebiGoruntule, self::SatinalmaTalebiGuncelle),
            $ekran('tum_faturalar', self::TumFaturalarGoruntule, null, self::TumFaturalarPdf, self::TumFaturalarDisariAktar),
            $ekran('efatura', self::EfaturaGoruntule, self::EfaturaSenkron, self::EfaturaPdf, self::EfaturaDisariAktar, self::EfaturaGizle),
            $ekran('protel_faturalari', self::ProtelFaturalariGoruntule, self::ProtelFaturalariSenkron, self::ProtelFaturalariPdf, self::ProtelFaturalariDisariAktar),
            // Kesinti Yönetimi grubu: menüdeki gibi önce raporlar
            $ekran('kesinti_ozeti', self::KesintiOzetiGoruntule, null),
            $ekran('pivot', self::PivotGoruntule, null),
            $ekran('tum_kesintiler', self::TumKesintilerGoruntule, null),
            $ekran('isgucu_kesintileri', self::IsgucuKesintileriGoruntule, self::IsgucuKesintileriGuncelle),
            $ekran('kamp_kesintileri', self::KampKesintileriGoruntule, self::KampKesintileriGuncelle),
            $ekran('kamp_malzeme_kesintileri', self::KampMalzemeKesintileriGoruntule, self::KampMalzemeKesintileriGuncelle),
            $ekran('makina_ekipman_kesintileri', self::MakinaEkipmanKesintileriGoruntule, self::MakinaEkipmanKesintileriGuncelle),
            $ekran('tutanak_kesintileri', self::TutanakKesintileriGoruntule, self::TutanakKesintileriGuncelle),
            $ekran('yemek_kesintileri', self::YemekKesintileriGoruntule, self::YemekKesintileriGuncelle),
            $ekran('osgb_kesintileri', self::OsgbKesintileriGoruntule, self::OsgbKesintileriGuncelle),
            $ekran('tetkik_kesintileri', self::TetkikKesintileriGoruntule, self::TetkikKesintileriGuncelle),
            $ekran('isg_ceza_kesintileri', self::IsgCezaKesintileriGoruntule, self::IsgCezaKesintileriGuncelle),
            $ekran('malzeme_kesintileri', self::MalzemeKesintileriGoruntule, self::MalzemeKesintileriGuncelle),
            $ekran('kart_basim_kesintileri', self::KartBasimKesintileriGoruntule, self::KartBasimKesintileriGuncelle),
            // Raporlar grubu
            $ekran('erp_kesinti_kontrolu', self::ErpKesintiKontroluGoruntule, null),
            $ekran('taseron_hakedisleri', self::TaseronHakedisleriGoruntule, null),
            $ekran('gider_yansitma_faturalari', self::GiderYansitmaFaturalariGoruntule, null),
            // Tanımlar grubu
            $ekran('projeler', self::ProjelerGoruntule, self::ProjelerGuncelle),
            $ekran('firmalar', self::FirmalarGoruntule, self::FirmalarGuncelle),
            $ekran('para_birimleri', self::ParaBirimleriGoruntule, self::ParaBirimleriGuncelle),
            $ekran('kesinti_turleri', self::KesintiTurleriGoruntule, self::KesintiTurleriGuncelle),
            $ekran('sql_baglantilari', self::SqlBaglantilariGoruntule, self::SqlBaglantilariGuncelle),
            $ekran('entegrator_baglantilari', self::EntegratorBaglantilariGoruntule, self::EntegratorBaglantilariGuncelle),
            $ekran('mail_ayarlari', self::MailAyarlariGoruntule, self::MailAyarlariGuncelle),
            $ekran('alarm_kurallari', self::AlarmKurallariGoruntule, self::AlarmKurallariGuncelle),
            $ekran('kullanicilar', self::KullanicilarGoruntule, self::KullanicilarGuncelle),
            $ekran('roller', self::RollerGoruntule, self::RollerGuncelle),
            $ekran('ekran_tasarimi', self::EkranTasarimiGoruntule, self::EkranTasarimiGuncelle),
            // Yedekleme (menüye ekranlarla birlikte girer: BY-015, BY-017)
            $ekran('yedekler', self::YedeklerGoruntule, self::YedeklerAl, self::YedeklerSil, self::YedeklerIndir, self::GeriYuklemeKopya),
            $ekran('yedek_planlari', self::YedekPlanlariGoruntule, self::YedekPlanlariGuncelle),
        ];
    }

    /**
     * Seçilen izinlere, güncelleme/ek izinlerinin ait olduğu ekranın
     * görüntüleme iznini ekler (görmeden güncellenemez). Sıra katalog sırasıdır.
     *
     * @param  list<string>  $izinler
     * @return list<string>
     */
    public static function tamamla(array $izinler): array
    {
        $secili = array_flip($izinler);

        foreach (self::ekranlar() as $ekran) {
            foreach ([$ekran['guncelle'], ...$ekran['ekler']] as $bagli) {
                if ($bagli !== null && isset($secili[$bagli])) {
                    $secili[$ekran['goruntule']] = true;
                }
            }
        }

        return array_values(array_filter(self::degerler(), fn (string $izin): bool => isset($secili[$izin])));
    }
}
