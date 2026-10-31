import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface TetkikKesintisi extends IcmalSatiri {
  kaynak_hakedis_no: string | null
  adi_soyadi: string
}

export const tetkikApi = icmalApi<TetkikKesintisi>('tetkik')
