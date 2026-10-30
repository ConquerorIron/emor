import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface KampKesintisi extends IcmalSatiri {
  oda_tipi: string
  toplam_oda: string
  birim_fiyat: string
}

export const kampApi = icmalApi<KampKesintisi>('kamp')
