import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'

/** ERP rapor proc'larının ortak girdileri (backend ErpRaporRequest); boş proje / firma = hepsi */
export interface RaporParametreleri {
  ilk_tarih: string
  son_tarih: string
  proje_kodu: string
  firma_adi: string
}

/** Parametre formunun seçenekleri: proc'lar proje KODU ve firma ADIYLA süzer */
export interface RaporSecenekleri {
  projeler: { kod: string; ad: string }[]
  firmalar: string[]
}

/** Rapor satırı: kolon alanı → değer (tarihler YYYY-MM-DD, tutarlar sayı) */
export type RaporSatiri = Record<string, string | number | null>

export interface ErpRaporApi<T extends RaporSatiri> {
  getir: (parametreler: RaporParametreleri) => Promise<T[]>
  secenekler: () => Promise<RaporSecenekleri>
  /**
   * Raporu aynı parametrelerle yeniden çalıştırıp ekranda süzülen satırları
   * (anahtarlarıyla, ekrandaki sırayla) .xlsx olarak indirir — Excel ekranla aynı
   */
  excel: (girdi: { parametreler: RaporParametreleri; anahtarlar: string[] }) => Promise<void>
}

/** Raporlar menüsündeki bir raporun uçları (backend ErpRaporController) */
export function erpRaporApi<T extends RaporSatiri>(
  adres: string,
  dosyaAdi: string,
): ErpRaporApi<T> {
  return {
    getir: async (parametreler) => {
      const yanit = await api.get<{ data: T[] }>(adres, { params: parametreler })

      return yanit.data.data
    },
    secenekler: async () => {
      const yanit = await api.get<{ data: RaporSecenekleri }>(`${adres}/secenekler`)

      return yanit.data.data
    },
    excel: async ({ parametreler, anahtarlar }) => {
      const yanit = await api
        .post<Blob>(`${adres}/excel`, { ...parametreler, anahtarlar }, { responseType: 'blob' })
        .catch(blobHatasiniCoz)

      dosyayiIndir(yanit.data, dosyaAdi)
    },
  }
}
