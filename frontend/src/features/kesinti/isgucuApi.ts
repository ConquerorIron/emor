import { icmalApi, type IcmalSatiri } from './icmalApi'

export type Vasif = 'nitelikli' | 'niteliksiz'
export type MesaiTuru = 'normal' | 'fazla'

/** Sabit seçenekler (backend enum'larıyla aynı; tanım tablosu yok) */
export const VASIFLAR: readonly Vasif[] = ['nitelikli', 'niteliksiz']
export const MESAI_TURLERI: readonly MesaiTuru[] = ['normal', 'fazla']

export interface IsgucuKesintisi extends IcmalSatiri {
  kaynak_fis_no: string | null
  adi_soyadi: string
  vasif: Vasif
  mesai_turu: MesaiTuru
  tanim: string | null
  gorev: string | null
  departman: string | null
  aktivite: string | null
  miktar_saat: string
  miktar_yevmiye: string
  birim_fiyat: string
}

export const isgucuApi = icmalApi<IsgucuKesintisi>('isgucu')
