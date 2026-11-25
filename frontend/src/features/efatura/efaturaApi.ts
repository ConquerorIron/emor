import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

export type FaturaYonu = 'gelen' | 'giden'

/** Listenin kaynağı: İzibiz ekranları ya da bütün kaynakların birleşik listesi (Tüm Faturalar, yalnız gelen) */
export type FaturaKapsami = 'izibiz' | 'tum'

/**
 * Faturanın geldiği kaynak: entegratörler (İzibiz, Protel) ve statik kaynaklar
 * (Logo, e-Arşiv / e-SMM — Excel içe aktarımıyla gelir).
 */
export type Saglayici = 'izibiz' | 'protel' | 'logo' | 'earsiv'

/** Pano ve Tüm Faturalar sırası (kullanıcının örneği: Logo, Portal Plus, İzibiz, E-Arşiv) */
export const GELEN_SAGLAYICILARI: readonly Saglayici[] = ['logo', 'protel', 'izibiz', 'earsiv']

/**
 * Faturanın sınıfı (sunucudaki tek tanım): işlendi = eMOR'da ya da SAP'de
 * işlendi; islenecek = RED değil, işlenmemiş; red = kaynakta ya da elle RED;
 * red_islenmis = RED olduğu hâlde işlenmiş (pano uyarısı).
 */
export type FaturaSinifi = '' | 'islendi' | 'islenecek' | 'red' | 'red_islenmis'

/** RED bilgisi: kaynakta (entegratör / liste) ya da yetkili kullanıcının çektiği (harici) */
export interface FaturaRed {
  var: boolean
  kaynak: 'kaynak' | 'harici' | null
  /** Yalnız harici RED: ne zaman, kim, neden */
  zaman: string | null
  eden: string | null
  neden: string | null
}

function listeYolu(kapsam: FaturaKapsami, yon: FaturaYonu): string {
  return kapsam === 'tum' ? '/api/v1/tum-faturalar/faturalar' : `/api/v1/efatura/${yon}/faturalar`
}

function faturaYolu(kapsam: FaturaKapsami, faturaId: number): string {
  return kapsam === 'tum'
    ? `/api/v1/tum-faturalar/faturalar/${faturaId}`
    : `/api/v1/efatura/faturalar/${faturaId}`
}

export interface EFatura {
  id: number
  yon: FaturaYonu
  /** Entegratörün iç kimliği (ETTN değil); statik kaynakta null */
  kaynak_id: number | null
  /** e-Arşiv / e-SMM listesinde ETTN yok */
  ettn: string | null
  belge_no: string
  belge_tarihi: string
  belge_saati: string | null
  olusturma_zamani: string | null
  fatura_tipi: string | null
  senaryo: string | null
  para_birimi: string
  /** Numeric metin (ör. "1250.5000") — float'a çevrilmeden gösterilir */
  tutar: string
  vergi_tutari: string | null
  satir_sayisi: number | null
  gonderici_vkn: string | null
  gonderici_unvan: string | null
  alici_vkn: string | null
  alici_unvan: string | null
  durum: string | null
  durum_aciklamasi: string | null
  gib_durum_kodu: number | null
  gib_durum_aciklamasi: string | null
  /** İzibiz'in ERP okundu bayrağı — yalnız gösterilir, bu uygulama değiştirmez */
  erp_okundu: boolean | null
  /** ERP'deki aşama; null = henüz kontrol edilmedi (havuzda yalnız gelende) */
  emor_durumu: EmorDurumu | null
  /** Vergi (KDV) istisna kodu — ERP'nin TOHOM_E_FATURA kaydından; yalnız gelen */
  vergi_istisna_kodu: string | null
  /** Vergi istisna kodu — entegratördeki UBL'den (esas); birden çok kod virgülle */
  izibiz_istisna_kodu: string | null
  okundu: boolean | null
  yanit_aciklamasi: string | null
  son_gorulme: string | null
  gonderici_ad_soyad: string | null
  alici_ad_soyad: string | null
  /** GİB gönderici birim etiketi (GB) */
  gonderici_etiketi: string | null
  /** GİB posta kutusu etiketi (PK) */
  alici_etiketi: string | null
  irsaliye_no: string | null
  siparis_no: string | null
  siparis_tarihi: string | null
  gtb_ref_no: string | null
  gcb_tescil_no: string | null
  gcb_tarihi: string | null
  portal_notu: string | null
  teslim_ref: string | null
  harici_aktarim: boolean | null
  mail_durumu: string | null
  /** Protel `erpStatus` (New, Booked, Cancelled…) — İzibiz'de null; yalnız gösterilir */
  erp_durumu: string | null
  /** Protel `isPrinted` — İzibiz'de null; PDF yalnız true iken açılır */
  yazdirildi: boolean | null
  /** Listede gizlendi mi (bize ait değil — ör. yanlış posta kutusu); silinmez */
  gizli: boolean
  gizlenme_zamani: string | null
  /** Gizleyen kullanıcının adı */
  gizleyen: string | null
  /** Protel `isErpRecieved` — otelin ERP'si aldı mı (İzibiz'de null; eMOR okuması `erp_okundu`) */
  otel_erp_okundu: boolean | null
  /** SAP'ye işlendi mi: Protel / statik kaynakta "İşlendi" anahtarı; İzibiz için SAP ucu bekleniyor */
  sap_islendi: boolean | null
  /** RED fatura "İşlendi" işaretlenemez; SAP kolonunda "Red"; hızlı filtrelerde görünmez */
  red: FaturaRed
  /** e-Arşiv tanımında belge türü; diğer kaynaklarda null */
  belge_turu: 'fatura' | 'makbuz' | null
  /** e-SMM makbuzu: brüt ücret ve stopaj (tutar = tahsilat); e-Arşiv faturasında brüt = toplam */
  brut_tutar: string | null
  stopaj_tutari: string | null
  /**
   * Protel, statik kaynak ve Tüm Faturalar ekranları: karşı tarafın VKN'si
   * eMOR'daki bir kurum kartında mı (var=null: ERP okunamadı)
   */
  emor_kurum?: EmorKurumu
  /** Yalnız Tüm Faturalar: satırın kaynağı (XML yalnız İzibiz'de) */
  saglayici?: Saglayici
}

