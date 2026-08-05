import { AxiosError, type AxiosResponse } from 'axios'
import { describe, expect, it } from 'vitest'

import { apiErrorKey } from './errors'

function axiosHata(status?: number, data?: unknown): AxiosError {
  const err = new AxiosError('test')
  if (status !== undefined) {
    err.response = { status, data } as AxiosResponse
  }
  return err
}

describe('apiErrorKey', () => {
  it('yanıt yoksa ağ hatası anahtarı döner', () => {
    expect(apiErrorKey(axiosHata())).toBe('hata.AG_HATASI')
  })

  it('backend kod alanını i18n anahtarına çevirir', () => {
    expect(apiErrorKey(axiosHata(409, { kod: 'DONEM_KILITLI' }))).toBe('hata.DONEM_KILITLI')
  })

  it('kod yoksa HTTP durum koduna göre eşler', () => {
    expect(apiErrorKey(axiosHata(401))).toBe('hata.YETKISIZ')
    expect(apiErrorKey(axiosHata(403))).toBe('hata.ERISIM_ENGELLI')
    expect(apiErrorKey(axiosHata(422))).toBe('hata.DOGRULAMA')
  })

  it('bilinmeyen hatada genel anahtar döner', () => {
    expect(apiErrorKey(new Error('x'))).toBe('hata.BILINMEYEN')
    expect(apiErrorKey(axiosHata(500))).toBe('hata.BILINMEYEN')
  })
})
