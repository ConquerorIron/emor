import { describe, expect, it } from 'vitest'

import type { Kullanici } from '@/features/auth/types'

import { gorunurGruplar, NAV_GRUPLARI } from './navGruplari'

const kullanici = (izinler: string[], yedeklemeAktif: boolean): Kullanici => ({
  id: 1,
  ad: 'Deneme',
  kullanici_adi: 'deneme',
  email: null,
  kaynak: 'erp',
  sistem_yoneticisi: false,
  izinler,
  yedekleme_aktif: yedeklemeAktif,
})

const adresler = (user: Kullanici) =>
  gorunurGruplar(NAV_GRUPLARI, user).flatMap((grup) => grup.ogeler.map((oge) => oge.to))

const YEDEK_IZINLERI = ['yedekler.goruntule', 'yedek_planlari.goruntule', 'mail_ayarlari.goruntule']

describe('gorunurGruplar', () => {
  it('yedekleme modülü kapalıyken izin olsa da yedekleme menüleri görünmez', () => {
    const gorunen = adresler(kullanici(YEDEK_IZINLERI, false))

    expect(gorunen).toContain('/ayarlar/mail')
    expect(gorunen.some((adres) => adres.includes('yedek'))).toBe(false)
  })

  it('modül açıkken her ekran kendi görüntüleme izniyle görünür', () => {
    expect(adresler(kullanici(['yedekler.goruntule'], true))).toEqual(
      expect.arrayContaining([
        '/ayarlar/yedekler',
        '/ayarlar/geri-yuklemeler',
        '/ayarlar/yedek-islem-gunlugu',
      ]),
    )
    expect(adresler(kullanici(['yedekler.goruntule'], true))).not.toContain(
      '/ayarlar/yedek-planlari',
    )
    expect(adresler(kullanici(['yedek_planlari.goruntule'], true))).toEqual([
      '/',
      '/ayarlar/yedek-planlari',
      '/ayarlar/yedek-hedefleri',
      '/ayarlar/yedekleme-ayarlari',
    ])
  })
})
