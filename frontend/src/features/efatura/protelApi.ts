import { api } from '@/api/client'

import type { SenkronDurumu } from './efaturaApi'

/**
 * Protel Gelen Faturalar (PRT-12) — otelin posta kutusu. Liste, Excel, PDF ve
 * "İşlendi" diğer tek kaynaklı ekranlarla ortaktır (kaynakApi, faturaIslemApi);
 * burada yalnız Protel'e özgü senkron uçları var. Protel'de hiçbir şey değişmez.
 */
export async function protelDurumuGetir(): Promise<SenkronDurumu> {
  const yanit = await api.get<{ data: SenkronDurumu }>('/api/v1/protel/durum')

  return yanit.data.data
}

export async function protelSenkronBaslat(aralik: {
  baslangic: string
  bitis: string
}): Promise<void> {
  await api.post('/api/v1/protel/senkron', aralik)
}
