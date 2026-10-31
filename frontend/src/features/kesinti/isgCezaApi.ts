import { icmalApi, type IcmalSatiri } from './icmalApi'

/** İSG ceza satırında icmale özgü kolon yok; ortak kolonlar IcmalSatiri'da */
export type IsgCezaKesintisi = IcmalSatiri

export const isgCezaApi = icmalApi<IsgCezaKesintisi>('isg-ceza')
