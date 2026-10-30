import { api } from '@/api/client'

/**
 * Taşeron kesinti türü (Kesinti Yönetimi). İcmal ekranları da tür seçimi
 * için bu listeyi okur.
 */
export interface KesintiTuru {
  id: number
  kod: string
  ad: string
  /** Yüzde, noktalı ve iki ondalıklı metin ("20.00") */
  kdv_orani: string
  aciklama: string | null
}

export interface KesintiTuruGovdesi {
  kod: string
  ad: string
  kdv_orani: string
  aciklama: string | null
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
