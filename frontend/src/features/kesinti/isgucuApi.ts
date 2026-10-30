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

export type Vasif = 'nitelikli' | 'niteliksiz'
export type MesaiTuru = 'normal' | 'fazla'
export type YansitmaTuru = 'hab' | 'taseron'

/** Sabit seçenekler (backend enum'larıyla aynı; tanım tablosu yok) */
export const VASIFLAR: readonly Vasif[] = ['nitelikli', 'niteliksiz']
export const MESAI_TURLERI: readonly MesaiTuru[] = ['normal', 'fazla']
export const YANSITMA_TURLERI: readonly YansitmaTuru[] = ['hab', 'taseron']

/** Formun gönderdiği alanlar; tutarlar noktalı metin ("2562.15") */
export interface IsgucuKesintisiGovdesi {
  kesinti_turu_id: number
  firma_id: number
  proje_id: number
  para_birimi_id: number
  tarih: string
  mahal: string | null
  kaynak_firma: string | null
  kaynak_fis_no: string | null
  adi_soyadi: string
  vasif: Vasif
  mesai_turu: MesaiTuru
  yansitma_turu: YansitmaTuru
  tanim: string | null
  gorev: string | null
  departman: string | null
  aktivite: string | null
  miktar_saat: string
  miktar_yevmiye: string
  birim_fiyat: string
  toplam_tutar: string
  iptal_edilen_tutar: string
  kesilen_tutar: string
}

export interface IsgucuKesintisi extends Omit<
  IsgucuKesintisiGovdesi,
  'kesinti_turu_id' | 'firma_id' | 'proje_id' | 'para_birimi_id'
> {
  id: number
  /** İçe aktarımla geldiyse partisi; elle girilen satırda null */
  yukleme_id: number | null
  kesinti_turu: KesintiTanimi
  firma: KesintiTanimi
  proje: KesintiTanimi
  para_birimi: KesintiTanimi
  /** Elle girişte hesaplanır, Excel'den olduğu gibi gelir */
  yansitilan_tutar: string
  kalan_tutar: string
}

export interface IsgucuFiltresi {
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

export interface IsgucuListesi {
  data: IsgucuKesintisi[]
  meta: { current_page: number; last_page: number; total: number }
  ozet: KesintiToplami[]
}

/** İçe aktarım hatası: aynı nedenle düşen satırlar tek kalemde (örnek satır numaralarıyla) */
export interface IceAktarimHatasi {
  alan: string
  deger: string
  kod: string
  adet: number
  satirlar: number[]
}

/** Önizleme satırı: Excel'deki değerler (seçimler kod olarak) */
export type OnizlemeSatiri = Record<string, string | number | boolean> & {
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

export async function isgucuAktarimKolonlariniGetir(): Promise<IceAktarimKolonu[]> {
  const yanit = await api.get<{ data: IceAktarimKolonu[] }>(
    '/api/v1/kesinti/isgucu/yuklemeler/kolonlar',
  )

  return yanit.data.data
}

/** Excel dosyası ya da yapıştırılan metin */
export type IceAktarimGirdisi = { dosya: File } | { metin: string }

export async function kesintiSecenekleriniGetir(): Promise<KesintiSecenekleri> {
  const yanit = await api.get<{ data: KesintiSecenekleri }>('/api/v1/kesinti/secenekler')

  return yanit.data.data
}

export async function isgucuKesintileriniGetir(
  filtre: IsgucuFiltresi,
  siralama: { anahtar: string; yon: 'asc' | 'desc' } | null,
  sayfa: number,
): Promise<IsgucuListesi> {
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

  const yanit = await api.get<IsgucuListesi>('/api/v1/kesinti/isgucu', { params })

  return yanit.data
}

export async function isgucuKesintisiKaydet(
  id: number | null,
  govde: IsgucuKesintisiGovdesi,
): Promise<IsgucuKesintisi> {
  const yanit =
    id === null
      ? await api.post<{ data: IsgucuKesintisi }>('/api/v1/kesinti/isgucu', govde)
      : await api.put<{ data: IsgucuKesintisi }>(`/api/v1/kesinti/isgucu/${id}`, govde)

  return yanit.data.data
}

export async function isgucuKesintisiSil(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/isgucu/${id}`)
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

/** Yazmadan doğrular: hata özeti, satırlar, toplamlar */
export async function isgucuOnizle(girdi: IceAktarimGirdisi): Promise<IceAktarimOnizlemesi> {
  const yanit = await api.post<{ data: IceAktarimOnizlemesi }>(
    '/api/v1/kesinti/isgucu/yuklemeler/onizle',
    aktarimGovdesi(girdi),
  )

  return yanit.data.data
}

/** Hatasızsa tüm satırları tek parti olarak kaydeder */
export async function isgucuIceAktar(girdi: IceAktarimGirdisi): Promise<KesintiYuklemesi> {
  const yanit = await api.post<{ data: KesintiYuklemesi }>(
    '/api/v1/kesinti/isgucu/yuklemeler',
    aktarimGovdesi(girdi),
  )

  return yanit.data.data
}

export async function isgucuYuklemeleriniGetir(): Promise<KesintiYuklemesi[]> {
  const yanit = await api.get<{ data: KesintiYuklemesi[] }>('/api/v1/kesinti/isgucu/yuklemeler')

  return yanit.data.data
}

/** Partiyi satırlarıyla birlikte geri alır */
export async function isgucuYuklemesiniGeriAl(id: number): Promise<void> {
  await api.delete(`/api/v1/kesinti/isgucu/yuklemeler/${id}`)
}
