import { api } from '@/api/client'
import { yedeklemeSayfaBoyutu } from '@/features/yedekleme/bicim'
import type { SayfaMeta } from '@/features/yedekleme/yedekApi'

export interface IslemKaydi {
  id: number
  zaman: string
  /** null: sistem / komut satırı */
  kullanici: string | null
  islem: string
  sonuc: 'basarili' | 'basarisiz'
  sure_ms: number | null
  yedek: { kimlik: string; dosya_adi: string } | null
  ayrinti: Record<string, unknown>
  hata_mesaji: string | null
}

export interface IslemKaydiFiltresi {
  baslangic: string
  bitis: string
  kullanici_id: string
  islem: string
  sonuc: '' | 'basarili' | 'basarisiz'
}

/** İşlem günlüğü (salt okunur; ekleme / değiştirme / silme ucu yoktur) */
export async function islemKayitlariniGetir(
  filtre: IslemKaydiFiltresi,
  sayfa: number,
): Promise<{ data: IslemKaydi[]; meta: SayfaMeta }> {
  const yanit = await api.get<{ data: IslemKaydi[]; meta: SayfaMeta }>(
    '/api/v1/ayarlar/yedek-islem-kayitlari',
    {
      params: {
        ...Object.fromEntries(Object.entries(filtre).filter(([, deger]) => deger !== '')),
        page: sayfa,
        // Ortak tercih 200 / "Hepsi" olabilir; bu uç 10–100 kabul eder
        sayfa_boyutu: yedeklemeSayfaBoyutu(),
      },
    },
  )

  return yanit.data
}
