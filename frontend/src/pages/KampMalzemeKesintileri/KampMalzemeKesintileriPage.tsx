import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { kampMalzemeApi, type KampMalzemeKesintisi } from '@/features/kesinti/kampMalzemeApi'

/**
 * Kamp Malzeme Kesintileri (Excel: "Ortak - Kamp Kesintileri" → "Ortak - Kamp
 * Malzeme" sayfası) — kolonlar backend KampMalzemeIceAktarimi ile aynı
 */
const KAMP_MALZEME_ICMALI: IcmalTanimi<KampMalzemeKesintisi> = {
  anahtar: 'kampMalzeme',
  izin: 'kamp_malzeme_kesintileri',
  icmal: 'kamp_malzeme',
  api: kampMalzemeApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    urun_tanimi: { tur: 'metin', sinir: 200, zorunlu: true },
    miktar: { tur: 'sayi', basamak: 2 },
    birim: { tur: 'metin', sinir: 20 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'urun_tanimi',
    'miktar',
    'birim',
    'birim_fiyat',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (kullanıcı isteği 2026-10-30; backend KampMalzemeKesintisiSorgusu::PIVOTLAR)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['taseron', 'proje', 'para_birimi'] },
    { anahtar: 'yansitma_turu', gruplar: ['yansitma_turu', 'proje', 'para_birimi'] },
    { anahtar: 'proje', gruplar: ['proje', 'para_birimi'] },
    { anahtar: 'tarih', gruplar: ['yil', 'ay', 'proje', 'para_birimi'] },
  ].map((pivot) => ({
    ...pivot,
    toplamlar: [
      'toplam_tutar',
      'iptal_edilen_tutar',
      'yansitilan_tutar',
      'kesilen_tutar',
      'kalan_tutar',
    ],
  })),
  form: [
    {
      baslik: 'kesinti.icmal.bolum.kesinti',
      alanlar: [
        'kesinti_turu',
        'taseron',
        'yansitma_turu',
        'proje',
        'tarih',
        'mahal',
        { alan: 'kaynak_firma', genis: true },
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.malzeme',
      alanlar: [{ alan: 'urun_tanimi', genis: true }, 'miktar', 'birim'],
    },
    {
      baslik: 'kesinti.icmal.bolum.tutarlar',
      alanlar: [
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
  satirAdi: (satir) => satir.urun_tanimi,
}

export function KampMalzemeKesintileriPage() {
  return <IcmalSayfasi tanim={KAMP_MALZEME_ICMALI} />
}
