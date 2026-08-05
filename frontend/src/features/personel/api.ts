import { api } from '@/api/client'

/**
 * ERP personel arama view'ı kaydı (VOHOM_ARAMA_PERSONEL).
 * kayit_id = PERSONEL_ID — store proc'larda parti/personel kimliği olarak
 * kullanılır (ör. SOHOM_SIPARIS_KAYDET @PARTI_YAMASI_ID).
 */
export interface PersonelSecenegi {
  kayit_id: number
  kod: string
  unvan: string
}

export async function personelSecenekleriGetir(): Promise<PersonelSecenegi[]> {
  const yanit = await api.get<{ data: PersonelSecenegi[] }>('/api/v1/secenekler/personeller')

  return yanit.data.data
}
