import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { osgbApi, type OsgbKesintisi } from '@/features/kesinti/osgbApi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'

/**
 * OSGB Kesintisi (Excel: "OSGB Kesintileri" → OSGB sayfası) — kolonlar backend
 * OsgbIceAktarimi ile aynı; günlük kişi kolonları yok
 */
const OSGB_ICMALI: IcmalTanimi<OsgbKesintisi> = {
  anahtar: 'osgb',
  izin: 'osgb_kesintileri',
  icmal: 'osgb',
  api: osgbApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    toplam_kisi: { tur: 'sayi', basamak: 2 },
  },
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
    'toplam_kisi',
    'birim_fiyat',
    'para_birimi',
    'toplam_tutar',
    'iptal_edilen_tutar',
    'yansitilan_tutar',
    'kesilen_tutar',
    'kalan_tutar',
  ],
  pivotlar: STANDART_PIVOTLAR,
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
        'toplam_kisi',
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
  // Silme onayında: taşeron kodu
  satirAdi: (satir) => satir.firma.kod,
}

export function OsgbKesintileriPage() {
  return <IcmalSayfasi tanim={OSGB_ICMALI} />
}
