import { api } from '@/api/client'

import type { EkranDuzeni, EkranTasarimi } from './types'

export async function ekranTasariminiGetir(ekran: string): Promise<EkranTasarimi> {
  const yanit = await api.get<{ data: EkranTasarimi }>(`/api/v1/ekranlar/${ekran}/tasarim`)

  return yanit.data.data
}

export interface TaslakYaniti extends EkranTasarimi {
  surum: number
  yayinda_surum: number | null
}

export async function ekranTaslaginiGetir(ekran: string): Promise<TaslakYaniti> {
  const yanit = await api.get<{ data: TaslakYaniti }>(`/api/v1/ekranlar/${ekran}/taslak`)

  return yanit.data.data
}

export async function ekranTaslaginiKaydet(
  ekran: string,
  duzen: EkranDuzeni,
): Promise<EkranDuzeni> {
  const yanit = await api.put<{ data: { duzen: EkranDuzeni } }>(
    `/api/v1/ekranlar/${ekran}/taslak`,
    { duzen },
  )

  return yanit.data.data.duzen
}

export async function ekranTasariminiYayinla(ekran: string): Promise<EkranDuzeni> {
  const yanit = await api.post<{ data: { duzen: EkranDuzeni } }>(
    `/api/v1/ekranlar/${ekran}/yayinla`,
  )

  return yanit.data.data.duzen
}
