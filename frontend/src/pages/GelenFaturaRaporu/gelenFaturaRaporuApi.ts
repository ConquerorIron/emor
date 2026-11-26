import { erpRaporApi } from '@/features/rapor/erpRaporApi'

/** TL karşılıkları (backend GelenFaturaRaporu); kuru olmayan dövizli faturada null */
export const TL_TUTARLAR = [
  'tutar_tl',
  'indirim_tl',
  'kdv_matrahi_tl',
  'kdv_tl',
  'fatura_toplami_tl',
  'tevkifat_tl',
  'net_kdv_tl',
] as const

/** Faturanın kendi para birimindeki tutarlar; oran kolonları kalemsiz faturada null */
export const ORIJINAL_TUTARLAR = [
  'orijinal_tutar',
  'orijinal_indirim',
  'net_tutar',
  'orijinal_kdv',
  'fatura_toplami',
  'orijinal_tevkifat',
  'net_kdv',
  'kdv_0',
  'matrah_0',
  'kdv_1',
  'matrah_1',
  'kdv_10',
  'matrah_10',
  'kdv_20',
  'matrah_20',
  'matrah_toplami',
] as const

/** Fatura başına satır (backend GelenFaturaRaporu::KOLONLAR + `red`) */
export type GelenFaturaSatiri = {
  id: number
  fatura_tarihi: string | null
  fatura_no: string
  fatura_profili: string
  entegrator: string
  firma_unvani: string
  vkn: string
  vergi_dairesi: string
  para_kodu: string
  kur: string
  oran_0: string
  oran_1: string
  oran_10: string
  oran_20: string
  istisna_kodu: string
  istisna_adi: string
  tevkifat_kodu: string
  tevkifat_aciklama: string
  /** Ekrandaki "RED'leri çıkar" süzgeci; kolon değil */
  red: 'evet' | 'hayir'
} & Record<(typeof TL_TUTARLAR)[number] | (typeof ORIJINAL_TUTARLAR)[number], number | null>

export const gelenFaturaRaporuApi = erpRaporApi<GelenFaturaSatiri>(
  '/api/v1/raporlar/gelen-fatura-raporu',
  'gelen-fatura-raporu.xlsx',
)
