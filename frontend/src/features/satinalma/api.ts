import { api } from '@/api/client'

/** ERP personel arama view'ı kaydı (VOHOM_ARAMA_PERSONEL) */
export interface PersonelSecenegi {
  kayit_id: number
  kod: string
  unvan: string
}

export async function personelSecenekleriGetir(): Promise<PersonelSecenegi[]> {
  const yanit = await api.get<{ data: PersonelSecenegi[] }>(
    '/api/v1/satinalma/secenekler/personeller',
  )

  return yanit.data.data
}
