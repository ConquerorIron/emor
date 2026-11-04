import { erpRaporApi } from '@/features/rapor/erpRaporApi'

/** Kesinti icmallerinin ortak tutarları + ERP'nin aynı gruptaki tutarları (backend ErpKesintiKontroluRaporu) */
export const TUTARLAR = [
  'toplam_tutar',
  'iptal_edilen_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
  'hakedis_yansitilacak_gider',
  'yansitma_faturasi',
] as const

/** Taşeron + proje + para birimi başına satır; anahtar bu üçünden türeyen kısa kimlik */
export type KesintiKontrolSatiri = {
  anahtar: string
  taseron: string
  proje: string
  para_kodu: string
} & Record<(typeof TUTARLAR)[number], number>

export const erpKesintiKontroluApi = erpRaporApi<KesintiKontrolSatiri>(
  '/api/v1/raporlar/erp-kesinti-kontrolu',
  'erp-kesinti-kontrolu.xlsx',
)
