import { afterEach, describe, expect, it } from 'vitest'

import { boyutGoster, istanbulZamani, parmakIziGoster, yedeklemeSayfaBoyutu } from './bicim'

describe('yedekleme biçimleri', () => {
  afterEach(() => localStorage.clear())

  it('ortak sayfa boyutu tercihini ucun 10–100 sınırına çeker', () => {
    localStorage.setItem('erp.sayfaBoyutu', '200')
    expect(yedeklemeSayfaBoyutu()).toBe(100)
    localStorage.setItem('erp.sayfaBoyutu', '0')
    expect(yedeklemeSayfaBoyutu()).toBe(100)
    localStorage.setItem('erp.sayfaBoyutu', '25')
    expect(yedeklemeSayfaBoyutu()).toBe(25)
  })

  it('boyutu 1024 tabanlı birimle ve Türkçe ondalıkla gösterir', () => {
    expect(boyutGoster(512)).toBe('512 B')
    expect(boyutGoster(1677722)).toBe('1,6 MB')
    expect(boyutGoster(null)).toBe('—')
  })

  it('zamanı tarayıcının saat diliminden bağımsız İstanbul saatiyle gösterir', () => {
    expect(istanbulZamani('2026-11-20T23:00:00+00:00')).toBe('21.11.2026 02:00')
    expect(istanbulZamani(null)).toBe('—')
  })

  it('parmak izini boşluksuz, büyük harfli dörderli gruplara ayırır', () => {
    expect(parmakIziGoster('abcd 1234ef56')).toBe('ABCD 1234 EF56')
  })
})
