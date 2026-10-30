import { isAxiosError } from 'axios'

import { api } from '@/api/client'

/** Seçim listesi kaydı (tür, taşeron, proje, para birimi) */
export interface KesintiTanimi {
  id: number
  kod: string
  ad: string
}

/** Kesinti icmal formlarının seçim listeleri */
export interface KesintiSecenekleri {
  kesinti_turleri: KesintiTanimi[]
  firmalar: KesintiTanimi[]
  projeler: KesintiTanimi[]
  para_birimleri: KesintiTanimi[]
}

export type YansitmaTuru = 'hab' | 'taseron'

/** Sabit seçenekler (backend enum'larıyla aynı; tanım tablosu yok) */
export const YANSITMA_TURLERI: readonly YansitmaTuru[] = ['hab', 'taseron']

/**
 * Her icmal satırında aynı adla duran kolonlar; icmale özgü kolonlar
 * alt tiplerde. Tutarlar noktalı decimal metin ("2562.15").
 */
export interface IcmalSatiri {
  id: number
  /** İçe aktarımla geldiyse partisi; elle girilen satırda null */
  yukleme_id: number | null
  kesinti_turu: KesintiTanimi
  firma: KesintiTanimi
  yansitma_turu: YansitmaTuru
  proje: KesintiTanimi
  para_birimi: KesintiTanimi
  mahal: string | null
  tarih: string
  kaynak_firma: string | null
  birim_fiyat: string
  toplam_tutar: string
  iptal_edilen_tutar: string
  /** Elle girişte hesaplanır, Excel'den olduğu gibi gelir */
  yansitilan_tutar: string
  kesilen_tutar: string
  /** Elle girişte hesaplanır, Excel'den olduğu gibi gelir */
  kalan_tutar: string
}

/** Formun gönderdiği gövde: tanımlar id ile, boş metinler null */
export type IcmalGovdesi = Record<string, string | number | null>

export interface IcmalFiltresi {
  ara: string
  firma_id: string
  proje_id: string
  baslangic: string
  bitis: string
}

/** Para birimi başına toplamlar (liste ve önizleme özeti) */
export interface KesintiToplami {
  para_birimi: string
  satir_sayisi: number
  toplam_tutar: string
  iptal_edilen_tutar: string
  yansitilan_tutar: string
  kesilen_tutar: string
  kalan_tutar: string
}

export interface IcmalListesi<T extends IcmalSatiri> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
  ozet: KesintiToplami[]
}

/** İçe aktarım hatası: aynı nedenle düşen satırlar tek kalemde (örnek satır etiketleriyle) */
export interface IceAktarimHatasi {
  alan: string
  deger: string
  kod: string
  adet: number
  /** Satır numarası; birden fazla sayfa okunduysa "Sayfa › satır" */
  satirlar: (string | number)[]
}

/** Önizleme satırı: Excel'deki değerler (seçimler kod olarak) */
export type OnizlemeSatiri = Record<string, string | number | boolean | null> & {
  satir: number
  hatali: boolean
}

export interface IceAktarimOnizlemesi {
  /** dosya_okunamadi | baslik_bulunamadi | satir_yok */
  hata: string | null
  eksik_kolonlar: string[]
  satirlar: OnizlemeSatiri[]
  hatalar: IceAktarimHatasi[]
  toplamlar: KesintiToplami[]
}

export interface KesintiYuklemesi {
  id: number
  kaynak: 'excel' | 'yapistirma'
  dosya_adi: string | null
  satir_sayisi: number
  yukleyen: string | null
  olusturma_zamani: string
}

/** İçe aktarım kolonu: standart sıra ve kabul edilen başlık adları (backend tanımı) */
export interface IceAktarimKolonu {
  alan: string
  basliklar: string[]
}

/** Excel dosyası ya da yapıştırılan metin */
export type IceAktarimGirdisi = { dosya: File } | { metin: string }

export type Siralama = { anahtar: string; yon: 'asc' | 'desc' } | null

