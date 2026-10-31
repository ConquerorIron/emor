import { icmalApi, type Icmal, type IcmalSatiri } from './icmalApi'

/** Tüm Kesintiler satırı: ortak kolonlar + satırın geldiği icmal ekranı */
export interface TumKesinti extends IcmalSatiri {
  /** id yalnız kendi icmalinde benzersiz: satırı id + icmal tanıtır */
  icmal: Icmal
}

/** Yalnız liste, özet, Excel ve filtre seçimi uçları var (veri girilmez) */
export const tumKesintilerApi = icmalApi<TumKesinti>('tumu')
