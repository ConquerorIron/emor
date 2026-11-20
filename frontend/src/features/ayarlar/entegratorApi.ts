import { api } from '@/api/client'

import type { SqlOrtam } from './sqlApi'

export type EntegratorOrtam = 'test' | 'canli'

export interface EntegratorBaglanti {
  id: number
  saglayici: 'izibiz' | 'protel'
  ortam: EntegratorOrtam
  /** Kullanılan adres (tanımlanmışsa o, değilse ortamın varsayılanı) */
  api_url: string
  /** true: adres ekrandan tanımlanmış; false: ortamın varsayılanı kullanılıyor */
  api_url_ozel: boolean
  /** Yalnız Protel: ayrı giriş (token) sunucusu — kullanılan adres */
  auth_url?: string
  /** Yalnız Protel: true ise giriş adresi ekrandan tanımlanmış */
  auth_url_ozel?: boolean
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
  /** Otelin posta kutusu (PRT-03) — aktif ortamı İzibiz'den bağımsız */
  protel: ProtelBaglantilari
}

export interface ProtelBaglantilari {
  test: EntegratorBaglanti | null
  canli: EntegratorBaglanti | null
  aktif_ortam: EntegratorOrtam | null
  /** Sunucu ayarı PROTEL_AKTIF: kapalıyken sınama ve senkron Protel'e istek göndermez */
  modul_aktif: boolean
  /** Adres alanları boş bırakılırsa kullanılacak adresler */
  varsayilan_auth_url: Record<EntegratorOrtam, string>
  varsayilan_api_url: Record<EntegratorOrtam, string>
}

/** Protel tanımı: giriş ve fatura adresleri (null = varsayılan), kullanıcı e-posta */
export interface ProtelBaglantiGovdesi {
  /** null = ortamın varsayılanı. Değişirse şifre yeniden istenir. */
  auth_url: string | null
  api_url: string | null
  kullanici_adi: string
  /** Boş bırakılırsa gönderilmez — kullanıcı aynıysa kayıtlı şifre korunur */
  sifre?: string
  vkn: string
  senkron_araligi_dakika: number
}

export interface ProtelSinamaSonucu {
  firma_unvani: string
  gecerlilik_bitis: string
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

export async function protelBaglantiGuncelle(
  ortam: EntegratorOrtam,
  govde: ProtelBaglantiGovdesi,
): Promise<EntegratorBaglanti> {
  const yanit = await api.put<{ data: EntegratorBaglanti }>(
    `/api/v1/ayarlar/entegrator-baglantilari/protel/${ortam}`,
    govde,
  )

  return yanit.data.data
}

/** Yalnız giriş denenir (AccessToken); Protel'de hiçbir şey değişmez. */
export async function protelBaglantiSina(
  ortam: EntegratorOrtam,
  govde: ProtelBaglantiGovdesi,
): Promise<ProtelSinamaSonucu> {
  const yanit = await api.post<{ data: ProtelSinamaSonucu }>(
    `/api/v1/ayarlar/entegrator-baglantilari/protel/${ortam}/sina`,
    govde,
  )

  return yanit.data.data
}

export async function protelAktifOrtamDegistir(
  ortam: EntegratorOrtam,
): Promise<EntegratorBaglanti> {
  const yanit = await api.post<{ data: EntegratorBaglanti }>(
    '/api/v1/ayarlar/entegrator-baglantilari/protel/aktif',
    { ortam },
  )

  return yanit.data.data
}
