import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface YemekKesintisi extends IcmalSatiri {
  kaynak_hakedis_no: string | null
}

export const yemekApi = icmalApi<YemekKesintisi>('yemek')
