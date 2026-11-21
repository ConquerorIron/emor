import { afterEach, describe, expect, it, vi } from 'vitest'

import { YETKISIZ_EVENT } from '@/api/client'

import { ustuneDurumGetir, UstuneGeriYuklemeHatasi, ustuneKararVer } from './ustuneGeriYuklemeApi'

const ISLEM = '11111111-1111-4111-8111-111111111111'
const ANAHTAR = 'b'.repeat(64)

function yanit(durum: number, govde: unknown): Response {
  return new Response(JSON.stringify(govde), {
    status: durum,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('üstüne geri yükleme ilerleme uçları', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('durumu oturum çerezi göndermeden, yalnız erişim anahtarı başlığıyla ister', async () => {
    const fetchSahte = vi.fn().mockResolvedValue(yanit(200, { data: { durum: 'calisiyor' } }))
    vi.stubGlobal('fetch', fetchSahte)

    await ustuneDurumGetir(ISLEM, ANAHTAR, '22222222-2222-4222-8222-222222222222')

    expect(fetchSahte).toHaveBeenCalledWith(
      `/api/v1/yedekleme/ustune-geri-yukleme/${ISLEM}/durum?istek=22222222-2222-4222-8222-222222222222`,
      expect.objectContaining({
        method: 'GET',
        credentials: 'omit',
        headers: expect.objectContaining({ 'X-Erisim-Anahtari': ANAHTAR }) as unknown,
      }),
    )
  })

  it('401 "anahtar yok" demektir: oturumu düşürmez, kodu taşıyan hata fırlatır', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(yanit(401, { kod: 'GERI_YUKLEME_ERISIM_ANAHTARI_YOK' })),
    )
    const dinleyici = vi.fn()
    window.addEventListener(YETKISIZ_EVENT, dinleyici)

    const hata = await ustuneDurumGetir(ISLEM, ANAHTAR, null).catch((e: unknown) => e)

    window.removeEventListener(YETKISIZ_EVENT, dinleyici)
    expect(hata).toBeInstanceOf(UstuneGeriYuklemeHatasi)
    expect(hata).toMatchObject({ durum: 401, kod: 'GERI_YUKLEME_ERISIM_ANAHTARI_YOK' })
    expect(dinleyici).not.toHaveBeenCalled()
  })

  it('onay ve geri dönüş kendi uçlarına anahtarla POST edilir', async () => {
    const fetchSahte = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(yanit(202, { data: { istek_kimligi: 'x', durum: 'kuyrukta' } })),
      )
    vi.stubGlobal('fetch', fetchSahte)

    await ustuneKararVer(ISLEM, ANAHTAR, 'onayla', { mail: 'guncel' })
    await ustuneKararVer(ISLEM, ANAHTAR, 'geri-don')

    expect(
      fetchSahte.mock.calls.map(([adres, secenek]) => [
        adres,
        (secenek as RequestInit).method,
        (secenek as RequestInit).body,
      ]),
    ).toEqual([
      [
        `/api/v1/yedekleme/ustune-geri-yukleme/${ISLEM}/onayla`,
        'POST',
        JSON.stringify({ ayar_secimleri: { mail: 'guncel' } }),
      ],
      [`/api/v1/yedekleme/ustune-geri-yukleme/${ISLEM}/geri-don`, 'POST', undefined],
    ])
    expect((fetchSahte.mock.calls[0][1] as RequestInit).headers).toEqual(
      expect.objectContaining({ 'Content-Type': 'application/json', 'X-Erisim-Anahtari': ANAHTAR }),
    )
    expect((fetchSahte.mock.calls[1][1] as RequestInit).credentials).toBe('omit')
  })
})