/** Bir icmal ekranının uçları (hepsi /api/v1/kesinti/<yol> altında aynı düzende) */
export interface IcmalApi<T extends IcmalSatiri> {
  listele: (filtre: IcmalFiltresi, siralama: Siralama, sayfa: number) => Promise<IcmalListesi<T>>
  kaydet: (id: number | null, govde: IcmalGovdesi) => Promise<T>
  sil: (id: number) => Promise<void>
  kolonlar: () => Promise<IceAktarimKolonu[]>
  /** Yazmadan doğrular: hata özeti, satırlar, toplamlar */
  onizle: (girdi: IceAktarimGirdisi) => Promise<IceAktarimOnizlemesi>
  /** Hatasızsa tüm satırları tek parti olarak kaydeder */
  iceAktar: (girdi: IceAktarimGirdisi) => Promise<KesintiYuklemesi>
  yuklemeler: () => Promise<KesintiYuklemesi[]>
  /** Partiyi satırlarıyla birlikte geri alır */
  geriAl: (id: number) => Promise<void>
}

/**
 * Kayıt hatalı satırlar yüzünden reddedildiyse (ICE_AKTARIM_HATALI) sunucunun
 * doğrulama sonucu — önizleme gibi gösterilir. Başka bir hataysa null.
 */
export function reddedilenAktarim(error: unknown): IceAktarimOnizlemesi | null {
  if (!isAxiosError(error) || error.response?.status !== 422) {
    return null
  }

  const govde = error.response.data as { kod?: string; data?: IceAktarimOnizlemesi } | undefined

  return govde?.kod === 'ICE_AKTARIM_HATALI' && govde.data ? govde.data : null
}

export async function kesintiSecenekleriniGetir(): Promise<KesintiSecenekleri> {
  const yanit = await api.get<{ data: KesintiSecenekleri }>('/api/v1/kesinti/secenekler')

  return yanit.data.data
}

function aktarimGovdesi(girdi: IceAktarimGirdisi): FormData {
  const govde = new FormData()

  if ('dosya' in girdi) {
    govde.append('dosya', girdi.dosya)
  } else {
    govde.append('metin', girdi.metin)
  }

  return govde
}

export function icmalApi<T extends IcmalSatiri>(yol: string): IcmalApi<T> {
  const adres = `/api/v1/kesinti/${yol}`

  return {
    listele: async (filtre, siralama, sayfa) => {
      const params: Record<string, string | number> = { page: sayfa }

      for (const [anahtar, deger] of Object.entries(filtre)) {
        if (deger !== '') {
          params[anahtar] = deger
        }
      }

      if (siralama) {
        params.sirala = siralama.anahtar
        params.yon = siralama.yon
      }

      const yanit = await api.get<IcmalListesi<T>>(adres, { params })

      return yanit.data
    },
    kaydet: async (id, govde) => {
      const yanit =
        id === null
          ? await api.post<{ data: T }>(adres, govde)
          : await api.put<{ data: T }>(`${adres}/${id}`, govde)

      return yanit.data.data
    },
    sil: async (id) => {
      await api.delete(`${adres}/${id}`)
    },
    kolonlar: async () => {
      const yanit = await api.get<{ data: IceAktarimKolonu[] }>(`${adres}/yuklemeler/kolonlar`)

      return yanit.data.data
    },
    onizle: async (girdi) => {
      const yanit = await api.post<{ data: IceAktarimOnizlemesi }>(
        `${adres}/yuklemeler/onizle`,
        aktarimGovdesi(girdi),
      )

      return yanit.data.data
    },
    iceAktar: async (girdi) => {
      const yanit = await api.post<{ data: KesintiYuklemesi }>(
        `${adres}/yuklemeler`,
        aktarimGovdesi(girdi),
      )

      return yanit.data.data
    },
    yuklemeler: async () => {
      const yanit = await api.get<{ data: KesintiYuklemesi[] }>(`${adres}/yuklemeler`)

      return yanit.data.data
    },
    geriAl: async (id) => {
      await api.delete(`${adres}/yuklemeler/${id}`)
    },
  }
}
