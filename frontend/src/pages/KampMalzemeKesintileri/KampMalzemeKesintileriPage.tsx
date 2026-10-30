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
  api: kampMalzemeApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    urun_tanimi: { tur: 'metin', sinir: 200, zorunlu: true },
    miktar: { tur: 'sayi', basamak: 2 },
    birim: { tur: 'metin', sinir: 20 },
  },
  liste: [
    'tarih',
    'kesinti_turu',
    'taseron',
    'yansitma_turu',
    'proje',
    'mahal',
    'kaynak_firma',
    'urun_tanimi',
    'birim_fiyat',
    'miktar',
    'birim',
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
    'urun_tanimi',
    'birim_fiyat',
    'miktar',
    'birim',
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
        'tarih',
        'mahal',
        { alan: 'kaynak_firma', genis: true },
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.malzeme',
      alanlar: [{ alan: 'urun_tanimi', genis: true }, 'birim'],
    },
    {
      baslik: 'kesinti.icmal.bolum.tutarlar',
      alanlar: [
        'birim_fiyat',
        'miktar',
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
