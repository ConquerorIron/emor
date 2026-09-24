import { api } from '@/api/client'

export type MailSifreleme = 'tls' | 'ssl' | 'yok'

export interface MailAyari {
  sunucu: string
  port: number
  sifreleme: MailSifreleme
  kullanici_adi: string | null
  sifre_dolu: boolean
  gonderen_adres: string
  gonderen_ad: string
  /** Doluysa uygulamanın gönderdiği TÜM mailler yalnız bu adrese gider */
  yonlendirme_adresi: string | null
  updated_at: string | null
}

export interface MailAyarGovdesi {
  sunucu: string
  port: number
  sifreleme: MailSifreleme
  kullanici_adi: string | null
  /** Boş bırakılırsa gönderilmez — sunucu/port/kullanıcı değişmediyse kayıtlı şifre korunur */
  sifre?: string
  gonderen_adres: string
  gonderen_ad: string
  yonlendirme_adresi: string | null
}

/** Yalnız sistem yöneticisi. Tanım yoksa null. */
export async function mailAyariGetir(): Promise<MailAyari | null> {
  const yanit = await api.get<{ data: MailAyari | null }>('/api/v1/ayarlar/mail')

  return yanit.data.data
}

export async function mailAyariGuncelle(govde: MailAyarGovdesi): Promise<MailAyari> {
  const yanit = await api.put<{ data: MailAyari }>('/api/v1/ayarlar/mail', govde)

  return yanit.data.data
}

/** Kayıtlı tanımla senkron test maili gönderir (kaydedilmemiş form değerleri kullanılmaz). */
export async function testMailiGonder(alici: string): Promise<void> {
  await api.post('/api/v1/ayarlar/mail/test', { alici })
}
