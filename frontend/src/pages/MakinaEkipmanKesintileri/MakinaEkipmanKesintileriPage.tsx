import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { makinaEkipmanApi, type MakinaEkipmanKesintisi } from '@/features/kesinti/makinaEkipmanApi'

/**
 * Makina Ekipman Kesintileri (Excel: "Makine-Ekipman Kesintileri") — kolonlar
 * backend MakinaEkipmanIceAktarimi ile aynı; birim fiyat yok
 */
const MAKINA_EKIPMAN_ICMALI: IcmalTanimi<MakinaEkipmanKesintisi> = {
  anahtar: 'makinaEkipman',
  izin: 'makina_ekipman_kesintileri',
  icmal: 'makina_ekipman',
  api: makinaEkipmanApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_fis_no: { tur: 'metin', sinir: 100 },
    makina_tanimi: { tur: 'metin', sinir: 200, zorunlu: true },
    aktivite: { tur: 'metin', sinir: 200 },
    disiplin: { tur: 'metin', sinir: 100 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'kaynak_fis_no',
    'makina_tanimi',
    'aktivite',
    'disiplin',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (kullanıcı isteği 2026-10-30; backend MakinaEkipmanKesintisiSorgusu::PIVOTLAR)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['taseron', 'proje', 'para_birimi'] },
    { anahtar: 'yansitma_turu', gruplar: ['yansitma_turu', 'proje', 'para_birimi'] },
    { anahtar: 'proje', gruplar: ['proje', 'para_birimi'] },
    { anahtar: 'makina', gruplar: ['makina_tanimi', 'proje', 'para_birimi'] },
    { anahtar: 'disiplin', gruplar: ['disiplin', 'proje', 'para_birimi'] },
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
        'kaynak_fis_no',
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.makina',
      alanlar: [
        { alan: 'makina_tanimi', genis: true },
        { alan: 'aktivite', genis: true },
        'disiplin',
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
  satirAdi: (satir) => satir.makina_tanimi,
}

export function MakinaEkipmanKesintileriPage() {
  return <IcmalSayfasi tanim={MAKINA_EKIPMAN_ICMALI} />
}
