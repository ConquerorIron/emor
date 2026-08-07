import type { SATIR_KAYITLARI, SatirKaynagi } from './satirKayitlari'
import type { TalepSatiri } from './talepSchema'

/**
 * Satınalma Talebi SATIR kolonları.
 *
 * NOT: Başlık alanları artık burada değil — ekran tasarım motoruna taşındı
 * (backend App\Ekranlar\SatinalmaTalebiKatalogu + ekran_tasarimlari tablosu).
 * Satırlar da sırası gelince aynı motora taşınacak; o zaman bu liste backend
 * katalogundan gelecek, hücre çizimi ise burada kaldığı gibi kalacak.
 */

/** Hücrenin nasıl çizildiği */
export type SatirHucreTanimi =
  /** Serbest metin — değeri satırda kendi anahtarında durur */
  | { tip: 'metin'; ad: keyof TalepSatiri }
  /**
   * Sayısal giriş. Ondalık basamak sayısı ERP'de sabit değildir: seçilen
   * kaydın ölçü sisteminden gelir ve `basamakAnahtari` ile satırdan okunur
   * (ör. Miktar → ürünün birimi, Amb. miktarı → kabın kapasite birimi).
   */
  | { tip: 'sayi'; ad: keyof TalepSatiri; basamakAnahtari: keyof TalepSatiri }
  /** Kullanıcı giremez; değer başlıktan türer (satırda saklanmaz) */
  | { tip: 'yansima' }
  /**
   * ERP kaydının bir yüzü — seçim tüm yüzleri birden doldurur. `goster`
   * yalnız o kaydın tanımlı yüzlerinden biri olabilir (satirKayitlari).
   */
  | {
      [K in SatirKaynagi]: {
        tip: 'secim'
        kaynak: K
        goster: keyof (typeof SATIR_KAYITLARI)[K]['yuzler'] & string
      }
    }[SatirKaynagi]

export interface TalepAlani {
  /** i18n etiketi (satinalma.alan.*) */
  etiketAnahtari: string
  hucre: SatirHucreTanimi
  /** Zorunlu kolonlar başlıkta * ile işaretlenir */
  zorunlu?: boolean
}

