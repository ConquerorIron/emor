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
     * ERP'nin bu belge için tanımladığı satır özel alanları verildiğinde
     * (VOHOM_EVRAK_OZELLIGI, EVRAK_KISMI = 1) katalogun sonuna eklenir.
     *
     * @param  list<array{ozellik_id: int, ad: string, zorunlu: bool}>  $ozellikler
     * @return list<array<string, mixed>>
     */
    public static function alanlar(array $ozellikler = []): array
    {
        // ERP'de tanımlı özel alanlar KAPATILAMAZ: belge onları bekliyor
        // (kullanıcı kararı 2026-08-10). Etiket ERP'den gelir, i18n anahtarı yok.
        $ozellikAlanlari = array_map(
            static fn (array $ozellik): array => [
                'anahtar' => 'ozellik_'.$ozellik['ozellik_id'],
                'etiket_anahtari' => '',
                'etiket' => $ozellik['ad'],
                'varsayilan_genislik' => 208,
                'kaldirilamaz' => true,
                'salt_okunur_sabit' => false,
                'ozellik_id' => $ozellik['ozellik_id'],
                'zorunlu' => $ozellik['zorunlu'],
            ],
            $ozellikler,
        );

        return [...self::programAlanlari(), ...$ozellikAlanlari];
    }

    /** @return list<array<string, mixed>> */
    private static function programAlanlari(): array
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
                ['aktiviteKodu', 'aktiviteKodu', 208],
                ['aktiviteAciklamasi', 'aktiviteAciklamasi', 208],
                ['masrafMerkeziKodu', 'masrafMerkeziKodu', 208],
                ['masrafMerkeziAdi', 'masrafMerkeziAdi', 208],
                // Ürün satırın kimliği: kaldırılamaz
                ['urunKodu', 'urunKodu', 208, true],
                ['barkod', 'barkod', 208],
                ['urunAdi', 'urunAdi', 208],
                ['ekipmanAdi', 'ekipmanAdi', 208],
                ['butceKalemi', 'butceKalemi', 208],
                ['butceBolumu', 'butceBolumu', 208],
                ['duranVarlik', 'duranVarlik', 208],
                ['personelAdi', 'personelAdi', 208],
                ['urunTarifi', 'urunTarifi', 144],
                ['ambalaj', 'ambalaj', 208],
                ['ambalajMiktari', 'ambalajMiktari', 144],
                ['daraliMiktar', 'daraliMiktar', 144],
                ['dara', 'dara', 144],
                ['miktar', 'miktar', 160],
                ['birimFiyati', 'birimFiyati', 240],
                ['birimFiyatiKuru', 'birimFiyatiKuru', 144],
                ['tutar', 'tutar', 144, false, true],
                ['tutarYp', 'tutarYp', 144, false, true],
                ['teklifBirimFiyati', 'teklifBirimFiyati', 240],
                ['teklifKuru', 'teklifKuru', 144],
                ['teklifTutari', 'teklifTutari', 144, false, true],
                ['butceBirimFiyati', 'butceBirimFiyati', 240],
                ['butceBirimFiyatiKuru', 'butceBirimFiyatiKuru', 144],
                ['butceBirimTutari', 'butceBirimTutari', 144, false, true],
                ['teslimTarihi', 'teslimTarihi', 160],
                ['teslimSuresi', 'teslimSuresi', 208],
                ['pozNo', 'pozNo', 144],
                ['ozelNo', 'ozelNo', 144],
                ['satirHakkinda', 'satirHakkinda', 144],
                ['kapandi', 'kapandi', 112],
                // Aşağıdakileri ERP doldurur
                ['butceTutari', 'butceTutari', 144, false, true],
                ['gerceklesenMaliyet', 'gerceklesenMaliyet', 144, false, true],
                ['gerceklesmeKuru', 'gerceklesmeKuru', 144, false, true],
                ['gerceklesmeOrani', 'gerceklesmeOrani', 144, false, true],
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
