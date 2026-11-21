<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Üstüne geri yüklemenin adımları (README §6.4; BY-028, BY-029): işlem durumu
 * dosyasındaki `adim` değeri. Serbest metin DEĞİLDİR (Fable F17): devam,
 * geri sarma, onay ve geri dönüş kararlarının hepsi bu enum'un yöntemlerine
 * bakar. Her adım, işi yapılmadan ÖNCE dosyaya yazılır; süreç ölürse dosya
 * "hangi adımın içinde kalındığını" gösterir.
 *
 * Sıra (ileri akış): on_denetim → bakim_modu → oncesi_yedek → veritabani →
 * pg_restore → migration → dogrulama → baglanti_kapatma → yer_degistirme →
 * dizin_kurma → ayar_uzlastirma → onay_bekliyor → (onaylaniyor → onaylandi |
 * geri_donuluyor → geri_donuldu). Yer değiştirmeden önceki bir hatada:
 * geri_sariliyor → basarisiz.
 */
enum UstuneGeriYuklemeAdimi: string
{
    case OnDenetim = 'on_denetim';
    case BakimModu = 'bakim_modu';
    case OncesiYedek = 'oncesi_yedek';
    case Veritabani = 'veritabani';
    case PgRestore = 'pg_restore';
    case Migration = 'migration';
    case Dogrulama = 'dogrulama';
    case BaglantiKapatma = 'baglanti_kapatma';
    case YerDegistirme = 'yer_degistirme';
    case DizinKurma = 'dizin_kurma';
    case AyarUzlastirma = 'ayar_uzlastirma';
    case OnayBekliyor = 'onay_bekliyor';
    case Onaylaniyor = 'onaylaniyor';
    case Onaylandi = 'onaylandi';
    case GeriDonuluyor = 'geri_donuluyor';
    case GeriDonuldu = 'geri_donuldu';
    case GeriSariliyor = 'geri_sariliyor';
    case Basarisiz = 'basarisiz';

    /** Ekranın adım listesi: ileri akışın sırası (README §6.4 1–9) */
    public const ILERI_AKIS = [
        self::OnDenetim, self::BakimModu, self::OncesiYedek, self::Veritabani, self::PgRestore,
        self::Migration, self::Dogrulama, self::BaglantiKapatma, self::YerDegistirme,
        self::DizinKurma, self::AyarUzlastirma, self::OnayBekliyor,
    ];

    /**
     * Canlı veritabanı henüz yerinde: bu adımlarda (ya da içinde kalınmışsa)
     * geri sarmak güvenlidir — yeni veritabanı silinir, servisler açılır
     * (README §6.4 hata kuralı). Yer değiştirme adımının kendisi bu listede
     * DEĞİLDİR: orada adlara bakılarak karar verilir.
     */
    public function yerDegistirmedenOnceMi(): bool
    {
        return in_array($this, [
            self::OnDenetim, self::BakimModu, self::OncesiYedek, self::Veritabani, self::PgRestore,
            self::Migration, self::Dogrulama, self::BaglantiKapatma, self::GeriSariliyor,
        ], true);
    }

    /**
     * Yer değiştirme bitti: canlı ad geri yüklenen veritabanında. Bundan sonra
     * uygulamayı açma kararı yalnız yöneticinindir; onay ve geri dönüş yalnız
     * bu adımlarda verilir (onay bekliyor, 8. adım hatayla durmuş, ya da
     * yarıda kalmış bir onay / geri dönüş).
     */
    public function yerDegistirmedenSonraMi(): bool
    {
        return in_array($this, [self::DizinKurma, self::AyarUzlastirma, self::OnayBekliyor, self::Onaylaniyor, self::GeriDonuluyor], true);
    }

    /** İşlem kapandı; dosya yalnız geçmişi gösterir */
    public function bittiMi(): bool
    {
        return in_array($this, [self::Onaylandi, self::GeriDonuldu, self::Basarisiz], true);
    }
}
