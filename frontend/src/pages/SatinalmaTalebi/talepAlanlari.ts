import type { FiyatGrubuAdi } from './fiyatGruplari'
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
  | {
      tip: 'sayi'
      ad: keyof TalepSatiri
      /** Basamak sayısı satırdaki bir değerden okunur (ölçü sistemi) */
      basamakAnahtari?: keyof TalepSatiri
      /** …ya da sabittir (ör. kur alanları 6 basamak) */
      sabitBasamak?: number
      /** Sayının sağında gösterilecek birim (ERP'deki "1,000000 Ad") */
      sonEkAnahtari?: keyof TalepSatiri
    }
  /**
   * Teslim süresi ve teslim tarihi AYNI VERİNİN iki yüzü (başlıktaki desenin
   * satır karşılığı): süre + birim saklanır, tarih talep tarihinden türer.
   * ERP'de de öyle — satır tablosunda teslim tarihi kolonu yoktur
   * (TOHOM_ISKELE_EVRAK_DETAYI: TESLIMAT_SURESI + TESLIMAT_SURESI_BIRIMI).
   */
  | { tip: 'sure'; ad: keyof TalepSatiri; birimAnahtari: keyof TalepSatiri }
  | { tip: 'sureTarih'; ad: keyof TalepSatiri; birimAnahtari: keyof TalepSatiri }
  /** Fiyat + para birimi birlikte; para seçimi kuru da doldurur */
  | { tip: 'fiyat'; grup: FiyatGrubuAdi }
  /** Hesaplanan, salt okunur tutar; `kurUygula=false` yabancı para tutarıdır */
  | { tip: 'tutar'; grup: FiyatGrubuAdi; kurUygula: boolean }
  /** Kullanıcı giremez; değer başlıktan türer (satırda saklanmaz) */
  | { tip: 'yansima' }
  /** ERP BOOL — anahtar */
  | { tip: 'evetHayir'; ad: keyof TalepSatiri }
  /**
   * ERP'nin doldurduğu, kullanıcının giremeyeceği sayısal alan (bütçe tutarı,
   * gerçekleşen maliyet…). Hesaplanan `tutar`dan farkı: değer satırda saklanır,
   * biz yalnız gösteririz.
   */
  | { tip: 'saltOkunurSayi'; ad: keyof TalepSatiri; basamak: number; yuzde?: boolean }
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

/**
 * Sütunun asgari genişliği — hücre tipine göre. TEK yerde durur ve hem başlığa
 * hem hücreye uygulanır; ikisi ayrı yazıldığında başlık ile hücre kayıyor.
 *
 * Izgara ekrana sığmaz, yatay kaydırılır: sütunlar birbirini ezmesin diye
 * içeriğin gerçekten girilebileceği genişlik verilir (ör. kur alanı
 * "46,752500" yazacak kadar geniş olmalı). Genişlikler ekran tasarım motoruna
 * taşınınca kullanıcı bunları kendisi ayarlayacak.
 */
