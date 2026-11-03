import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

/** Tutar kolonları (backend TaseronHakedisleriRaporu::TUTARLAR) */
export const TUTARLAR = [
  'imalat',
  'tutanak',
  'hakedis',
  'imalat_kesintisi',
  'kdv',
  'kdvli_hakedis',
  'kdv_tevkifati',
  'cari_kesinti',
  'yansitilacak_gider',
  'taseron_adina_yapilan_is',
  'net_odenecek',
  'odeme_talebi_tutari',
  'odenen_tutar',
] as const

export type Tutar = (typeof TUTARLAR)[number]

/** ERP proc'unun girdileri; boş proje / firma = hepsi */
export interface RaporParametreleri {
  ilk_tarih: string
  son_tarih: string
  proje_kodu: string
  firma_adi: string
}

/** Hakediş başına satır; tarihler YYYY-MM-DD, onay saati SS:DD:ss */
export interface HakedisSatiri extends Record<Tutar, number> {
  hakedis_id: number
  firma_adi: string
  proje_adi: string
  sozlesme_no: string
  sozlesme_konusu: string
  hakedis_no: string
  hakedis_tarihi: string | null
  donem_baslangici: string | null
  donem_sonu: string | null
  para_kodu: string
  onay_durumu: string
  onaylayan: string
  onay_tarihi: string | null
  onay_saati: string | null
  fatura_no: string
  hakedis_durumu: string
}

/** Kolonlar, Sıra No'dan sonra kullanıcının sırasıyla (backend TaseronHakedisleriRaporu::KOLONLAR) */
export const KOLONLAR: {
  alan: Exclude<keyof HakedisSatiri, 'hakedis_id'>
  tur: 'metin' | 'tarih' | 'tutar'
}[] = [
  { alan: 'firma_adi', tur: 'metin' },
  { alan: 'proje_adi', tur: 'metin' },
  { alan: 'sozlesme_no', tur: 'metin' },
  { alan: 'sozlesme_konusu', tur: 'metin' },
  { alan: 'hakedis_no', tur: 'metin' },
  { alan: 'hakedis_tarihi', tur: 'tarih' },
  { alan: 'donem_baslangici', tur: 'tarih' },
  { alan: 'donem_sonu', tur: 'tarih' },
  { alan: 'para_kodu', tur: 'metin' },
  { alan: 'imalat', tur: 'tutar' },
  { alan: 'tutanak', tur: 'tutar' },
  { alan: 'hakedis', tur: 'tutar' },
  { alan: 'imalat_kesintisi', tur: 'tutar' },
  { alan: 'kdv', tur: 'tutar' },
  { alan: 'kdvli_hakedis', tur: 'tutar' },
  { alan: 'kdv_tevkifati', tur: 'tutar' },
  { alan: 'cari_kesinti', tur: 'tutar' },
  { alan: 'yansitilacak_gider', tur: 'tutar' },
  { alan: 'taseron_adina_yapilan_is', tur: 'tutar' },
  { alan: 'net_odenecek', tur: 'tutar' },
  { alan: 'onay_durumu', tur: 'metin' },
  { alan: 'onaylayan', tur: 'metin' },
  { alan: 'onay_tarihi', tur: 'tarih' },
  { alan: 'onay_saati', tur: 'metin' },
  { alan: 'fatura_no', tur: 'metin' },
  { alan: 'hakedis_durumu', tur: 'metin' },
  { alan: 'odeme_talebi_tutari', tur: 'tutar' },
  { alan: 'odenen_tutar', tur: 'tutar' },
]

/** Para kodu başına genel toplam */
export type HakedisToplami = { para_kodu: string } & Record<Tutar, number>

export interface HakedisRaporu {
  data: HakedisSatiri[]
  toplamlar: HakedisToplami[]
}

/** Süzgeç seçenekleri: proc proje KODU ve firma ADIYLA süzer */
export interface HakedisSecenekleri {
  projeler: { kod: string; ad: string }[]
  firmalar: string[]
}

const ADRES = '/api/v1/raporlar/taseron-hakedisleri'

export async function taseronHakedisleriGetir(
  parametreler: RaporParametreleri,
): Promise<HakedisRaporu> {
  const yanit = await api.get<HakedisRaporu>(ADRES, { params: parametreler })

  return yanit.data
}

export async function taseronHakedisSecenekleriGetir(): Promise<HakedisSecenekleri> {
  const yanit = await api.get<{ data: HakedisSecenekleri }>(`${ADRES}/secenekler`)

  return yanit.data.data
}

/** Raporu aynı parametrelerle yeniden çalıştırıp .xlsx olarak indirir */
export async function taseronHakedisleriExcel(parametreler: RaporParametreleri): Promise<void> {
  const yanit = await api
    .get<Blob>(`${ADRES}/excel`, { params: parametreler, responseType: 'blob' })
    .catch(blobHatasiniCoz)

  dosyayiIndir(yanit.data, 'taseron-hakedisleri.xlsx')
}
