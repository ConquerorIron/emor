import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface KampMalzemeKesintisi extends IcmalSatiri {
  urun_tanimi: string
  miktar: string
  birim: string | null
}

export const kampMalzemeApi = icmalApi<KampMalzemeKesintisi>('kamp-malzeme')