export function sutunGenisligi(hucre: SatirHucreTanimi): string {
  switch (hucre.tip) {
    case 'yansima':
      return 'min-w-28'
    case 'secim':
      return 'min-w-52'
    // Sayı + birim seçici yan yana
    case 'sure':
      return 'min-w-52'
    case 'sureTarih':
      return 'min-w-40'
    // Fiyat hücresinde sayının yanında bir de para birimi seçici var
    case 'fiyat':
      return 'min-w-60'
    // Birim gösterilen sayılarda ("1,000000 Ad") sayıya kalan yer daralır
    case 'sayi':
      return hucre.sonEkAnahtari ? 'min-w-40' : 'min-w-32'
    case 'tutar':
      return 'min-w-36'
    case 'saltOkunurSayi':
      return 'min-w-36'
    case 'evetHayir':
      return 'min-w-28'
    default:
      return 'min-w-36'
  }
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
  // Amb. miktarı ile aynı desen: hassasiyeti seçilen ambalajın ölçü sisteminden
  {
    etiketAnahtari: 'daraliMiktar',
    hucre: { tip: 'sayi', ad: 'darali_miktar', basamakAnahtari: 'ambalaj_basamak_sayisi' },
  },
  // ERP ekranında Daralı miktar ile Miktar arasında durur
  {
    etiketAnahtari: 'dara',
    hucre: { tip: 'sayi', ad: 'dara', basamakAnahtari: 'ambalaj_basamak_sayisi' },
  },
  {
    etiketAnahtari: 'miktar',
    hucre: {
      tip: 'sayi',
      ad: 'miktar',
      basamakAnahtari: 'urun_basamak_sayisi',
      sonEkAnahtari: 'urun_birimi',
    },
  },
  // Üç fiyat grubu da aynı desende: fiyat+para, kur (6 basamak), hesaplanan tutar
  { etiketAnahtari: 'birimFiyati', hucre: { tip: 'fiyat', grup: 'birim' } },
  {
    etiketAnahtari: 'birimFiyatiKuru',
    hucre: { tip: 'sayi', ad: 'birim_fiyati_kuru', sabitBasamak: 6 },
  },
  { etiketAnahtari: 'tutar', hucre: { tip: 'tutar', grup: 'birim', kurUygula: true } },
  { etiketAnahtari: 'tutarYp', hucre: { tip: 'tutar', grup: 'birim', kurUygula: false } },
  { etiketAnahtari: 'teklifBirimFiyati', hucre: { tip: 'fiyat', grup: 'teklif' } },
  { etiketAnahtari: 'teklifKuru', hucre: { tip: 'sayi', ad: 'teklif_kuru', sabitBasamak: 6 } },
  { etiketAnahtari: 'teklifTutari', hucre: { tip: 'tutar', grup: 'teklif', kurUygula: true } },
  { etiketAnahtari: 'butceBirimFiyati', hucre: { tip: 'fiyat', grup: 'butce' } },
  {
    etiketAnahtari: 'butceBirimFiyatiKuru',
    hucre: { tip: 'sayi', ad: 'butce_birim_fiyati_kuru', sabitBasamak: 6 },
  },
  { etiketAnahtari: 'butceBirimTutari', hucre: { tip: 'tutar', grup: 'butce', kurUygula: true } },
  {
    etiketAnahtari: 'teslimTarihi',
    hucre: { tip: 'sureTarih', ad: 'teslim_suresi', birimAnahtari: 'teslim_suresi_birimi' },
  },
  {
    etiketAnahtari: 'teslimSuresi',
    hucre: { tip: 'sure', ad: 'teslim_suresi', birimAnahtari: 'teslim_suresi_birimi' },
  },
  { etiketAnahtari: 'pozNo', hucre: { tip: 'metin', ad: 'poz_no' } },
  { etiketAnahtari: 'ozelNo', hucre: { tip: 'metin', ad: 'ozel_no' } },
  { etiketAnahtari: 'satirHakkinda', hucre: { tip: 'metin', ad: 'hakkinda' } },
  { etiketAnahtari: 'kapandi', hucre: { tip: 'evetHayir', ad: 'kapandi' } },
  // Aşağıdaki dördünü ERP doldurur; kullanıcı giremez (kullanıcı kararı 2026-08-07)
  {
    etiketAnahtari: 'butceTutari',
    hucre: { tip: 'saltOkunurSayi', ad: 'butce_tutari', basamak: 2 },
  },
  {
    etiketAnahtari: 'gerceklesenMaliyet',
    hucre: { tip: 'saltOkunurSayi', ad: 'gerceklesen_maliyet', basamak: 2 },
  },
  {
    etiketAnahtari: 'gerceklesmeKuru',
    hucre: { tip: 'saltOkunurSayi', ad: 'gerceklesme_kuru', basamak: 6 },
  },
  {
    etiketAnahtari: 'gerceklesmeOrani',
    hucre: { tip: 'saltOkunurSayi', ad: 'gerceklesme_orani', basamak: 2, yuzde: true },
  },
  { etiketAnahtari: 'kullanimAmaci', hucre: { tip: 'metin', ad: 'kullanim_amaci' } },
]