/** İşlem geçmişi kaydı (EK-03): kullanıcı adı ve belge kimliği kayıt anındaki kopyadır */
export interface EFaturaIslemi {
  id: number
  fatura_id: number | null
  yukleme_id: number | null
  saglayici: Saglayici | ''
  belge_no: string | null
  belge_kimligi: string | null
  islem: EFaturaIslemTuru
  onceki: string | null
  yeni: string | null
  neden: string | null
  ayrinti: Record<string, string | number | null> | null
  kullanici_adi: string
  zaman: string
}

export type EFaturaIslemTuru =
  | 'red_cekildi'
  | 'red_geri_alindi'
  | 'islendi_isaretlendi'
  | 'islendi_kaldirildi'
  | 'gizlendi'
  | 'gizleme_kaldirildi'
  | 'ice_aktarildi'
  | 'ice_aktarim_geri_alindi'

export const ISLEM_TURLERI: readonly EFaturaIslemTuru[] = [
  'red_cekildi',
  'red_geri_alindi',
  'islendi_isaretlendi',
  'islendi_kaldirildi',
  'gizlendi',
  'gizleme_kaldirildi',
  'ice_aktarildi',
  'ice_aktarim_geri_alindi',
]

export interface ParaBirimiOzeti {
  para_birimi: string
  adet: number
  tutar: string
  vergi_tutari: string
}

export interface EFaturaListesi {
  data: EFatura[]
  /** from: sayfanın ilk satırının genel sırası (Sıra No); boş listede null */
  meta: { current_page: number; last_page: number; total: number; from: number | null }
  ozet: ParaBirimiOzeti[]
  secenekler: {
    /** İzibiz durum kodu + Türkçe açıklaması */
    durumlar: { deger: string; aciklama: string | null }[]
    para_birimleri: string[]
    /** Seçili aralıktaki fatura tipleri ve vergi istisna kodları */
    tipler: string[]
    istisna_kodlari: string[]
    /** Aralıktaki gizlenen fatura sayısı ("Gizlenenleri de göster" yanında) */
    gizlenen_adet: number
  }
  /** Ekrandaki listenin ortamı (Tüm Faturalar'da sağlayıcı başına: `izibiz:canli,protel:canli`) */
  kapsam: { ortam: string }
  /** Yalnız Tüm Faturalar: sağlayıcı başına verinin güncelliği (bu ekranda senkron paneli yok) */
  tazelik?: SaglayiciTazeligi[]
}

export interface SaglayiciTazeligi {
  saglayici: Saglayici
  ortam: string
  /** Bugünü kapsayan son tam senkronun bitişi; hiç yoksa null */
  veri_zamani: string | null
  guncel: boolean
}

