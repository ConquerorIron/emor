import { erpRaporApi } from '@/features/rapor/erpRaporApi'

/** Tutar kolonları (backend TaseronHakedisleriRaporu::TUTARLAR) */
export const TUTARLAR = [
  'imalat',
  'tutanak',
  'hakedis',
  'imalat_kesintisi',
  'kdv',
  'kdvli_hakedis',
  'kdv_tevkifati',
  'cari_kesinti',
  'yansitilacak_gider',
  'taseron_adina_yapilan_is',
  'net_odenecek',
  'odeme_talebi_tutari',
  'odenen_tutar',
] as const

export type Tutar = (typeof TUTARLAR)[number]

/** Hakediş başına satır; tarihler YYYY-MM-DD, onay saati SS:DD:ss */
export type HakedisSatiri = {
  hakedis_id: number
  firma_adi: string
  proje_adi: string
  sozlesme_no: string
  sozlesme_konusu: string
  hakedis_no: string
  hakedis_tarihi: string | null
  donem_baslangici: string | null
  donem_sonu: string | null
  para_kodu: string
  onay_durumu: string
  onaylayan: string
  onay_tarihi: string | null
  onay_saati: string | null
  fatura_no: string
  hakedis_durumu: string
} & Record<Tutar, number>

export const taseronHakedisleriApi = erpRaporApi<HakedisSatiri>(
  '/api/v1/raporlar/taseron-hakedisleri',
  'taseron-hakedisleri.xlsx',
)
