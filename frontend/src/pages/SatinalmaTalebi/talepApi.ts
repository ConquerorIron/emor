import { api } from '@/api/client'

import type { TalepGirdisi } from './talepSchema'

/** SOHOM_SIPARIS_KAYDET'in dönüşü — talep numarasını ERP üretir */
export interface TalepKaydiSonucu {
  siparis_id: number
  talep_no: string
  /** 0 = onaya sunulmuş talep yok, 1 = var, 2 = onaylanmış */
  onay_durumu: number
}

export async function talepKaydet(girdi: TalepGirdisi): Promise<TalepKaydiSonucu> {
  const yanit = await api.post<{ data: TalepKaydiSonucu }>('/api/v1/satinalma/talepler', girdi)

  return yanit.data.data
}
