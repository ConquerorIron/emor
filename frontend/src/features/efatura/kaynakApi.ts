import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

import type {
  EFaturaListesi,
  EmorVknFiltresi,
  ErpOkunduFiltresi,
  ExcelKolonu,
  FaturaPdfi,
  FaturaSinifi,
  Siralama,
} from './efaturaApi'

/**
 * Tek kaynaklı gelen fatura ekranları: Protel (otelin posta kutusu, PRT-12)
 * ve statik kaynaklar (Logo, e-Arşiv / e-SMM — EK-07, EK-08). Sunucu e-Fatura
 * uçlarını rotanın kaynağıyla çalıştırır: yalnız gelen, XML ve gizleme yok.
 */
export type KaynakOneki = 'protel' | 'logo' | 'earsiv'

/** Statik kaynaklar: veri Excel içe aktarımıyla gelir */
export type StatikKaynak = 'logo' | 'earsiv'

export interface KaynakFiltresi {
  baslangic: string
  bitis: string
  ara: string
  durum: string
  para_birimi: string
  tip: string
  /** "eMOR VKN Var": gönderici eMOR'da bir kurum kartında kayıtlı mı */
  emor_vkn: EmorVknFiltresi
  /** Hızlı filtreler: RED faturalar işlendi / işlenecek süzgeçlerine gelmez */
  sinif: FaturaSinifi
  /** Yalnız Protel: otelin ERP'si aldı mı (Protel `isErpRecieved`) */
  otel_erp_okundu: ErpOkunduFiltresi
  /** Yalnız e-Arşiv: fatura ya da makbuz */
  belge_turu: '' | 'fatura' | 'makbuz'
}

function parametreler(filtre: KaynakFiltresi, siralama: Siralama | null): Record<string, string> {
  const sonuc: Record<string, string> = { baslangic: filtre.baslangic, bitis: filtre.bitis }
  for (const anahtar of [
    'ara',
    'durum',
    'otel_erp_okundu',
    'para_birimi',
    'tip',
    'emor_vkn',
    'sinif',
    'belge_turu',
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

export async function kaynakFaturalariGetir(
  onEk: KaynakOneki,
  filtre: KaynakFiltresi,
  siralama: Siralama | null,
  sayfa: number,
): Promise<EFaturaListesi> {
  const yanit = await api.get<EFaturaListesi>(`/api/v1/${onEk}/gelen/faturalar`, {
    params: { ...parametreler(filtre, siralama), page: sayfa },
  })

  return yanit.data
}

/**
 * PDF anlık okunur: Protel'de entegratörden (istek yalnız izinli alanları
 * taşır), statik kaynakta yalnız eMOR arşivinden (yoksa açık hata).
 */
export async function kaynakPdfGetir(onEk: KaynakOneki, faturaId: number): Promise<FaturaPdfi> {
  const yanit = await api
    .get<Blob>(`/api/v1/${onEk}/faturalar/${faturaId}/pdf`, { responseType: 'blob' })
    .catch(blobHatasiniCoz)

  return { pdf: yanit.data, kaynak: onEk === 'protel' ? 'entegrator' : 'erp' }
}

/** Tablonun o anki hâli (aynı filtre, sıralama, sayfa; görünen kolonlar) .xlsx olarak */
export async function kaynakExcelIndir(
  onEk: KaynakOneki,
  filtre: KaynakFiltresi,
  siralama: Siralama | null,
  sayfa: number,
  kolonlar: ExcelKolonu[],
  /** Ekrandaki listenin ortamı — sunucuda aktif ortam farklıysa indirme reddedilir */
  ortam: string,
): Promise<void> {
  const yanit = await api
    .get<Blob>(`/api/v1/${onEk}/gelen/faturalar/excel`, {
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

  dosyayiIndir(yanit.data, `${onEk}-gelen-${filtre.baslangic}-${filtre.bitis}.xlsx`)
}

/** İçe aktarım önizlemesi: sayılar ve nedenine göre gruplu hatalar (satırlar yazılmaz) */
export interface IceAktarimOnizlemesi {
  hata: 'dosya_okunamadi' | 'baslik_bulunamadi' | 'satir_yok' | null
  eksik_kolonlar: string[]
  belge_turu: 'fatura' | 'makbuz' | null
  hatalar: { alan: string; deger: string; kod: string; adet: number; satirlar: number[] }[]
  ozet: { okunan: number; yeni: number; guncellenecek: number; degismeyen: number; red: number }
}

/** İçe aktarım partisi */
export interface Yukleme {
  id: number
  belge_turu: 'fatura' | 'makbuz' | null
  dosya_adi: string
  kullanici_adi: string
  yeni: number
  guncellenen: number
  degismeyen: number
  zaman: string
}

function dosyaFormu(dosya: File): FormData {
  const form = new FormData()
  form.append('dosya', dosya)

  return form
}

export async function iceAktarimKontrol(
  onEk: StatikKaynak,
  dosya: File,
): Promise<IceAktarimOnizlemesi> {
  const yanit = await api.post<{ data: IceAktarimOnizlemesi }>(
    `/api/v1/${onEk}/ice-aktar/kontrol`,
    dosyaFormu(dosya),
  )

  return yanit.data.data
}

/** Hatasız dosya tek parti olarak yazılır; hatalıysa sunucu 422 + önizleme döner. */
export async function iceAktar(onEk: StatikKaynak, dosya: File): Promise<Yukleme> {
  const yanit = await api.post<{ data: Yukleme }>(`/api/v1/${onEk}/ice-aktar`, dosyaFormu(dosya))

  return yanit.data.data
}

export async function yuklemeleriGetir(onEk: StatikKaynak): Promise<Yukleme[]> {
  const yanit = await api.get<{ data: Yukleme[] }>(`/api/v1/${onEk}/yuklemeler`)

  return yanit.data.data
}

/** Partinin eklediği faturalar silinir; elle işlem görmüş fatura varsa sunucu 409 döner. */
export async function yuklemeyiGeriAl(onEk: StatikKaynak, yuklemeId: number): Promise<void> {
  await api.delete(`/api/v1/${onEk}/yuklemeler/${yuklemeId}`)
}
