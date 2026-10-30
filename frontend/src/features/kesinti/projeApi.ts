import { api } from '@/api/client'

/** Proje (Kesinti Yönetimi). Elle açılır ya da canlı ERP'den aktarılır. */
export interface Proje {
  id: number
  kod: string
  ad: string
  /** Doluysa proje ERP'den aktarılmıştır */
  erp_guid: string | null
}

export interface ProjeGovdesi {
  kod: string
  ad: string
}

/**
 * ERP projesinin aktarımda ne olacağı (backend ErpProjeAktarimi):
 * yeni → eklenir, guncellenecek → kod/ad ERP'deki gibi olur, eslesecek →
 * elle açılmış aynı kodlu proje ERP'ye bağlanır, ayni → değişiklik yok,
 * cakisma / gecersiz → aktarılamaz (neden alanında).
 */
export type ErpProjeDurumu =
  'yeni' | 'guncellenecek' | 'eslesecek' | 'ayni' | 'cakisma' | 'gecersiz'

export interface ErpProjeSatiri {
  /** ERP sırası — satır anahtarı (GUID tekrar edebilir) */
  sira: number
  guid: string
  kod: string
  ad: string
  durum: ErpProjeDurumu
  neden: string | null
  /** Eşleşen projenin uygulamadaki hâli */
  mevcut_kod: string | null
  mevcut_ad: string | null
}

export interface ErpAktarimSonucu {
  eklenen: number
  guncellenen: number
  eslesen: number
  atlanan: number
}

/** Seçilip aktarılabilen durumlar */
export const AKTARILABILIR_DURUMLAR: readonly ErpProjeDurumu[] = [
  'yeni',
  'guncellenecek',
  'eslesecek',
]

export async function projeleriGetir(): Promise<Proje[]> {
  const yanit = await api.get<{ data: Proje[] }>('/api/v1/kesinti/projeler')

  return yanit.data.data
}

export async function projeKaydet(id: number | null, govde: ProjeGovdesi): Promise<Proje> {
  const yanit =
    id === null
      ? await api.post<{ data: Proje }>('/api/v1/kesinti/projeler', govde)
      : await api.put<{ data: Proje }>(`/api/v1/kesinti/projeler/${id}`, govde)

  return yanit.data.data
}

export async function projeSil(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/projeler/${id}`)
}

/** Canlı ERP'deki projeler ve her birinin aktarımda ne olacağı (yazmaz). */
export async function erpProjeleriniGetir(): Promise<ErpProjeSatiri[]> {
  const yanit = await api.get<{ data: ErpProjeSatiri[] }>('/api/v1/kesinti/projeler/erp')

  return yanit.data.data
}

/** Seçilen ERP projelerini kaydeder; kod/ad sunucuda ERP'den yeniden okunur. */
export async function erpProjeleriniAktar(guidler: string[]): Promise<ErpAktarimSonucu> {
  const yanit = await api.post<{ data: ErpAktarimSonucu }>('/api/v1/kesinti/projeler/erp', {
    guidler,
  })

  return yanit.data.data
}
