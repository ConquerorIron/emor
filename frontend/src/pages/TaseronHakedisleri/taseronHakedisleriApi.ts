import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

/** Tutar kolonları, ekrandaki sırayla (backend TaseronHakedisleriRaporu::TUTARLAR) */
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

/** Proje + para kodu başına toplam */
export type HakedisOzeti = { proje_adi: string; para_kodu: string } & Record<Tutar, number>

/** Para kodu başına genel toplam */
export type HakedisToplami = { para_kodu: string } & Record<Tutar, number>

export interface HakedisRaporu {
  data: HakedisOzeti[]
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
