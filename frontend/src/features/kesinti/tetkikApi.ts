import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface TetkikKesintisi extends IcmalSatiri {
  adi_soyadi: string
}

export const tetkikApi = icmalApi<TetkikKesintisi>('tetkik')
