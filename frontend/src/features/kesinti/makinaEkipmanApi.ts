import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface MakinaEkipmanKesintisi extends IcmalSatiri {
  kaynak_fis_no: string | null
  makina_tanimi: string
  aktivite: string | null
  disiplin: string | null
}

export const makinaEkipmanApi = icmalApi<MakinaEkipmanKesintisi>('makina-ekipman')
