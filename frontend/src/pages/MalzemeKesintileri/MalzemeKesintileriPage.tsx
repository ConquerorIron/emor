import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { malzemeApi, type MalzemeKesintisi } from '@/features/kesinti/malzemeApi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'

/**
 * Malzeme Kesintisi (Excel: "TDF, Ambar, Fatura Kesintileri" → "Ambar Malzeme"
 * sayfası) — kolonlar backend MalzemeIceAktarimi ile aynı; KDV kolonları yok
 */
const MALZEME_ICMALI: IcmalTanimi<MalzemeKesintisi> = {
  anahtar: 'malzeme',
  izin: 'malzeme_kesintileri',
  icmal: 'malzeme',
  api: malzemeApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    kaynak_irsaliye_no: { tur: 'metin', sinir: 100 },
    urun_tanimi: { tur: 'metin', sinir: 200, zorunlu: true },
    malzeme_birim_fiyati: { tur: 'sayi', basamak: 4 },
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
    'kaynak_irsaliye_no',
    'urun_tanimi',
    'malzeme_birim_fiyati',
    'miktar',
    'birim',
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
        'kaynak_irsaliye_no',
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.malzeme',
      alanlar: [{ alan: 'urun_tanimi', genis: true }, 'malzeme_birim_fiyati', 'miktar', 'birim'],
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
  satirAdi: (satir) => satir.urun_tanimi,
}

export function MalzemeKesintileriPage() {
  return <IcmalSayfasi tanim={MALZEME_ICMALI} />
}
