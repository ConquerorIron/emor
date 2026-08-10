/**
 * Ekran tasarım motoru tipleri. Katalog KOD tarafında (backend) tanımlıdır ve
 * API ile gelir; düzen ise kullanıcının sürükle-bırakla ürettiği veridir.
 */

/** Alanın NE olduğu — kullanıcı değiştiremez */
export interface KatalogAlani {
  anahtar: string
  etiket_anahtari: string
  /** Frontend kayıt defterindeki bileşen anahtarı */
  giris_tipi: string
  /** Alanın form verisine yazdığı anahtarlar (bir alan birden fazla yazabilir) */
  veri_anahtarlari: string[]
  proc_parametresi: string
  varsayilan_genislik: number
  /** Tasarımdan çıkarılamaz (proc onsuz çalışmaz) */
  kaldirilamaz: boolean
  /** Değerini program üretir; düzenlenebilir yapılamaz (ör. No) */
  salt_okunur_sabit: boolean
  zorunlu_secilebilir: boolean
  /** Serbest metin mi (textarea seçeneği yalnız bunlarda) */
  metin_alani: boolean
  /** ERP domain tipinin karakter sınırı (ACIKLAMA200 → 200); 0 = sınırsız */
  metin_limiti: number
  /**
   * Bu alanın OKUDUĞU/SIFIRLADIĞI diğer alanın veri anahtarı. Giriş bileşenleri
   * ekrandan bağımsız kalsın diye bağlantı kodda değil katalogda tanımlıdır
   * (ör. ilgi cinsi → ilgili_id, teslimat süresi tarihi → tarih).
   */
  bagli_veri_anahtari: string
}

/** Alanın NASIL göründüğü — tasarımcı belirler */
export interface DuzenAlani {
  alan: string
  genislik: number
  zorunlu?: boolean
  salt_okunur?: boolean
  /**
   * Formda ÇİZİLMEZ ama değeri (varsayilan) kayda gider — ERP'deki
   * "alanı göstermiyoruz, hep şu değer seçili" davranışı.
   */
  gizli?: boolean
  gorunum?: 'textarea'
  satir?: number
  varsayilan?: string
}

export interface DuzenBolumu {
  anahtar: string
  genislik: number
  /**
   * Tasarımcının yazdığı başlık. Alan YOKSA katalogun i18n başlığı kullanılır
   * (çeviri korunur); boş dizge ise "başlık gösterme" demektir.
   */
  baslik?: string
  alanlar: DuzenAlani[]
}

/** Satır ızgarasının bir kolonu — genişlik PİKSEL (yatay kaydırmalı ızgara) */
export interface DuzenSatirKolonu {
  alan: string
  genislik: number
  gizli?: boolean
}

export interface EkranDuzeni {
  bolumler: DuzenBolumu[]
  /** Satır ızgarası düzeni: sıra + görünürlük + piksel genişlik */
  satirlar?: DuzenSatirKolonu[]
  /**
   * Talep onaya sunulurken kullanılacak ERP onay rolü (ROL_ID).
   * Alan değil, ekranın ayarıdır — tasarımla birlikte sürümlenir.
   */
  onay_rol_id?: number | null
}

export interface BolumTanimi {
  anahtar: string
  /** i18n anahtarı — tasarımcı değiştiremez (çoklu dil korunur) */
  baslik_anahtari: string
}

/** Satır ızgarası kolonunun katalog tanımı (backend SatinalmaTalebiSatirKatalogu) */
export interface SatirKatalogAlani {
  anahtar: string
  etiket_anahtari: string
  varsayilan_genislik: number
  kaldirilamaz: boolean
  salt_okunur_sabit: boolean
}

export interface EkranTasarimi {
  ekran: string
  satir_katalogu?: SatirKatalogAlani[]
  baslik_anahtari: string
  bolumler: BolumTanimi[]
  katalog: KatalogAlani[]
  duzen: EkranDuzeni
}

/**
 * Tailwind sınıfları derleme anında taranır; `col-span-${n}` gibi dinamik
 * isimler üretilemez — bu yüzden açık eşleme.
 *
 * İki kırılma noktası var: alanlar dar ekranda erken (sm) yan yana gelebilir,
 * BÖLÜMLER ise ancak çok geniş ekranda (xl) yan yana durur — aksi halde iki
 * panel 800px'lik ekranda sıkışırdı.
 */
const ALAN_SINIFLARI: Record<number, string> = {
  1: 'sm:col-span-1',
  2: 'sm:col-span-2',
  3: 'sm:col-span-3',
  4: 'sm:col-span-4',
  5: 'sm:col-span-5',
  6: 'sm:col-span-6',
  7: 'sm:col-span-7',
  8: 'sm:col-span-8',
  9: 'sm:col-span-9',
  10: 'sm:col-span-10',
  11: 'sm:col-span-11',
  12: 'sm:col-span-12',
}

const BOLUM_SINIFLARI: Record<number, string> = {
  1: 'xl:col-span-1',
  2: 'xl:col-span-2',
  3: 'xl:col-span-3',
  4: 'xl:col-span-4',
  5: 'xl:col-span-5',
  6: 'xl:col-span-6',
  7: 'xl:col-span-7',
  8: 'xl:col-span-8',
  9: 'xl:col-span-9',
  10: 'xl:col-span-10',
  11: 'xl:col-span-11',
  12: 'xl:col-span-12',
}

/**
 * Zorunlu alanların etiketine yıldız ekler. Etiketini kendi üreten girişler de
 * (ör. ilgi cinsine göre adı değişen bağlı alan) bunu kullanmalı — yoksa
 * zorunluluk işareti sessizce kaybolur.
 */
export function zorunluEtiket(metin: string, duzen: DuzenAlani): string {
  return duzen.zorunlu === true ? `${metin} *` : metin
}

export function genislikSinifi(genislik: number): string {
  return ALAN_SINIFLARI[genislik] ?? ALAN_SINIFLARI[6]
}

export function bolumGenisligiSinifi(genislik: number): string {
  return BOLUM_SINIFLARI[genislik] ?? BOLUM_SINIFLARI[6]
}
