import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'
import type {
  KesintiRaporuParametreleri,
  KesintiRaporuSecenekleri,
} from '@/features/kesinti/kesintiRaporu'

/** Satırın tutarları: kalanlar kesinti_turleri ile aynı sırada (noktalı iki ondalıklı metin) */
export interface KalanSatiri {
  kalanlar: string[]
  /** Kesinti türü başına satır sayısı: 0 → o türde kesinti yok ("-"), kalan 0 olsa da >0 → "0,00" */
  kayit_sayilari: number[]
  toplam_kalan: string
}

/** Firma Özeti sekmesi: taşeron başına (projeler toplanır) */
export interface FirmaSatiri extends KalanSatiri {
  firma_id: number
  /** Firma kodu (ekranda); tam unvan taseron_ad */
  taseron: string
  taseron_ad: string
}

/** Proje Özeti sekmesi: proje başına (taşeronlar toplanır) */
export interface ProjeSatiri extends KalanSatiri {
  proje_id: number
  /** Proje kodu (ekranda); adı proje_ad */
  proje: string
  proje_ad: string
}

/** Özet sekmesi: taşeron + proje */
export interface FirmaOzetiSatiri extends FirmaSatiri, ProjeSatiri {}

/** Backend FirmaOzeti::hesapla — yalnız taşerona yansıtılan kesintilerin kalanı; üç sekmenin satırları */
export interface FirmaOzeti {
  tarih: string
  para_birimi: string
  /** Kolonlar: kesinti türleri, bağlı oldukları ekranın menü sırasıyla */
  kesinti_turleri: { id: number; kod: string; ad: string }[]
  satirlar: FirmaOzetiSatiri[]
  firma_satirlari: FirmaSatiri[]
  proje_satirlari: ProjeSatiri[]
  toplam: KalanSatiri
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
  /** Üç sekme, sekme başına sayfa (tablo + toplam satırı) .xlsx */
  excel: async (parametreler: KesintiRaporuParametreleri): Promise<void> => {
    const yanit = await api
      .get<Blob>(`${ADRES}/excel`, { params: parametreler, responseType: 'blob' })
      .catch(blobHatasiniCoz)

    dosyayiIndir(yanit.data, 'pivot.xlsx')
  },
  /** Hücrenin kesinti ekstresi: PDF, A3 yatay */
  ekstre: async (parametreler: EkstreParametreleri, dosyaAdi: string): Promise<void> => {
    const yanit = await api
      .get<Blob>(`${ADRES}/ekstre`, { params: parametreler, responseType: 'blob' })
      .catch(blobHatasiniCoz)

    dosyayiIndir(yanit.data, dosyaAdi)
  },
}