/** Satır grid kolonları (# ve sil kolonu hariç) */
export const SATIR_ALANLARI: TalepAlani[] = [
  // Proje başlıktan gelir ve satırda kilitlidir (kullanıcı kararı 2026-08-07)
  { etiketAnahtari: 'projemiz', hucre: { tip: 'yansima' } },
  { etiketAnahtari: 'aktiviteKodu', hucre: { tip: 'secim', kaynak: 'aktivite', goster: 'kod' } },
  {
    etiketAnahtari: 'aktiviteAciklamasi',
    hucre: { tip: 'secim', kaynak: 'aktivite', goster: 'aciklama' },
  },
  {
    etiketAnahtari: 'masrafMerkeziKodu',
    hucre: { tip: 'secim', kaynak: 'masrafMerkezi', goster: 'kod' },
  },
  {
    etiketAnahtari: 'masrafMerkeziAdi',
    hucre: { tip: 'secim', kaynak: 'masrafMerkezi', goster: 'ad' },
  },
  // Ürünün üç yüzü: birinden seçmek yeterli, diğer ikisi dolar
  {
    etiketAnahtari: 'urunKodu',
    hucre: { tip: 'secim', kaynak: 'urun', goster: 'kod' },
    zorunlu: true,
  },
  { etiketAnahtari: 'barkod', hucre: { tip: 'secim', kaynak: 'urun', goster: 'barkod' } },
  { etiketAnahtari: 'urunAdi', hucre: { tip: 'secim', kaynak: 'urun', goster: 'ad' } },

  // ——— Henüz tarif edilmemiş kolonlar: serbest metin olarak duruyorlar.
  // Her biri tarif edildikçe kendi ERP tipine bağlanacak (seçim listesi, sayı,
  // tarih, evet/hayır); sıra ERP ekranındaki sırayla birebir.
  { etiketAnahtari: 'ekipmanAdi', hucre: { tip: 'secim', kaynak: 'ekipman', goster: 'ad' } },
  { etiketAnahtari: 'butceKalemi', hucre: { tip: 'secim', kaynak: 'butceKalemi', goster: 'ad' } },
  { etiketAnahtari: 'butceBolumu', hucre: { tip: 'secim', kaynak: 'butceBolumu', goster: 'ad' } },
  { etiketAnahtari: 'duranVarlik', hucre: { tip: 'secim', kaynak: 'duranVarlik', goster: 'ad' } },
  { etiketAnahtari: 'personelAdi', hucre: { tip: 'secim', kaynak: 'personel', goster: 'ad' } },
  { etiketAnahtari: 'urunTarifi', hucre: { tip: 'metin', ad: 'urun_tarifi' } },
  { etiketAnahtari: 'ambalaj', hucre: { tip: 'secim', kaynak: 'ambalaj', goster: 'ad' } },
  {
    etiketAnahtari: 'ambalajMiktari',
    hucre: { tip: 'sayi', ad: 'ambalaj_miktari', basamakAnahtari: 'ambalaj_basamak_sayisi' },
  },
  { etiketAnahtari: 'daraliMiktar', hucre: { tip: 'metin', ad: 'darali_miktar' } },
  {
    etiketAnahtari: 'miktar',
    hucre: { tip: 'sayi', ad: 'miktar', basamakAnahtari: 'urun_basamak_sayisi' },
  },
  { etiketAnahtari: 'birimFiyati', hucre: { tip: 'metin', ad: 'birim_fiyati' } },
  { etiketAnahtari: 'birimFiyatiKuru', hucre: { tip: 'metin', ad: 'birim_fiyati_kuru' } },
  { etiketAnahtari: 'tutar', hucre: { tip: 'metin', ad: 'tutar' } },
  { etiketAnahtari: 'tutarYp', hucre: { tip: 'metin', ad: 'tutar_yp' } },
  { etiketAnahtari: 'teklifBirimFiyati', hucre: { tip: 'metin', ad: 'teklif_birim_fiyati' } },
  { etiketAnahtari: 'teklifKuru', hucre: { tip: 'metin', ad: 'teklif_kuru' } },
  { etiketAnahtari: 'teklifTutari', hucre: { tip: 'metin', ad: 'teklif_tutari' } },
  { etiketAnahtari: 'butceBirimFiyati', hucre: { tip: 'metin', ad: 'butce_birim_fiyati' } },
  {
    etiketAnahtari: 'butceBirimFiyatiKuru',
    hucre: { tip: 'metin', ad: 'butce_birim_fiyati_kuru' },
  },
  { etiketAnahtari: 'butceBirimTutari', hucre: { tip: 'metin', ad: 'butce_birim_tutari' } },
  { etiketAnahtari: 'teslimTarihi', hucre: { tip: 'metin', ad: 'teslim_tarihi' } },
  { etiketAnahtari: 'teslimSuresi', hucre: { tip: 'metin', ad: 'teslim_suresi' } },
  { etiketAnahtari: 'pozNo', hucre: { tip: 'metin', ad: 'poz_no' } },
  { etiketAnahtari: 'ozelNo', hucre: { tip: 'metin', ad: 'ozel_no' } },
  { etiketAnahtari: 'satirHakkinda', hucre: { tip: 'metin', ad: 'hakkinda' } },
  { etiketAnahtari: 'kapandi', hucre: { tip: 'metin', ad: 'kapandi' } },
  { etiketAnahtari: 'butceTutari', hucre: { tip: 'metin', ad: 'butce_tutari' } },
  { etiketAnahtari: 'gerceklesenMaliyet', hucre: { tip: 'metin', ad: 'gerceklesen_maliyet' } },
  { etiketAnahtari: 'gerceklesmeKuru', hucre: { tip: 'metin', ad: 'gerceklesme_kuru' } },
  { etiketAnahtari: 'gerceklesmeOrani', hucre: { tip: 'metin', ad: 'gerceklesme_orani' } },
  { etiketAnahtari: 'kullanimAmaci', hucre: { tip: 'metin', ad: 'kullanim_amaci' } },
]
