import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { yemekApi, type YemekKesintisi } from '@/features/kesinti/yemekApi'

/**
 * Yemek Kesintisi (Excel: "Yemek Kesintileri") — kolonlar backend
 * YemekIceAktarimi ile aynı; öğün kolonları yok
 */
const YEMEK_ICMALI: IcmalTanimi<YemekKesintisi> = {
  anahtar: 'yemek',
  izin: 'yemek_kesintileri',
  icmal: 'yemek',
  api: yemekApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_hakedis_no: { tur: 'metin', sinir: 100 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'kaynak_hakedis_no',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (diğer icmallerdeki ortak düzen; backend YemekKesintisiSorgusu::PIVOTLAR)
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
        'mahal',
        'tarih',
        'kaynak_firma',
        'kaynak_hakedis_no',
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.tutarlar',
      alanlar: [
        'para_birimi',
        'toplam_tutar',
        'iptal_edilen_tutar',
        'yansitilan_tutar',
        'kesilen_tutar',
        'kalan_tutar',
      ],
    },
  ],
  // Silme onayında: kaynak firma, yoksa kesinti türü
  satirAdi: (satir) => satir.kaynak_firma ?? satir.kesinti_turu.kod,
}

export function YemekKesintileriPage() {
  return <IcmalSayfasi tanim={YEMEK_ICMALI} />
}
