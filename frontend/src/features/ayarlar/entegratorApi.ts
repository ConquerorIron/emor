import { api } from '@/api/client'

import type { SqlOrtam } from './sqlApi'

export type EntegratorOrtam = 'test' | 'canli'

export interface EntegratorBaglanti {
  id: number
  saglayici: 'izibiz'
  ortam: EntegratorOrtam
  /** Kullanılan adres (tanımlanmışsa o, değilse ortamın varsayılanı) */
  api_url: string
  /** true: adres ekrandan tanımlanmış; false: ortamın varsayılanı kullanılıyor */
  api_url_ozel: boolean
  portal_url: string
  kullanici_adi: string
  vkn: string
  posta_kutusu: string | null
  gonderici_birim: string | null
  /** Otomatik senkron aralığı (dk) — İzibiz kuralı: en az 15 */
  senkron_araligi_dakika: number
  /** Tek istekteki fatura sayısı — İzibiz kuralı: en çok 100 */
  sayfa_boyutu: number
  aktif: boolean
  sifre_dolu: boolean
  updated_at: string | null
}

export interface EntegratorBaglantilar {
  test: EntegratorBaglanti | null
  canli: EntegratorBaglanti | null
  aktif_ortam: EntegratorOrtam | null
  /** SQL'in aktif ortamı — seçimler bağımsızdır, uyuşmazlık uyarı olarak gösterilir */
  sql_aktif_ortam: SqlOrtam | null
  ortam_uyumsuz: boolean
  /** Adres alanı boş bırakılırsa kullanılacak adresler */
  varsayilan_api_url: Record<EntegratorOrtam, string>
}

export interface EntegratorBaglantiGovdesi {
  /** null = ortamın varsayılan adresi. Değişirse şifre yeniden istenir. */
  api_url: string | null
  kullanici_adi: string
  /** Boş bırakılırsa gönderilmez — kullanıcı adı değişmediyse backend kayıtlı şifreyi korur */
  sifre?: string
  vkn: string
  posta_kutusu: string | null
  gonderici_birim: string | null
  senkron_araligi_dakika: number
  sayfa_boyutu: number
}

export interface EntegratorSinamaSonucu {
  musteri_tipi: string
  /** ISO 8601 (UTC) — alınan token'ın geçerlilik sonu */
  gecerlilik_bitis: string
}

/** Yalnız sistem yöneticisi — diğer kullanıcılar 403 alır. */
export async function entegratorBaglantilariGetir(): Promise<EntegratorBaglantilar> {
  const yanit = await api.get<{ data: EntegratorBaglantilar }>(
    '/api/v1/ayarlar/entegrator-baglantilari',
  )

  return yanit.data.data
}

export async function entegratorBaglantiGuncelle(
  ortam: EntegratorOrtam,
  govde: EntegratorBaglantiGovdesi,
): Promise<EntegratorBaglanti> {
  const yanit = await api.put<{ data: EntegratorBaglanti }>(
    `/api/v1/ayarlar/entegrator-baglantilari/${ortam}`,
    govde,
  )

  return yanit.data.data
}

/**
 * Kaydedilmemiş adres/kullanıcı/şifreyle de sınanabilir. Boş şifre yalnız
 * kullanıcı adı VE adres kayıtlıyla aynıysa kayıtlı şifreye düşer.
 */
export async function entegratorBaglantiSina(
  ortam: EntegratorOrtam,
  govde: { api_url: string | null; kullanici_adi: string; sifre?: string },
): Promise<EntegratorSinamaSonucu> {
  const yanit = await api.post<{ data: EntegratorSinamaSonucu }>(
    `/api/v1/ayarlar/entegrator-baglantilari/${ortam}/sina`,
    govde,
  )

  return yanit.data.data
}

export async function entegratorAktifOrtamDegistir(
  ortam: EntegratorOrtam,
): Promise<EntegratorBaglanti> {
  const yanit = await api.post<{ data: EntegratorBaglanti }>(
    '/api/v1/ayarlar/entegrator-baglantilari/aktif',
    { ortam },
  )

  return yanit.data.data
}
