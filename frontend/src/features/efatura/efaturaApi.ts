import { isAxiosError } from 'axios'

import { api } from '@/api/client'

export type FaturaYonu = 'gelen' | 'giden'

export interface EFatura {
  id: number
  yon: FaturaYonu
  /** İzibiz iç kimliği (ETTN değil) */
  kaynak_id: number
  ettn: string
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
}

export interface ParaBirimiOzeti {
  para_birimi: string
  adet: number
  tutar: string
  vergi_tutari: string
}

export interface EFaturaListesi {
  data: EFatura[]
  meta: { current_page: number; last_page: number; total: number }
  ozet: ParaBirimiOzeti[]
  secenekler: {
    /** İzibiz durum kodu + Türkçe açıklaması */
    durumlar: { deger: string; aciklama: string | null }[]
    para_birimleri: string[]
    /** Seçili aralıktaki fatura tipleri ve vergi istisna kodları */
    tipler: string[]
    istisna_kodlari: string[]
  }
  kapsam: { ortam: 'test' | 'canli' }
}

export type ErpOkunduFiltresi = '' | 'evet' | 'hayir' | 'bilinmiyor'

/**
 * eMOR: islendi = muhasebeleşmiş (gelen: TOHOM_FATURA), havuzda = ERP almış ama
 * muhasebeleşmemiş (TOHOM_E_FATURA), yok = ERP'de karşılığı yok.
 */
export type EmorDurumu = 'islendi' | 'havuzda' | 'yok'

/** islenmemis: "İşlendi" yazmayanların hepsi (hızlı filtre) */
export type EmorFiltresi = '' | EmorDurumu | 'bilinmiyor' | 'islenmemis'

export interface EFaturaFiltresi {
  baslangic: string
  bitis: string
  ara: string
  durum: string
  erp_okundu: ErpOkunduFiltresi
  emor: EmorFiltresi
  para_birimi: string
  tip: string
  istisna_kodu: string
  /** Hızlı filtre: vergi istisna kodu olanlar */
  istisnali: '' | 'evet'
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
    'para_birimi',
    'tip',
    'istisna_kodu',
    'istisnali',
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
): Promise<EFaturaListesi> {
  const yanit = await api.get<EFaturaListesi>(`/api/v1/efatura/${yon}/faturalar`, {
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
  havuzda: number
  yok: number
  degisen: number
}

/** "ERP Senkronla": yönün eMOR (ERP'de karşılığı var mı) bilgisini hemen tazeler. */
export async function erpSenkronla(yon: FaturaYonu): Promise<ErpSenkronSonucu> {
  const yanit = await api.post<{ data: ErpSenkronSonucu }>('/api/v1/efatura/erp-senkron', { yon })

  return yanit.data.data
}

/**
 * Blob yanıtlı isteklerde hata gövdesi de Blob gelir; `kod` alanı okunabilsin
 * diye JSON'a çevrilip hataya geri yazılır (apiErrorKey sözleşmesi).
 */
async function blobHatasiniCoz(error: unknown): Promise<never> {
  if (isAxiosError(error) && error.response?.data instanceof Blob) {
    try {
      error.response.data = JSON.parse(await error.response.data.text()) as unknown
    } catch {
      // JSON değilse durum koduna göre çevrilir
    }
  }

  throw error
}

/** Filtrenin TAMAMI (görünen sayfa değil) .xlsx olarak indirilir. */
export async function excelIndir(
  yon: FaturaYonu,
  filtre: EFaturaFiltresi,
  siralama: Siralama | null,
): Promise<void> {
  const yanit = await api
    .get<Blob>(`/api/v1/efatura/${yon}/faturalar/excel`, {
      params: parametreler(filtre, siralama),
      responseType: 'blob',
    })
    .catch(blobHatasiniCoz)

  const adres = URL.createObjectURL(yanit.data)
  const baglanti = document.createElement('a')
  baglanti.href = adres
  baglanti.download = `efatura-${yon}-${filtre.baslangic}-${filtre.bitis}.xlsx`
  document.body.appendChild(baglanti)
  baglanti.click()
  baglanti.remove()
  // Bazı tarayıcılar indirmeyi click'ten sonra başlatır; adres hemen bırakılmaz
  window.setTimeout(() => URL.revokeObjectURL(adres), 60_000)
}

/** Fatura aslı İzibiz'den anlık okunur; token tarayıcıya gelmez. */
export async function pdfGetir(faturaId: number): Promise<Blob> {
  const yanit = await api
    .get<Blob>(`/api/v1/efatura/faturalar/${faturaId}/pdf`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  return yanit.data
}
