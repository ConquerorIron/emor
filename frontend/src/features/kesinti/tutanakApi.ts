import { icmalApi, type IcmalSatiri } from './icmalApi'

export interface TutanakKesintisi extends IcmalSatiri {
  kaynak_tutanak_no: string | null
  kaynak_sozlesme: string | null
  kaynak_hakedis_no: string | null
  aktivite: string | null
}

export const tutanakApi = icmalApi<TutanakKesintisi>('tutanak')
