import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'
import { tetkikApi, type TetkikKesintisi } from '@/features/kesinti/tetkikApi'

/**
 * Tetkik Kesintisi (Excel: "OSGB Kesintileri" → Sağlık Tetkikleri sayfası) —
 * kolonlar backend TetkikIceAktarimi ile aynı; tek tek tetkik kolonları yok
 */
const TETKIK_ICMALI: IcmalTanimi<TetkikKesintisi> = {
  anahtar: 'tetkik',
  izin: 'tetkik_kesintileri',
  icmal: 'tetkik',
  api: tetkikApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_hakedis_no: { tur: 'metin', sinir: 100 },
    adi_soyadi: { tur: 'metin', sinir: 200, zorunlu: true },
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
    'adi_soyadi',
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
        'kaynak_firma',
        'kaynak_hakedis_no',
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.personel',
      alanlar: [{ alan: 'adi_soyadi', genis: true }],
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
  satirAdi: (satir) => satir.adi_soyadi,
}

export function TetkikKesintileriPage() {
  return <IcmalSayfasi tanim={TETKIK_ICMALI} />
}
