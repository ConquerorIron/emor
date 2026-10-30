import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { kampApi, type KampKesintisi } from '@/features/kesinti/kampApi'

/** Özet sekmelerinde toplanan kolonlar (her sekmede aynı) */
const PIVOT_TOPLAMLARI = [
  'toplam_oda',
  'toplam_tutar',
  'iptal_edilen_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
]

/**
 * Kamp (oda) Kesintileri (Excel: "Ortak - Kamp Kesintileri" → "Ortak - Kamp"
 * sayfası) — kolonlar backend KampIceAktarimi ile aynı; günlük kolonlar yok
 */
const KAMP_ICMALI: IcmalTanimi<KampKesintisi> = {
  anahtar: 'kamp',
  izin: 'kamp_kesintileri',
  icmal: 'kamp',
  api: kampApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    oda_tipi: { tur: 'metin', sinir: 100, zorunlu: true },
    toplam_oda: { tur: 'sayi', basamak: 2 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'oda_tipi',
    'toplam_oda',
    'birim_fiyat',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (kullanıcı isteği 2026-10-30; backend KampKesintisiSorgusu::PIVOTLAR)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['taseron', 'proje', 'para_birimi'] },
    { anahtar: 'yansitma_turu', gruplar: ['yansitma_turu', 'proje', 'para_birimi'] },
    { anahtar: 'proje', gruplar: ['proje', 'para_birimi'] },
  ].map((pivot) => ({ ...pivot, toplamlar: PIVOT_TOPLAMLARI })),
  form: [
    {
      baslik: 'kesinti.icmal.bolum.kesinti',
      alanlar: [
        'kesinti_turu',
        'taseron',
        'yansitma_turu',
        'proje',
        'mahal',
        'tarih',
        { alan: 'kaynak_firma', genis: true },
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.tutarlar',
      alanlar: [
        { alan: 'oda_tipi', genis: true },
        'toplam_oda',
        'birim_fiyat',
        'para_birimi',
        'toplam_tutar',
        'iptal_edilen_tutar',
        'yansitilan_tutar',
        'kesilen_tutar',
        'kalan_tutar',
      ],
    },
  ],
  satirAdi: (satir) => satir.oda_tipi,
}

export function KampKesintileriPage() {
  return <IcmalSayfasi tanim={KAMP_ICMALI} />
}
