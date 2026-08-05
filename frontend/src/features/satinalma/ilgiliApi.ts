import { api } from '@/api/client'

import type { IlgiCinsi } from './ilgiCinsleri'

/**
 * İlgi cinsine göre ilişkili kayıt adayı (@ILGILI_ID) — kaynak view cinse
 * göre değişir (7 → parti yaması/proje, 8 → sipariş/uygulama sözleşmesi…).
 */
export interface IlgiliSecenegi {
  kayit_id: number
  kod: string
  ad: string
  /** Ek bağlam (ör. uygulama sözleşmesinde proje adı) — etikete eklenir */
  ek: string
}

export async function ilgiliSecenekleriGetir(cins: IlgiCinsi): Promise<IlgiliSecenegi[]> {
  const yanit = await api.get<{ data: IlgiliSecenegi[] }>(`/api/v1/secenekler/ilgili/${cins}`)

  return yanit.data.data
}
