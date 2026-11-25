import { api } from '@/api/client'

import type { EFaturaIslemi, EFaturaIslemTuru, Saglayici } from './efaturaApi'

export interface IslemGecmisiSuzgeci {
  baslangic: string
  bitis: string
  kullanici_id: string
  islem: '' | EFaturaIslemTuru
  saglayici: '' | Saglayici
  ara: string
}

export interface IslemGecmisiListesi {
  data: EFaturaIslemi[]
  meta: { current_page: number; last_page: number; total: number; from: number | null }
  secenekler: { kullanicilar: { id: number; ad: string }[] }
}

/** İşlem Geçmişi ekranı (EK-03): tarih aralığı İstanbul günüdür; en yeni üstte. */
export async function islemGecmisiGetir(
  suzgec: IslemGecmisiSuzgeci,
  sayfa: number,
): Promise<IslemGecmisiListesi> {
  const params: Record<string, string | number> = { page: sayfa }
  for (const [anahtar, deger] of Object.entries(suzgec)) {
    if (deger.trim() !== '') {
      params[anahtar] = deger.trim()
    }
  }
  const yanit = await api.get<IslemGecmisiListesi>('/api/v1/efatura/islem-gecmisi', { params })

  return yanit.data
}
