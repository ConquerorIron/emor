import { isAxiosError } from 'axios'

import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

/** Kesinti türünün bağlanabileceği icmal ekranları (backend KesintiYuklemesi::ICMALLER) */
export const ICMALLER = ['isgucu', 'kamp', 'kamp_malzeme', 'makina_ekipman'] as const

export type Icmal = (typeof ICMALLER)[number]

/** Seçim listesi kaydı (tür, taşeron, proje, para birimi) */
export interface KesintiTanimi {
  id: number
  kod: string
  ad: string
  /** Yalnız kesinti türlerinde: bağlı olduğu ekran (null: hiçbir ekranda kullanılamaz) */
  icmal?: Icmal | null
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
  /** İcmale özgü ek toplamlar (ör. İşgücü'nde miktar_saat) */
  [alan: string]: string | number
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

/** Satırdaki bir hata: hangi kolon, hangi neden */
export interface SatirHatasi {
  alan: string
  kod: string
}

/** Kontrol edilen satır: Excel'deki değerler (seçimler kod olarak) ve kendi hataları */
export type OnizlemeSatiri = Record<string, unknown> & {
  satir: number
  /** Birden fazla Excel sayfası okunduysa satırın sayfası */
  sayfa?: string | null
  hatali: boolean
  satir_hatalari: SatirHatasi[]
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

/**
 * Özet (pivot) satırı: gruplanan alanlar (tanımlar kod olarak, yil / ay sayı),
 * satır sayısı ve toplamlar (noktalı decimal metin)
 */
export type PivotSatiri = Record<string, string | number | null> & { satir_sayisi: number }

/** Bir icmal ekranının uçları (hepsi /api/v1/kesinti/<yol> altında aynı düzende) */
export interface IcmalApi<T extends IcmalSatiri> {
  listele: (filtre: IcmalFiltresi, siralama: Siralama, sayfa: number) => Promise<IcmalListesi<T>>
  /** Özet sekmesi: listeyle aynı filtre, sekmenin alanlarına göre gruplanmış toplamlar */
  pivot: (pivot: string, filtre: IcmalFiltresi) => Promise<PivotSatiri[]>
  /** Filtredeki tüm satırlar, tüm kolonlar (gizliler dahil) .xlsx olarak indirilir */
  excel: (filtre: IcmalFiltresi, siralama: Siralama, dosyaAdi: string) => Promise<void>
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

/** Liste filtresi ve sıralaması sorgu parametresi olarak (boş filtreler gönderilmez) */
function listeParametreleri(
  filtre: IcmalFiltresi,
  siralama: Siralama,
): Record<string, string | number> {
  const params: Record<string, string | number> = {}

  for (const [anahtar, deger] of Object.entries(filtre)) {
    if (deger !== '') {
      params[anahtar] = deger
    }
  }

  if (siralama) {
    params.sirala = siralama.anahtar
    params.yon = siralama.yon
  }

  return params
}

export function icmalApi<T extends IcmalSatiri>(yol: string): IcmalApi<T> {
  const adres = `/api/v1/kesinti/${yol}`

  return {
    listele: async (filtre, siralama, sayfa) => {
      const yanit = await api.get<IcmalListesi<T>>(adres, {
        params: { ...listeParametreleri(filtre, siralama), page: sayfa },
      })

      return yanit.data
    },
    pivot: async (pivot, filtre) => {
      const yanit = await api.get<{ data: PivotSatiri[] }>(`${adres}/pivot/${pivot}`, {
        params: listeParametreleri(filtre, null),
      })

      return yanit.data.data
    },
    excel: async (filtre, siralama, dosyaAdi) => {
      const yanit = await api
        .get<Blob>(`${adres}/excel`, {
          params: listeParametreleri(filtre, siralama),
          responseType: 'blob',
        })
        .catch(blobHatasiniCoz)

      dosyayiIndir(yanit.data, dosyaAdi)
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