export type ErpOkunduFiltresi = '' | 'evet' | 'hayir' | 'bilinmiyor'

/** "eMOR VKN Var" süzgeci */
export type EmorVknFiltresi = '' | 'evet' | 'hayir'

/** Karşı tarafın eMOR'daki (canlı ERP) kurum kartı; var=null: ERP okunamadı */
export interface EmorKurumu {
  var: boolean | null
  kod: string | null
  ad: string | null
}

/**
 * eMOR: islendi = muhasebeleşmiş (gelen: TOHOM_FATURA / TOHOM_HARCAMA_BELGESI,
 * ETTN ile), elle_islendi = e-fatura eşleştirilmeden elle girilmiş (fatura no +
 * VKN ile bulundu), havuzda = ERP almış ama muhasebeleşmemiş (TOHOM_E_FATURA),
 * yok = ERP'de karşılığı yok.
 */
export type EmorDurumu = 'islendi' | 'elle_islendi' | 'havuzda' | 'yok'

export type EmorFiltresi = '' | EmorDurumu | 'bilinmiyor'

/** Boş: gizlenenler listede yok; dahil: onlar da gelir; yalniz: yalnız gizlenenler (pano) */
export type GizlenenlerFiltresi = '' | 'dahil' | 'yalniz'

export interface EFaturaFiltresi {
  baslangic: string
  bitis: string
  ara: string
  durum: string
  erp_okundu: ErpOkunduFiltresi
  emor: EmorFiltresi
  /** Hızlı filtreler ve pano: faturanın sınıfı (RED faturalar işlendi / işlenecek süzgeçlerine gelmez) */
  sinif: FaturaSinifi
  /** Yalnız Tüm Faturalar: tek kaynağın faturaları */
  entegrator: '' | Saglayici
  para_birimi: string
  tip: string
  istisna_kodu: string
  /** Hızlı filtre: vergi istisna kodu olanlar */
  istisnali: '' | 'evet'
  gizlenenler: GizlenenlerFiltresi
  /** Hızlı filtre: karşı tarafın VKN'si eMOR'daki bir kurum kartında olanlar / olmayanlar */
  emor_vkn: EmorVknFiltresi
}

export interface Siralama {
  anahtar: string
  yon: 'asc' | 'desc'
}

export type CalismaDurumu = 'calisiyor' | 'tam' | 'eksik' | 'basarisiz'

export interface SenkronCalismasi {
  durum: CalismaDurumu
  tetikleyen: 'zamanlanmis' | 'manuel' | 'ilk_tarama'
  tarih_turu: 'DOCUMENT' | 'DELIVERY'
  baslangic: string
  bitis: string
  basladi: string
  bitti: string | null
  okunan_adet: number | null
  beklenen_adet: number | null
  yeni_adet: number | null
  eksik_nedeni: string | null
  hata_kodu: string | null
}

export interface YonDurumu {
  veri_zamani: string | null
  guncel: boolean
  calisiyor: boolean
  ardisik_hata: number
  son_calisma: SenkronCalismasi | null
}

export interface SenkronDurumu {
  ortam: 'test' | 'canli' | null
  senkron_aktif: boolean
  manuel_istek: { zaman: string; baslangic: string; bitis: string } | null
  yonler: Record<FaturaYonu, YonDurumu> | null
}

/** Boş filtreler gönderilmez; sayfa boyutu interceptor'dan gelir (`page` varken). */
function parametreler(filtre: EFaturaFiltresi, siralama: Siralama | null): Record<string, string> {
  const sonuc: Record<string, string> = { baslangic: filtre.baslangic, bitis: filtre.bitis }
  for (const anahtar of [
    'ara',
    'durum',
    'erp_okundu',
    'emor',
    'sinif',
    'entegrator',
    'para_birimi',
    'tip',
    'istisna_kodu',
    'istisnali',
    'gizlenenler',
    'emor_vkn',
  ] as const) {
    const deger = filtre[anahtar].trim()
    if (deger !== '') {
      sonuc[anahtar] = deger
    }
  }
  if (siralama !== null) {
    sonuc['sirala'] = siralama.anahtar
    sonuc['yon'] = siralama.yon
  }

  return sonuc
}

export async function faturalariGetir(
  yon: FaturaYonu,
  filtre: EFaturaFiltresi,
  siralama: Siralama | null,
  sayfa: number,
  kapsam: FaturaKapsami = 'izibiz',
): Promise<EFaturaListesi> {
  const yanit = await api.get<EFaturaListesi>(listeYolu(kapsam, yon), {
    params: { ...parametreler(filtre, siralama), page: sayfa },
  })

  return yanit.data
}

