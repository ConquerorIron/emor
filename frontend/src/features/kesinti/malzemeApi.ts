import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface MalzemeKesintisi extends IcmalSatiri {
  kaynak_irsaliye_no: string | null
  urun_tanimi: string
  malzeme_birim_fiyati: string
  miktar: string
  birim: string | null
}

export const malzemeApi = icmalApi<MalzemeKesintisi>('malzeme')
