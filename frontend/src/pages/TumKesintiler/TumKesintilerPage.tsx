import { IcmalSayfasi } from '@/features/kesinti/IcmalSayfasi'
import { ORTAK_ALANLAR, type IcmalTanimi } from '@/features/kesinti/icmalTanimi'
import { STANDART_PIVOTLAR } from '@/features/kesinti/ortakPivotlar'
import { tumKesintilerApi, type TumKesinti } from '@/features/kesinti/tumKesintilerApi'

/**
 * Tüm Kesintiler (kullanıcı isteği 2026-10-31): bütün kesinti ekranlarının
 * kayıtları, ortak kolonlarla tek listede. Yalnız rapor — veri ilgili kesinti
 * ekranından girilir; backend TumKesintilerSorgusu.
 */
const TUM_KESINTILER: IcmalTanimi<TumKesinti> = {
  anahtar: 'tumu',
  izin: 'tum_kesintiler',
  rapor: true,
  satirAnahtari: (satir) => `${satir.icmal}-${satir.id}`,
  api: tumKesintilerApi,
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
  form: [],
  satirAdi: (satir) => satir.firma.kod,
}

export function TumKesintilerPage() {
  return <IcmalSayfasi tanim={TUM_KESINTILER} />
}
