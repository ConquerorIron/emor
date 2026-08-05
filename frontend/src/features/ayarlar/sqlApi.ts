import { api } from '@/api/client'

export type SqlOrtam = 'test' | 'canli'

export interface SqlBaglanti {
  id: number
  ortam: SqlOrtam
  sunucu: string
  port: number | null
  veritabani: string
  kullanici_adi: string
  aktif: boolean
  sifre_dolu: boolean
  updated_at: string | null
}

export interface SqlBaglantilar {
  test: SqlBaglanti | null
  canli: SqlBaglanti | null
  aktif_ortam: SqlOrtam | null
}

export interface SqlBaglantiGovdesi {
  sunucu: string
  port: number | null
  veritabani: string
  kullanici_adi: string
  /** Boş bırakılırsa gönderilmez — backend kayıtlı şifreyi korur */
  sifre?: string
}

export interface SinamaSonucu {
  surum: string
  veritabani: string
  kullanici: string
}

export async function sqlBaglantilariGetir(): Promise<SqlBaglantilar> {
  const yanit = await api.get<{ data: SqlBaglantilar }>('/api/v1/ayarlar/sql-baglantilari')

  return yanit.data.data
}

export async function sqlBaglantiGuncelle(
  ortam: SqlOrtam,
  govde: SqlBaglantiGovdesi,
): Promise<SqlBaglanti> {
  const yanit = await api.put<{ data: SqlBaglanti }>(
    `/api/v1/ayarlar/sql-baglantilari/${ortam}`,
    govde,
  )

  return yanit.data.data
}

/** Kaydedilmemiş form değerleriyle de sınanabilir; boş şifre kayıtlıya düşer. */
export async function sqlBaglantiSina(
  ortam: SqlOrtam,
  govde: Partial<SqlBaglantiGovdesi>,
): Promise<SinamaSonucu> {
  const yanit = await api.post<{ data: SinamaSonucu }>(
    `/api/v1/ayarlar/sql-baglantilari/${ortam}/sina`,
    govde,
  )

  return yanit.data.data
}

export async function aktifOrtamDegistir(ortam: SqlOrtam): Promise<SqlBaglanti> {
  const yanit = await api.post<{ data: SqlBaglanti }>('/api/v1/ayarlar/sql-baglantilari/aktif', {
    ortam,
  })

  return yanit.data.data
}
