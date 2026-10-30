import { api } from '@/api/client'

import type { ErpAktarimSatiri, ErpAktarimSonucu } from './erpAktarim'

/** Firma bilgileri (elle girilen ve ERP'den gelen ortak alanlar) */
export interface FirmaBilgileri {
  kod: string
  ad: string
  vergi_kimlik_no: string | null
  vergi_dairesi: string | null
  eposta: string | null
  gsm_no: string | null
}

/** Firma / taşeron (Kesinti Yönetimi). Elle açılır ya da canlı ERP'den aktarılır. */
export interface Firma extends FirmaBilgileri {
  id: number
  /** Doluysa firma ERP'den aktarılmıştır */
  erp_guid: string | null
}

/** ERP taşeronu ve aktarımda ne olacağı */
export interface ErpFirmaSatiri extends ErpAktarimSatiri, FirmaBilgileri {
  /** Eşleşen (GUID ya da VKN ile) firmanın uygulamadaki hâli */
  mevcut: FirmaBilgileri | null
}

export async function firmalariGetir(): Promise<Firma[]> {
  const yanit = await api.get<{ data: Firma[] }>('/api/v1/kesinti/firmalar')

  return yanit.data.data
}

export async function firmaKaydet(id: number | null, govde: FirmaBilgileri): Promise<Firma> {
  const yanit =
    id === null
      ? await api.post<{ data: Firma }>('/api/v1/kesinti/firmalar', govde)
      : await api.put<{ data: Firma }>(`/api/v1/kesinti/firmalar/${id}`, govde)

  return yanit.data.data
}

export async function firmaSil(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/firmalar/${id}`)
}

/** Canlı ERP'deki taşeronlar ve her birinin aktarımda ne olacağı (yazmaz). */
export async function erpFirmalariniGetir(): Promise<ErpFirmaSatiri[]> {
  const yanit = await api.get<{ data: ErpFirmaSatiri[] }>('/api/v1/kesinti/firmalar/erp')

  return yanit.data.data
}

/** Seçilen ERP taşeronlarını kaydeder; bilgiler sunucuda ERP'den yeniden okunur. */
export async function erpFirmalariniAktar(guidler: string[]): Promise<ErpAktarimSonucu> {
  const yanit = await api.post<{ data: ErpAktarimSonucu }>('/api/v1/kesinti/firmalar/erp', {
    guidler,
  })

  return yanit.data.data
}
