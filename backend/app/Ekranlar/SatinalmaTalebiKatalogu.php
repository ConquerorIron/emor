<?php

declare(strict_types=1);

namespace App\Ekranlar;

/**
 * Satınalma Talebi ekranının alan kataloğu — ERP'nin "Satınalma Talep Formu"
 * başlık alanlarının karşılığı. Değerler SOHOM_SIPARIS_KAYDET parametrelerine
 * eşlenir. Talep SATIRLARI ayrı bir bölüm olarak sonra eklenecek.
 */
final class SatinalmaTalebiKatalogu implements EkranKatalogu
{
    public const ANAHTAR = 'satinalma.talep';

    public const BOLUM_TALEP = 'talep';

    public const BOLUM_TESLIMAT = 'teslimat';

    public function anahtar(): string
    {
        return self::ANAHTAR;
    }

    public function baslikAnahtari(): string
    {
        return 'satinalma.baslik';
    }

    public function bolumler(): array
    {
        return [
            ['anahtar' => self::BOLUM_TALEP, 'baslik_anahtari' => 'satinalma.talepBilgileri'],
            ['anahtar' => self::BOLUM_TESLIMAT, 'baslik_anahtari' => 'satinalma.teslimatBilgileri'],
        ];
    }

    public function alanlar(): array
    {
        return [
            new KatalogAlani(
                anahtar: 'personel_adi',
                etiketAnahtari: 'satinalma.alan.personelAdi',
                girisTipi: 'personel',
                veriAnahtarlari: ['personel_id', 'personel_adi'],
                procParametresi: 'PARTI_YAMASI_ID',
                kaldirilamaz: true,
            ),
            new KatalogAlani(
                anahtar: 'no',
                etiketAnahtari: 'satinalma.alan.no',
                girisTipi: 'metin',
                veriAnahtarlari: ['no'],
                procParametresi: 'SIPARIS_NO (OUTPUT)',
                // Değeri SOHOM_NUMERATOR_URET üretir; kullanıcı yazamaz
                saltOkunurSabit: true,
                zorunluSecilebilir: false,
            ),
            new KatalogAlani(
                anahtar: 'tarih',
                etiketAnahtari: 'satinalma.alan.tarih',
                girisTipi: 'tarih',
                veriAnahtarlari: ['tarih'],
                procParametresi: 'TARIH',
                kaldirilamaz: true,
            ),
            new KatalogAlani(
                anahtar: 'termin',
                etiketAnahtari: 'satinalma.alan.termin',
                girisTipi: 'opsiyonelTarih',
                veriAnahtarlari: ['termin'],
                procParametresi: 'OPSIYON_TARIHI',
            ),
            new KatalogAlani(
                anahtar: 'oncelik',
                etiketAnahtari: 'satinalma.alan.oncelik',
                girisTipi: 'oncelik',
                veriAnahtarlari: ['oncelik_id'],
                procParametresi: 'ONCELIK_ID',
            ),
            new KatalogAlani(
                anahtar: 'aciklama',
                etiketAnahtari: 'satinalma.alan.aciklama',
                girisTipi: 'sinirliMetin',
                veriAnahtarlari: ['aciklama'],
                procParametresi: 'ACIKLAMA (ACIKLAMA200)',
                varsayilanGenislik: 12,
                metinAlani: true,
                metinLimiti: 200,
            ),
            new KatalogAlani(
                anahtar: 'hakkinda',
                etiketAnahtari: 'satinalma.alan.hakkinda',
                girisTipi: 'sinirliMetin',
                veriAnahtarlari: ['hakkinda'],
                procParametresi: 'HAKKINDA (ACIKLAMA3072)',
                varsayilanGenislik: 12,
                metinAlani: true,
                metinLimiti: 3072,
            ),
            new KatalogAlani(
                anahtar: 'ilgi_konusu',
                etiketAnahtari: 'satinalma.alan.ilgiKonusu',
                girisTipi: 'ilgiCinsi',
                veriAnahtarlari: ['ilgi_cinsi'],
                procParametresi: 'ILGI_CINSI',
                // Cins değişince bu alan sıfırlanır
                bagliVeriAnahtari: 'ilgili_id',
            ),
            new KatalogAlani(
                anahtar: 'ilgili',
                etiketAnahtari: 'satinalma.alan.ilgili',
                girisTipi: 'ilgili',
                // Kod da saklanır: satır ızgarası seçili projeyi kodla gösterir
                veriAnahtarlari: ['ilgili_id', 'ilgili_kodu'],
                procParametresi: 'ILGILI_ID',
                // Arama kaynağı bu alandaki cinse göre belirlenir
                bagliVeriAnahtari: 'ilgi_cinsi',
            ),
            new KatalogAlani(
                anahtar: 'depo_adi',
                etiketAnahtari: 'satinalma.alan.depoAdi',
                girisTipi: 'depo',
                veriAnahtarlari: ['depomuz_id'],
                procParametresi: 'satır: DEPOMUZ_ID',
            ),
            new KatalogAlani(
                anahtar: 'teslimat_adresi',
                etiketAnahtari: 'satinalma.alan.teslimatAdresi',
                girisTipi: 'teslimatAdresi',
                veriAnahtarlari: ['teslimat_adresi_id', 'teslimat_adresi'],
                procParametresi: 'TESLIMAT_ADRESI_ID + TESLIMAT_ADRESI',
                varsayilanGenislik: 12,
            ),
            new KatalogAlani(
                anahtar: 'teslimat_bicimi',
                etiketAnahtari: 'satinalma.alan.teslimatBicimi',
                girisTipi: 'teslimatBicimi',
                veriAnahtarlari: ['teslimat_bicimi'],
                procParametresi: 'TESLIMAT_BICIMI',
            ),
            new KatalogAlani(
                anahtar: 'teslimat_sekli',
                etiketAnahtari: 'satinalma.alan.teslimatSekli',
                girisTipi: 'teslimatSekli',
                veriAnahtarlari: ['teslimat_sekli_id'],
                procParametresi: 'TESLIMAT_SEKLI_ID',
            ),
            new KatalogAlani(
                anahtar: 'teslimat_suresi_tarih',
                etiketAnahtari: 'satinalma.alan.teslimatSuresiTarih',
                girisTipi: 'teslimatSuresiTarih',
                // Süre alanıyla AYNI veriyi yazar (tarih yalnız gösterimdir)
                veriAnahtarlari: ['teslimat_suresi', 'teslimat_suresi_birimi'],
                procParametresi: 'TESLIMAT_SURESI (gösterim)',
                zorunluSecilebilir: false,
                // Süre bu tarihin üzerine eklenerek gösterilir
                bagliVeriAnahtari: 'tarih',
            ),
            new KatalogAlani(
                anahtar: 'teslimat_suresi_sure',
                etiketAnahtari: 'satinalma.alan.teslimatSuresiSure',
                girisTipi: 'teslimatSuresi',
                veriAnahtarlari: ['teslimat_suresi', 'teslimat_suresi_birimi'],
                procParametresi: 'TESLIMAT_SURESI + TESLIMAT_SURESI_BIRIMI',
            ),
            new KatalogAlani(
                anahtar: 'alim_yeri',
                etiketAnahtari: 'satinalma.alan.alimYeri',
                girisTipi: 'alimYeri',
                veriAnahtarlari: ['alim_yeri'],
                procParametresi: 'ALIM_YERI',
            ),
        ];
    }

