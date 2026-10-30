import { api } from '@/api/client'

import type { Icmal } from './icmalApi'

/**
 * Taşeron kesinti türü (Kesinti Yönetimi). Bağlı olduğu icmal ekranında
 * kullanılır; başka ekrana girilemez / aktarılamaz.
 */
export interface KesintiTuru {
  id: number
  kod: string
  ad: string
  /** Yüzde, noktalı ve iki ondalıklı metin ("20.00") */
  kdv_orani: string
  aciklama: string | null
  /** Bağlı ekran; null: hiçbir ekranda kullanılamaz */
  icmal: Icmal | null
}

export interface KesintiTuruGovdesi {
  kod: string
  ad: string
  kdv_orani: string
  aciklama: string | null
  icmal: Icmal | null
}

export async function kesintiTurleriniGetir(): Promise<KesintiTuru[]> {
  const yanit = await api.get<{ data: KesintiTuru[] }>('/api/v1/kesinti/turler')

  return yanit.data.data
}

export async function kesintiTuruKaydet(
  id: number | null,
  govde: KesintiTuruGovdesi,
): Promise<KesintiTuru> {
  const yanit =
    id === null
      ? await api.post<{ data: KesintiTuru }>('/api/v1/kesinti/turler', govde)
      : await api.put<{ data: KesintiTuru }>(`/api/v1/kesinti/turler/${id}`, govde)

  return yanit.data.data
}

export async function kesintiTuruSil(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/turler/${id}`)
}
