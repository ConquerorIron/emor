<?php

declare(strict_types=1);

namespace App\Ekranlar;

/**
 * Satınalma Talebi SATIR kolonları — katalog tarafı.
 *
 * Başlık alanlarındaki ayrımın aynısı: burada kolonun NE olduğu durur (var mı,
 * kaldırılabilir mi, kullanıcı girebilir mi, varsayılan genişliği ne). Kolonun
 * NASIL çizildiği (hangi ERP listesinden seçildiği, hangi fiyat grubuna ait
 * olduğu) kodda kalır — frontend `talepAlanlari` eşlemesi anahtarla bağlanır.
 *
 * Genişlik ızgarada PİKSELdir, 12'lik ızgara değil: satırlar yatay kaydırılır
 * ve kolonun içeriği kadar yer kaplaması gerekir (kur alanı "46,752500"
 * yazabilmeli).
 */
final class SatinalmaTalebiSatirKatalogu
{
    /**
     * @return list<array{anahtar: string, etiket_anahtari: string, varsayilan_genislik: int, kaldirilamaz?: bool, salt_okunur_sabit?: bool}>
     */
    public static function alanlar(): array
    {
        return array_map(
            static fn (array $alan): array => [
                'anahtar' => $alan[0],
                'etiket_anahtari' => 'satinalma.alan.'.$alan[1],
                'varsayilan_genislik' => $alan[2],
                'kaldirilamaz' => $alan[3] ?? false,
                // ERP'nin hesapladığı/doldurduğu kolonlara kullanıcı giremez
                'salt_okunur_sabit' => $alan[4] ?? false,
            ],
            [
                ['projemiz', 'projemiz', 112, false, true],
                ['aktivite_kodu', 'aktiviteKodu', 208],
                ['aktivite_aciklamasi', 'aktiviteAciklamasi', 208],
                ['masraf_merkezi_kodu', 'masrafMerkeziKodu', 208],
                ['masraf_merkezi_adi', 'masrafMerkeziAdi', 208],
                // Ürün satırın kimliği: kaldırılamaz
                ['urun_kodu', 'urunKodu', 208, true],
                ['barkod', 'barkod', 208],
                ['urun_adi', 'urunAdi', 208],
                ['ekipman_adi', 'ekipmanAdi', 208],
                ['butce_kalemi', 'butceKalemi', 208],
                ['butce_bolumu', 'butceBolumu', 208],
                ['duran_varlik', 'duranVarlik', 208],
                ['personel_adi', 'personelAdi', 208],
                ['urun_tarifi', 'urunTarifi', 144],
                ['ambalaj', 'ambalaj', 208],
                ['ambalaj_miktari', 'ambalajMiktari', 144],
                ['darali_miktar', 'daraliMiktar', 144],
                ['dara', 'dara', 144],
                ['miktar', 'miktar', 160],
                ['birim_fiyati', 'birimFiyati', 240],
                ['birim_fiyati_kuru', 'birimFiyatiKuru', 144],
                ['tutar', 'tutar', 144, false, true],
                ['tutar_yp', 'tutarYp', 144, false, true],
                ['teklif_birim_fiyati', 'teklifBirimFiyati', 240],
                ['teklif_kuru', 'teklifKuru', 144],
                ['teklif_tutari', 'teklifTutari', 144, false, true],
                ['butce_birim_fiyati', 'butceBirimFiyati', 240],
                ['butce_birim_fiyati_kuru', 'butceBirimFiyatiKuru', 144],
                ['butce_birim_tutari', 'butceBirimTutari', 144, false, true],
                ['teslim_tarihi', 'teslimTarihi', 160],
                ['teslim_suresi', 'teslimSuresi', 208],
                ['poz_no', 'pozNo', 144],
                ['ozel_no', 'ozelNo', 144],
                ['satir_hakkinda', 'satirHakkinda', 144],
                ['kapandi', 'kapandi', 112],
                // Aşağıdakileri ERP doldurur
                ['butce_tutari', 'butceTutari', 144, false, true],
                ['gerceklesen_maliyet', 'gerceklesenMaliyet', 144, false, true],
                ['gerceklesme_kuru', 'gerceklesmeKuru', 144, false, true],
                ['gerceklesme_orani', 'gerceklesmeOrani', 144, false, true],
            ],
        );
    }

    /** Tasarım yapılmadan önce ekranın bugünkü hali (tüm kolonlar, sırasıyla) */
    public static function varsayilanDuzen(): array
    {
        return array_map(
            static fn (array $alan): array => [
                'alan' => $alan['anahtar'],
                'genislik' => $alan['varsayilan_genislik'],
            ],
            self::alanlar(),
        );
    }
}
