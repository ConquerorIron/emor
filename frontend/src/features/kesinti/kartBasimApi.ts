import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface KartBasimKesintisi extends IcmalSatiri {
  personel_adi_soyadi: string
  urun_tanimi: string
  kart_basim_nedeni: string | null
  malzeme_birim_fiyati: string
  miktar: string
  birim: string | null
}

export const kartBasimApi = icmalApi<KartBasimKesintisi>('kart-basim')
