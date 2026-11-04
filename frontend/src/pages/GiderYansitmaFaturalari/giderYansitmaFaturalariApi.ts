import { erpRaporApi } from '@/features/rapor/erpRaporApi'

/** TL karşılıkları (backend GiderYansitmaFaturalariRaporu) */
export const TL_TUTARLAR = [
  'kdv_matrahi_tl',
  'kdv_tl',
  'vergi_tl',
  'tevkifat_tl',
  'genel_toplam_tl',
  'kesinti_toplami_tl',
  'net_toplam_tl',
] as const

/** Faturanın kendi para birimindeki tutarlar (TL faturada TL'nin aynısı) */
export const DOVIZ_TUTARLAR = [
  'kdv_matrahi_doviz',
  'kdv_doviz',
  'vergi_doviz',
  'tevkifat_doviz',
  'genel_toplam_doviz',
  'kesinti_toplami_doviz',
  'net_toplam_doviz',
] as const

/** Fatura başına satır; tarihler YYYY-MM-DD; hakedişe bağlı olmayan faturada hakediş alanları boş */
export type GiderYansitmaFaturasi = {
  tarih: string | null
  fatura_no: string
  unvan: string
  proje_kodu: string
  aciklama: string
  hakedis_no: string
  hakedis_tarihi: string | null
  sozlesme_no: string
  para_kodu: string
  kayit_eden: string
  guncelleyen: string
} & Record<(typeof TL_TUTARLAR)[number] | (typeof DOVIZ_TUTARLAR)[number], number>

export const giderYansitmaFaturalariApi = erpRaporApi<GiderYansitmaFaturasi>(
  '/api/v1/raporlar/gider-yansitma-faturalari',
  'gider-yansitma-faturalari.xlsx',
)