    /**
     * Motor devreye girdiğinde ekran BUGÜNKÜ haliyle açılsın diye mevcut
     * düzenin birebir karşılığı.
     */
    public function varsayilanDuzen(): array
    {
        return [
            // Onay rolü tasarım ekranından seçilir; seçilmeden talep onaya
            // sunulamaz (ERP'de VOHOM_ARAMA_ONAY_ROLU)
            'onay_rol_id' => null,
            // Satır ızgarası da tasarlanır: sıra, görünürlük ve piksel genişlik
            'satirlar' => SatinalmaTalebiSatirKatalogu::varsayilanDuzen(),
            'bolumler' => [
                [
                    'anahtar' => self::BOLUM_TALEP,
                    'genislik' => 6,
                    'alanlar' => [
                        ['alan' => 'personel_adi', 'genislik' => 6],
                        ['alan' => 'no', 'genislik' => 6, 'salt_okunur' => true],
                        ['alan' => 'tarih', 'genislik' => 6],
                        ['alan' => 'termin', 'genislik' => 6],
                        ['alan' => 'oncelik', 'genislik' => 6],
                        ['alan' => 'aciklama', 'genislik' => 12, 'gorunum' => 'textarea', 'satir' => 2],
                        ['alan' => 'ilgi_konusu', 'genislik' => 6],
                        ['alan' => 'ilgili', 'genislik' => 6],
                        ['alan' => 'hakkinda', 'genislik' => 12, 'gorunum' => 'textarea', 'satir' => 3],
                    ],
                ],
                [
                    'anahtar' => self::BOLUM_TESLIMAT,
                    'genislik' => 6,
                    'alanlar' => [
                        ['alan' => 'depo_adi', 'genislik' => 6],
                        ['alan' => 'teslimat_adresi', 'genislik' => 12],
                        ['alan' => 'teslimat_bicimi', 'genislik' => 6],
                        ['alan' => 'teslimat_sekli', 'genislik' => 6],
                        ['alan' => 'teslimat_suresi_tarih', 'genislik' => 6],
                        ['alan' => 'teslimat_suresi_sure', 'genislik' => 6],
                        ['alan' => 'alim_yeri', 'genislik' => 6],
                    ],
                ],
            ],
        ];
    }
}
