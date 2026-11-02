import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { kartBasimApi, type KartBasimKesintisi } from '@/features/kesinti/kartBasimApi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'

/** Personel Kart Basım Kesintisi — kolonlar backend KartBasimIceAktarimi ile aynı */
const KART_BASIM_ICMALI: IcmalTanimi<KartBasimKesintisi> = {
  anahtar: 'kartBasim',
  izin: 'kart_basim_kesintileri',
  icmal: 'kart_basim',
  api: kartBasimApi,
  alanlar: {
    ...ORTAK_ALANLAR,
    personel_adi_soyadi: { tur: 'metin', sinir: 100, zorunlu: true },
    urun_tanimi: { tur: 'metin', sinir: 100, zorunlu: true },
    kart_basim_nedeni: { tur: 'metin', sinir: 100 },
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
    'personel_adi_soyadi',
    'urun_tanimi',
    'kart_basim_nedeni',
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
        { alan: 'kaynak_firma', genis: true },
      ],
    },
    {
      baslik: 'kesinti.icmal.bolum.personel',
      alanlar: [{ alan: 'personel_adi_soyadi', genis: true }],
    },
    {
      baslik: 'kesinti.icmal.bolum.malzeme',
      alanlar: ['urun_tanimi', 'kart_basim_nedeni', 'malzeme_birim_fiyati', 'miktar', 'birim'],
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
  satirAdi: (satir) => satir.personel_adi_soyadi,
}

export function KartBasimKesintileriPage() {
  return <IcmalSayfasi tanim={KART_BASIM_ICMALI} />
}
