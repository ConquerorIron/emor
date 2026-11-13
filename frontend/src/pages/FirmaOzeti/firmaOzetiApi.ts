import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'
import type {
  KesintiRaporuParametreleri,
  KesintiRaporuSecenekleri,
} from '@/features/kesinti/kesintiRaporu'

/** Taşeron + proje satırı: kalanlar kesinti_turleri ile aynı sırada (noktalı iki ondalıklı metin) */
export interface FirmaOzetiSatiri {
  firma_id: number
  /** Firma kodu (ekranda); tam unvan taseron_ad */
  taseron: string
  taseron_ad: string
  proje_id: number
  proje: string
  kalanlar: string[]
  /** Kesinti türü başına satır sayısı: 0 → o türde kesinti yok ("-"), kalan 0 olsa da >0 → "0,00" */
  kayit_sayilari: number[]
  toplam_kalan: string
}

/** Backend FirmaOzeti::hesapla — yalnız taşerona yansıtılan kesintilerin kalanı */
export interface FirmaOzeti {
  tarih: string
  para_birimi: string
  /** Kolonlar: kesinti türleri, bağlı oldukları ekranın menü sırasıyla */
  kesinti_turleri: { id: number; kod: string; ad: string }[]
  satirlar: FirmaOzetiSatiri[]
  toplam: { kalanlar: string[]; kayit_sayilari: number[]; toplam_kalan: string }
}

/** Tıklanan hücre: raporun tarihi ve para birimi + satırın taşeronu ve projesi; tür yoksa (Toplam Kalan) bütün türler */
export interface EkstreParametreleri {
  tarih: string
  para_birimi_id: number
  firma_id: number
  proje_id: number
  kesinti_turu_id?: number
}

const ADRES = '/api/v1/kesinti/firma-ozeti'

export const firmaOzetiApi = {
  getir: async (parametreler: KesintiRaporuParametreleri): Promise<FirmaOzeti> => {
    const yanit = await api.get<{ data: FirmaOzeti }>(ADRES, { params: parametreler })

    return yanit.data.data
  },
  secenekler: async (): Promise<KesintiRaporuSecenekleri> => {
    const yanit = await api.get<{ data: KesintiRaporuSecenekleri }>(`${ADRES}/secenekler`)

    return yanit.data.data
  },
  /** Ekrandaki tablo + toplam satırı .xlsx */
  excel: async (parametreler: KesintiRaporuParametreleri): Promise<void> => {
    const yanit = await api
      .get<Blob>(`${ADRES}/excel`, { params: parametreler, responseType: 'blob' })
      .catch(blobHatasiniCoz)

    dosyayiIndir(yanit.data, 'firma-ozeti.xlsx')
  },
  /** Hücrenin kesinti ekstresi: PDF, A3 yatay */
  ekstre: async (parametreler: EkstreParametreleri, dosyaAdi: string): Promise<void> => {
    const yanit = await api
      .get<Blob>(`${ADRES}/ekstre`, { params: parametreler, responseType: 'blob' })
      .catch(blobHatasiniCoz)

    dosyayiIndir(yanit.data, dosyaAdi)
  },
}
