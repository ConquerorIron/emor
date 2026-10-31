import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { tutanakApi, type TutanakKesintisi } from '@/features/kesinti/tutanakApi'

/**
 * Tutanak Kesintisi (Excel: "İlave Tutanak Kesintileri") — kolonlar backend
 * TutanakIceAktarimi ile aynı; birim fiyat yok
 */
const TUTANAK_ICMALI: IcmalTanimi<TutanakKesintisi> = {
  anahtar: 'tutanak',
  izin: 'tutanak_kesintileri',
  icmal: 'tutanak',
  api: tutanakApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_tutanak_no: { tur: 'metin', sinir: 100 },
    kaynak_sozlesme: { tur: 'metin', sinir: 100 },
    kaynak_hakedis_no: { tur: 'metin', sinir: 100 },
    aktivite: { tur: 'metin', sinir: 200 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'kaynak_tutanak_no',
    'kaynak_sozlesme',
    'kaynak_hakedis_no',
    'aktivite',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (diğer icmallerdeki ortak düzen; backend TutanakKesintisiSorgusu::PIVOTLAR)
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
        { alan: 'kaynak_firma', genis: true },
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.tutanak',
      alanlar: [
        'kaynak_tutanak_no',
        'kaynak_sozlesme',
        'kaynak_hakedis_no',
        { alan: 'aktivite', genis: true },
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
  // Silme onayında: tutanak numarası, yoksa kesinti türü
  satirAdi: (satir) => satir.kaynak_tutanak_no ?? satir.kesinti_turu.kod,
}

export function TutanakKesintileriPage() {
  return <IcmalSayfasi tanim={TUTANAK_ICMALI} />
}
