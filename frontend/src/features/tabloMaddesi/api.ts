import { api } from '@/api/client'

/**
 * ERP genel amaçlı madde tablosu kaydı (VOHOM_TABLO_MADDESI) — TUR'a göre
 * farklı listeler döner (ör. TUR=36 → Öncelik). kayit_id = TABLO_MADDESI_ID;
 * proc parametrelerine set edilecek değer budur (ör. @ONCELIK_ID).
 */
export interface TabloMaddesiSecenegi {
  kayit_id: number
  ust_id: number | null
  ad: string
}

/** ERP sorgusu türe göre farklı sıralar (Öncelik → ad, Teslimat şekli → kayıt id) */
export type MaddeSiralamasi = 'ad' | 'kayit_id'

export async function tabloMaddesiSecenekleriGetir(
  tur: number,
  sirala: MaddeSiralamasi = 'ad',
): Promise<TabloMaddesiSecenegi[]> {
  const yanit = await api.get<{ data: TabloMaddesiSecenegi[] }>(
    `/api/v1/secenekler/tablo-maddesi/${tur}`,
    { params: { sirala } },
  )

  return yanit.data.data
}
