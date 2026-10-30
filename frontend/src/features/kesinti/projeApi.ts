import { api } from '@/api/client'

import type { ErpAktarimSatiri, ErpAktarimSonucu } from './erpAktarim'

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

/** ERP projesi ve aktarımda ne olacağı */
export interface ErpProjeSatiri extends ErpAktarimSatiri {
  kod: string
  ad: string
  /** Eşleşen projenin uygulamadaki hâli */
  mevcut: { kod: string; ad: string } | null
}

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
