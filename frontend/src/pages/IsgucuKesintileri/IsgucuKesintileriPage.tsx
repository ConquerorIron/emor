import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import {
  isgucuApi,
  MESAI_TURLERI,
  VASIFLAR,
  type IsgucuKesintisi,
} from '@/features/kesinti/isgucuApi'

/** Özet sekmelerinde toplanan kolonlar (her sekmede aynı) */
const PIVOT_TOPLAMLARI = [
  'miktar_saat',
  'toplam_tutar',
  'iptal_edilen_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
]

/** İşgücü Kesintileri (Excel: "İşgücü-MFK Kesintileri") — kolonlar backend IsgucuIceAktarimi ile aynı */
const ISGUCU_ICMALI: IcmalTanimi<IsgucuKesintisi> = {
  anahtar: 'isgucu',
  izin: 'isgucu_kesintileri',
  icmal: 'isgucu',
  api: isgucuApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_fis_no: { tur: 'metin', sinir: 50 },
    adi_soyadi: { tur: 'metin', sinir: 200, zorunlu: true },
    vasif: { tur: 'secenek', degerler: VASIFLAR },
    mesai_turu: { tur: 'secenek', degerler: MESAI_TURLERI },
    tanim: { tur: 'metin', sinir: 200 },
    gorev: { tur: 'metin', sinir: 200 },
    departman: { tur: 'metin', sinir: 200 },
    aktivite: { tur: 'metin', sinir: 200 },
    miktar_saat: { tur: 'sayi', basamak: 2 },
    miktar_yevmiye: { tur: 'sayi', basamak: 2 },
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
    'adi_soyadi',
    'vasif',
    'mesai_turu',
    'tanim',
    'gorev',
    'departman',
    'aktivite',
    'miktar_saat',
    'miktar_yevmiye',
    'birim_fiyat',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  // Özet sekmeleri (kullanıcı isteği 2026-10-30; backend IsgucuKesintisiSorgusu::PIVOTLAR)
  pivotlar: [
    { anahtar: 'taseron', gruplar: ['taseron', 'proje', 'para_birimi'] },
    { anahtar: 'yansitma_turu', gruplar: ['yansitma_turu', 'proje', 'para_birimi'] },
    { anahtar: 'proje', gruplar: ['proje', 'para_birimi'] },
    { anahtar: 'vasif', gruplar: ['vasif', 'proje', 'para_birimi'] },
    { anahtar: 'mesai_turu', gruplar: ['mesai_turu', 'proje', 'para_birimi'] },
    { anahtar: 'tarih', gruplar: ['yil', 'ay', 'proje', 'para_birimi'] },
  ].map((pivot) => ({ ...pivot, toplamlar: PIVOT_TOPLAMLARI })),
  form: [
    {
      baslik: 'kesinti.icmal.bolum.kesinti',
      alanlar: ['kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih'],
    },
    {
      baslik: 'kesinti.icmal.bolum.personel',
      alanlar: [
        { alan: 'adi_soyadi', genis: true },
        'vasif',
        'mesai_turu',
        'kaynak_firma',
        'kaynak_fis_no',
        'mahal',
      ],
    },
    { baslik: 'kesinti.icmal.bolum.is', alanlar: ['tanim', 'gorev', 'departman', 'aktivite'] },
    {
      baslik: 'kesinti.icmal.bolum.tutarlar',
      alanlar: [
        'miktar_saat',
        'miktar_yevmiye',
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
  satirAdi: (satir) => satir.adi_soyadi,
}

export function IsgucuKesintileriPage() {
  return <IcmalSayfasi tanim={ISGUCU_ICMALI} />
}
