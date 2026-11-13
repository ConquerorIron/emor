import { api } from '@/api/client'
import { blobHatasiniCoz, dosyayiIndir } from '@/api/indirme'
import type {
  KesintiRaporuParametreleri,
  KesintiRaporuSecenekleri,
} from '@/features/kesinti/kesintiRaporu'

/** Genel özetin ve her grubun tutarları (backend KesintiOzeti::TUTARLAR) */
export const OZET_TUTARLARI = ['toplam', 'hab', 'taseron', 'iptal', 'kesilen', 'kalan'] as const

export type OzetTutari = (typeof OZET_TUTARLARI)[number]

/** Noktalı iki ondalıklı metin ("2050.00"); hab = HAB'ın üstlendiği, diğerleri taşeron payı ve kırılımı */
export type OzetTutarlari = Record<OzetTutari, string>

/** Proje, kesinti türü ya da firma: kod (ekranda) + ad (üzerine gelince) + tutarlar */
export interface OzetGrubu extends OzetTutarlari {
  kod: string
  ad: string
}

export interface KesintiOzeti {
  tarih: string
  para_birimi: string
  /** Firmamız (Firmalar'da işaretli); yoksa null */
  isveren: string | null
  /** Başlıktaki "Bütçe": seçilen projeler, seçim yoksa veride geçenler */
  projeler: string[]
  /** Başlıktaki "Taşeron": seçilen firmalar; seçim yoksa boş (satır çizilmez) */
  firmalar: string[]
  genel: OzetTutarlari
  proje_dagilimi: OzetGrubu[]
  tur_dagilimi: OzetGrubu[]
  /** En yüksek kesintili taşeronlar: yalnız taşerona yansıtılanlar (toplam = taşeron payı) */
  taseronlar: OzetGrubu[]
}

const ADRES = '/api/v1/kesinti/ozet'

/** Firma süzgeci HAB'ın üstlendiği kesintileri de süzer */
export const kesintiOzetiApi = {
  getir: async (parametreler: KesintiRaporuParametreleri): Promise<KesintiOzeti> => {
    const yanit = await api.get<{ data: KesintiOzeti }>(ADRES, { params: parametreler })

    return yanit.data.data
  },
  secenekler: async (): Promise<KesintiRaporuSecenekleri> => {
    const yanit = await api.get<{ data: KesintiRaporuSecenekleri }>(`${ADRES}/secenekler`)

    return yanit.data.data
  },
  /** Ekrandaki rapor, tek sayfa .xlsx */
  excel: async (parametreler: KesintiRaporuParametreleri): Promise<void> => {
    const yanit = await api
      .get<Blob>(`${ADRES}/excel`, { params: parametreler, responseType: 'blob' })
      .catch(blobHatasiniCoz)

    dosyayiIndir(yanit.data, 'kesinti-ozeti.xlsx')
  },
}
