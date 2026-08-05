import axios, { isAxiosError } from 'axios'
import { toast } from 'sonner'

import i18n from '@/i18n/i18n'
import { sayfaBoyutuOku } from '@/utils/sayfaBoyutu'

import { apiErrorKey } from './errors'

/** 401 yakalandığında (auth uçları hariç) yayınlanır; AuthProvider dinleyip oturumu düşürür. */
export const YETKISIZ_EVENT = 'puantaj:yetkisiz'

/**
 * Tek axios instance — bileşen içinde axios import etmek yasak (rules.md §2).
 * Sanctum SPA cookie auth: withCredentials + XSRF cookie'sini axios otomatik yönetir.
 */
export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

// Sayfa boyutu ORTAK tercihtir: `page` parametreli tüm liste istekleri
// kullanıcının seçtiği boyutu taşır (Pagination'daki seçici — tek kaynak)
api.interceptors.request.use((config) => {
  const params = config.params as Record<string, unknown> | undefined
  if (config.method === 'get' && params && 'page' in params && !('sayfa_boyutu' in params)) {
    params['sayfa_boyutu'] = sayfaBoyutuOku()
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    if (isAxiosError(error) && error.response) {
      const url = error.config?.url ?? ''

      // Oturum düşmüşse login'e dönülür; auth uçlarının kendi 401 akışı vardır
      if (error.response.status === 401 && !url.includes('/auth/')) {
        window.dispatchEvent(new Event(YETKISIZ_EVENT))
      }

      if (error.response.status === 403) {
        const anahtar = apiErrorKey(error)
        toast.error(i18n.t(anahtar))

        // Hesap pasife alındıysa oturum backend'de düşürüldü; login'e dön
        if (anahtar === 'hata.HESAP_PASIF') {
          window.dispatchEvent(new Event(YETKISIZ_EVENT))
        }
      }
    }

    return Promise.reject(error instanceof Error ? error : new Error(String(error)))
  },
)
