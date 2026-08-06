import { api } from '@/api/client'

/**
 * Firmamızın adres kaydı (VOHOM_ARAMA_FIRMAMIZ_ADRESI). Seçimde iki değer
 * birden kullanılır: kayit_id → @TESLIMAT_ADRESI_ID, adres → @TESLIMAT_ADRESI.
 */
export interface FirmamizAdresi {
  kayit_id: number
  ad: string
  adres: string
  semt: string
  sehir: string
}

export async function firmamizAdresleriGetir(): Promise<FirmamizAdresi[]> {
  const yanit = await api.get<{ data: FirmamizAdresi[] }>('/api/v1/secenekler/firmamiz-adresleri')

  return yanit.data.data
}
