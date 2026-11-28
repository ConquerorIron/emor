import { api } from '@/api/client'
import { yedeklemeSayfaBoyutu } from '@/features/yedekleme/bicim'
import type { SayfaMeta } from '@/features/yedekleme/yedekApi'

/**
 * Bilinen işlem adları (süzgeç seçenekleri). Liste kapalı değildir:
 * çalıştırıcı yenilerini ekler; bilinmeyen ad çevirisiz, olduğu gibi gösterilir.
 */
export const BILINEN_ISLEMLER = [
  'istek',
  'yedek_al',
  'yedek_sil',
  'sabitle',
  'sabit_coz',
  'aciklama',
  'kopya',
  'kopya_sil',
  'saklama_bag',
  'saklama_sil',
  'klasor_tara',
  'sinama_geri_yukleme',
  'geri_yukle_kopya',
  'geri_yukle_ustune',
  'geri_yukle_ustune_onay',
  'geri_yukle_ustune_geri_don',
  'geri_yukle_ustune_ayar_secimi',
  'eski_yedekleri_al',
  'kurtarma_paketi',
  'sifre_coz',
  'hedef_ekle',
  'hedef_guncelle',
  'hedef_sil',
  'hedef_sina',
  'hedef_sunucu_anahtari_sifirla',
  'hedef_google_baglan',
  'hedef_google_kes',
  'hedef_google_iptal',
  'hedef_google_klasor',
  'hedef_google_secici',
  'plan_ekle',
  'plan_guncelle',
  'plan_sil',
  'ayar_guncelle',
  'ayar_kopyasi',
  'sifreleme_anahtari_yukle',
  'sifreleme_anahtari_kaldir',
] as const

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
