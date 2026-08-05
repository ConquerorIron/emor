import { isAxiosError } from 'axios'

import { api } from '@/api/client'

import type { LoginGirdisi } from './schemas/loginSchema'
import type { Kullanici } from './types'

interface KullaniciYaniti {
  data: Kullanici
}

export async function loginIstegi(girdi: LoginGirdisi): Promise<Kullanici> {
  // Sanctum SPA: önce CSRF cookie alınır; sonraki isteklerde axios X-XSRF-TOKEN'ı otomatik ekler
  await api.get('/sanctum/csrf-cookie')
  const yanit = await api.post<KullaniciYaniti>('/api/v1/auth/login', girdi)

  return yanit.data.data
}

export async function logoutIstegi(): Promise<void> {
  await api.post('/api/v1/auth/logout')
}

/** Oturum yoksa hata değil null döner; AuthProvider'ın me sorgusu bunu bekler. */
export async function meIstegi(): Promise<Kullanici | null> {
  try {
    const yanit = await api.get<KullaniciYaniti>('/api/v1/auth/me')

    return yanit.data.data
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 401) {
      return null
    }
    throw error
  }
}
