import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { isgCezaApi, type IsgCezaKesintisi } from '@/features/kesinti/isgCezaApi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'

/**
 * İSG Ceza Kesintisi (Excel: "OSGB Kesintileri" → İSG Ceza sayfası) — yalnız
 * ortak kolonlar; backend IsgCezaIceAktarimi ile aynı
 */
const ISG_CEZA_ICMALI: IcmalTanimi<IsgCezaKesintisi> = {
  anahtar: 'isgCeza',
  izin: 'isg_ceza_kesintileri',
  icmal: 'isg_ceza',
  api: isgCezaApi,
  alanlar: ORTAK_ALANLAR,
  kolonlar: [
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'tarih',
    'kaynak_firma',
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

export function IsgCezaKesintileriPage() {
  return <IcmalSayfasi tanim={ISG_CEZA_ICMALI} />
}
