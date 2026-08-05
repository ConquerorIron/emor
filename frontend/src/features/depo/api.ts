import { api } from '@/api/client'

/**
 * ERP depo kaydı — depolar da parti yaması ağacındadır
 * (VOHOM_ARAMA_PARTI_YAMASI TUR=12). kayit_id, sipariş satırlarının
 * DEPOMUZ_ID alanına yazılır (TOHOM_SIPARIS_SATIRI).
 */
export interface DepoSecenegi {
  kayit_id: number
  kod: string
  ad: string
}

export async function depoSecenekleriGetir(): Promise<DepoSecenegi[]> {
  const yanit = await api.get<{ data: DepoSecenegi[] }>('/api/v1/secenekler/depolar')

  return yanit.data.data
}
