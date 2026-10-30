import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface KampKesintisi extends IcmalSatiri {
  oda_tipi: string
  toplam_oda: string
}

export const kampApi = icmalApi<KampKesintisi>('kamp')
