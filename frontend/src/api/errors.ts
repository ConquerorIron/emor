import { isAxiosError } from 'axios'

/** Backend hata sözleşmesi (rules.md §1): yanıt gövdesinde makine okunur `kod` alanı bulunur. */
export interface ApiErrorBody {
  kod?: string
  mesaj?: string
}

/**
 * Hatayı i18n çeviri anahtarına çevirir; UI `t(apiErrorKey(error))` ile gösterir.
 * Öncelik: backend `kod` alanı → HTTP durum kodu → genel hata.
 */
/**
 * 422 doğrulama yanıtındaki İLK sunucu mesajını döndürür (zaten yerelleşmiş) —
 * silme engelleri gibi domain mesajları generic "doğrulama hatası" yerine
 * olduğu gibi gösterilir. 422 değilse null.
 */
export function dogrulamaMesaji(error: unknown): string | null {
  if (isAxiosError(error) && error.response?.status === 422) {
    const govde = error.response.data as { hatalar?: Record<string, string[]> } | undefined
    return Object.values(govde?.hatalar ?? {})[0]?.[0] ?? null
  }
  return null
}

export function apiErrorKey(error: unknown): string {
  if (isAxiosError(error)) {
    if (!error.response) {
      return 'hata.AG_HATASI'
    }

    const kod = (error.response.data as ApiErrorBody | null | undefined)?.kod
    if (kod) {
      return `hata.${kod}`
    }

    switch (error.response.status) {
      case 401:
        return 'hata.YETKISIZ'
      case 403:
        return 'hata.ERISIM_ENGELLI'
      case 422:
        return 'hata.DOGRULAMA'
    }
  }

  return 'hata.BILINMEYEN'
}
