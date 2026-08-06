import { api } from '@/api/client'

import type { IlgiCinsi } from './sabitler'

/**
 * ERP seçim listeleri — hepsi backend'deki tek SecenekController'a
 * (/api/v1/secenekler/*) gider. Seçim bileşenleri bu dosyayı kullanır.
 */

/** VOHOM_ARAMA_PERSONEL — kayit_id = PERSONEL_ID */
export interface PersonelSecenegi {
  kayit_id: number
  kod: string
  unvan: string
}

export async function personelSecenekleriGetir(): Promise<PersonelSecenegi[]> {
  const yanit = await api.get<{ data: PersonelSecenegi[] }>('/api/v1/secenekler/personeller')

  return yanit.data.data
}

/** Depolar da parti yaması ağacındadır (TUR=12); kayit_id satırların DEPOMUZ_ID'si */
export interface DepoSecenegi {
  kayit_id: number
  kod: string
  ad: string
}

export async function depoSecenekleriGetir(): Promise<DepoSecenegi[]> {
  const yanit = await api.get<{ data: DepoSecenegi[] }>('/api/v1/secenekler/depolar')

  return yanit.data.data
}

/** VOHOM_ARAMA_FIRMAMIZ_ADRESI — seçimde hem ID hem adres metni kullanılır */
export interface FirmamizAdresi {
  kayit_id: number
  ad: string
  adres: string
  semt: string
  sehir: string
}

export async function firmamizAdresleriGetir(): Promise<FirmamizAdresi[]> {
  const yanit = await api.get<{ data: FirmamizAdresi[] }>('/api/v1/secenekler/firmamiz-adresleri')

  return yanit.data.data
}

/** VOHOM_TABLO_MADDESI — TUR'a göre farklı listeler (36=Öncelik, 53=Teslimat şekli…) */
export interface TabloMaddesiSecenegi {
  kayit_id: number
  ust_id: number | null
  ad: string
}

/** ERP sorgusu türe göre farklı sıralar (Öncelik → ad, Teslimat şekli → kayıt id) */
export type MaddeSiralamasi = 'ad' | 'kayit_id'

export async function tabloMaddesiSecenekleriGetir(
  tur: number,
  sirala: MaddeSiralamasi = 'ad',
): Promise<TabloMaddesiSecenegi[]> {
  const yanit = await api.get<{ data: TabloMaddesiSecenegi[] }>(
    `/api/v1/secenekler/tablo-maddesi/${tur}`,
    { params: { sirala } },
  )

  return yanit.data.data
}

/** İlgi cinsine göre ilişkili kayıt adayı — kaynak view cinse göre değişir */
export interface IlgiliSecenegi {
  kayit_id: number
  kod: string
  ad: string
  /** Ek bağlam (ör. uygulama sözleşmesinde proje adı) */
  ek: string
}

export async function ilgiliSecenekleriGetir(cins: IlgiCinsi): Promise<IlgiliSecenegi[]> {
  const yanit = await api.get<{ data: IlgiliSecenegi[] }>(`/api/v1/secenekler/ilgili/${cins}`)

  return yanit.data.data
}
