import { api } from '@/api/client'

/** Para birimi (Kesinti Yönetimi). Excel'deki "PARA BİRİMİ" koduyla eşleşir. */
export interface ParaBirimi {
  id: number
  kod: string
  ad: string
}

export interface ParaBirimiGovdesi {
  kod: string
  ad: string
}

export async function paraBirimleriniGetir(): Promise<ParaBirimi[]> {
  const yanit = await api.get<{ data: ParaBirimi[] }>('/api/v1/kesinti/para-birimleri')

  return yanit.data.data
}

export async function paraBirimiKaydet(
  id: number | null,
  govde: ParaBirimiGovdesi,
): Promise<ParaBirimi> {
  const yanit =
    id === null
      ? await api.post<{ data: ParaBirimi }>('/api/v1/kesinti/para-birimleri', govde)
      : await api.put<{ data: ParaBirimi }>(`/api/v1/kesinti/para-birimleri/${id}`, govde)

  return yanit.data.data
}

export async function paraBirimiSil(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/para-birimleri/${id}`)
}
