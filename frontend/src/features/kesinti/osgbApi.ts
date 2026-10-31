import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface OsgbKesintisi extends IcmalSatiri {
  toplam_kisi: string
  birim_fiyat: string
}

export const osgbApi = icmalApi<OsgbKesintisi>('osgb')