export async function senkronDurumuGetir(): Promise<SenkronDurumu> {
  const yanit = await api.get<{ data: SenkronDurumu }>('/api/v1/efatura/durum')

  return yanit.data.data
}

export async function senkronBaslat(aralik: { baslangic: string; bitis: string }): Promise<void> {
  await api.post('/api/v1/efatura/senkron', aralik)
}

export interface ErpSenkronSonucu {
  islendi: number
  elle_islendi: number
  havuzda: number
  yok: number
  degisen: number
}

/** Faturayı listede gizler ya da listeye geri alır; güncel faturayı döner. */
export async function gizliligiDegistir(faturaId: number, gizli: boolean): Promise<EFatura> {
  const yanit = await api.put<{ data: EFatura }>(`/api/v1/efatura/faturalar/${faturaId}/gizli`, {
    gizli,
  })

  return yanit.data.data
}

/** "ERP Senkronla": yönün eMOR (ERP'de karşılığı var mı) bilgisini hemen tazeler. */
export async function erpSenkronla(yon: FaturaYonu): Promise<ErpSenkronSonucu> {
  const yanit = await api.post<{ data: ErpSenkronSonucu }>('/api/v1/efatura/erp-senkron', { yon })

  return yanit.data.data
}

/** Excel'e aktarılan ekran kolonu: anahtar + ekrandaki başlık */
export interface ExcelKolonu {
  anahtar: string
  baslik: string
}

/**
 * Tablonun o anki hâli .xlsx olarak indirilir (kullanıcı isteği 2026-09-24):
 * aynı filtre, sıralama ve sayfa (sayfa boyutu interceptor'dan); yalnız
 * görünen kolonlar, ekrandaki başlık ve sırayla.
 */
export async function excelIndir(
  yon: FaturaYonu,
  filtre: EFaturaFiltresi,
  siralama: Siralama | null,
  sayfa: number,
  kolonlar: ExcelKolonu[],
  /** Ekrandaki listenin ortamı — sunucuda aktif ortam farklıysa indirme reddedilir */
  ortam: string,
  kapsam: FaturaKapsami = 'izibiz',
): Promise<void> {
  const yanit = await api
    .get<Blob>(`${listeYolu(kapsam, yon)}/excel`, {
      params: {
        ...parametreler(filtre, siralama),
        page: sayfa,
        ortam,
        kolonlar: kolonlar.map((kolon) => kolon.anahtar),
        basliklar: kolonlar.map((kolon) => kolon.baslik),
      },
      responseType: 'blob',
    })
    .catch(blobHatasiniCoz)

  const onEk = kapsam === 'tum' ? 'tum-faturalar' : `efatura-${yon}`
  dosyayiIndir(yanit.data, `${onEk}-${filtre.baslangic}-${filtre.bitis}.xlsx`)
}

/** Fatura aslının nereden okunduğu (sunucu X-Belge-Kaynagi başlığıyla bildirir). */
export type BelgeKaynagi = 'erp' | 'entegrator'

export type FaturaPdfi = { pdf: Blob; kaynak: BelgeKaynagi | null }

function belgeKaynagi(baslik: unknown): BelgeKaynagi | null {
  return baslik === 'erp' || baslik === 'entegrator' ? baslik : null
}

/**
 * Fatura aslı önce ERP havuzundan, yoksa entegratörden anlık okunur;
 * token tarayıcıya gelmez.
 */
export async function pdfGetir(
  faturaId: number,
  kapsam: FaturaKapsami = 'izibiz',
): Promise<FaturaPdfi> {
  const yanit = await api
    .get<Blob>(`${faturaYolu(kapsam, faturaId)}/pdf`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  return { pdf: yanit.data, kaynak: belgeKaynagi(yanit.headers['x-belge-kaynagi']) }
}

/** UBL XML'i PDF ile aynı kaynak sırasıyla okunup dosya olarak indirilir. */
export async function xmlIndir(
  fatura: Pick<EFatura, 'id' | 'belge_no'>,
  kapsam: FaturaKapsami = 'izibiz',
): Promise<void> {
  const yanit = await api
    .get<Blob>(`${faturaYolu(kapsam, fatura.id)}/xml`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  dosyayiIndir(yanit.data, `${fatura.belge_no}.xml`)
}
