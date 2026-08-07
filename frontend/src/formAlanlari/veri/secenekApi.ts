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
export type TabloMaddesiSecenegi = {
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

/**
 * VOHOM_ARAMA_AKTIVITE — seçili projenin iş programındaki aktiviteler.
 * Backend, projeden iş programını (VOHOM_PROJEMIZ.IS_PROGRAMI_ID) kendisi
 * çözer; istemci yalnız projeyi bilir.
 */
export type AktiviteSecenegi = {
  kayit_id: number
  kod: string
  aciklama: string
  /** Aktivitenin poz numarası — satırdaki Poz No sütununun kaynağı olabilir */
  poz_no: string
}

export async function aktiviteSecenekleriGetir(projemizId: string): Promise<AktiviteSecenegi[]> {
  const yanit = await api.get<{ data: AktiviteSecenegi[] }>(
    `/api/v1/secenekler/aktiviteler/${projemizId}`,
  )

  return yanit.data.data
}

/** VOHOM_ARAMA_MASRAF_MERKEZI — projesiz (genel) merkezler her projede görünür */
export type MasrafMerkeziSecenegi = {
  kayit_id: number
  kod: string
  ad: string
}

/**
 * VOHOM_ARAMA_URUN_YAMASI (TUR=6) — kayit_id = URUN_YAMASI_ID.
 * Liste on binlerce kayıt olabildiği için tamamı çekilmez: arama sunucuda
 * yapılır (kod, ad ve barkodun üçünde birden) ve ilk 50 kayıt döner.
 */
export type UrunSecenegi = {
  kayit_id: number
  kod: string
  ad: string
  barkod: string
}

export async function urunSecenekleriGetir(ara: string): Promise<UrunSecenegi[]> {
  const yanit = await api.get<{ data: UrunSecenegi[] }>('/api/v1/secenekler/urunler', {
    params: { ara },
  })

  return yanit.data.data
}

export async function masrafMerkeziSecenekleriGetir(
  projemizId: string,
): Promise<MasrafMerkeziSecenegi[]> {
  const yanit = await api.get<{ data: MasrafMerkeziSecenegi[] }>(
    `/api/v1/secenekler/masraf-merkezleri/${projemizId}`,
  )

  return yanit.data.data
}

/** VOHOM_ARAMA_EKIPMAN — kiralama hizmetine bağlı kayıtlar listelenmez */
export type EkipmanSecenegi = {
  kayit_id: number
  kod: string
  ad: string
}

export async function ekipmanSecenekleriGetir(): Promise<EkipmanSecenegi[]> {
  const yanit = await api.get<{ data: EkipmanSecenegi[] }>('/api/v1/secenekler/ekipmanlar')

  return yanit.data.data
}

/** VOHOM_ARAMA_PROJEMIZ_BUTCE_KALEMI — proje bazlı; kayit_id = HARCAMA_KALEMI_ID */
export type ButceKalemiSecenegi = {
  kayit_id: number
  kod: string
  ad: string
}

export async function butceKalemiSecenekleriGetir(
  projemizId: string,
): Promise<ButceKalemiSecenegi[]> {
  const yanit = await api.get<{ data: ButceKalemiSecenegi[] }>(
    `/api/v1/secenekler/butce-kalemleri/${projemizId}`,
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
