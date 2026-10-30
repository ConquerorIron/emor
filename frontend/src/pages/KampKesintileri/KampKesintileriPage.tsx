import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { kampApi, type KampKesintisi } from '@/features/kesinti/kampApi'

/**
 * Kamp (oda) Kesintileri (Excel: "Ortak - Kamp Kesintileri" → "Ortak - Kamp"
 * sayfası) — kolonlar backend KampIceAktarimi ile aynı; günlük kolonlar yok
 */
const KAMP_ICMALI: IcmalTanimi<KampKesintisi> = {
  anahtar: 'kamp',
  izin: 'kamp_kesintileri',
  api: kampApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    oda_tipi: { tur: 'metin', sinir: 100, zorunlu: true },
    toplam_oda: { tur: 'sayi', basamak: 2 },
  },
  liste: [
    'tarih',
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
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
  onizleme: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'tarih',
    'oda_tipi',
    'toplam_oda',
    'birim_fiyat',
    'para_birimi',
    'toplam_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
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
