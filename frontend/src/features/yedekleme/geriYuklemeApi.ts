import { api } from '@/api/client'

import { istekBirak, type IstekKabulu } from './yedekApi'

export type GeriYuklemeTuru = 'kopya' | 'ustune'
export type GeriYuklemeDurumu =
  'kuyrukta' | 'calisiyor' | 'onay_bekliyor' | 'tamam' | 'basarisiz' | 'geri_alindi'

export interface GeriYukleme {
  kimlik: string
  tur: GeriYuklemeTuru
  durum: GeriYuklemeDurumu
  yedek: { kimlik: string; dosya_adi: string; alinma_zamani: string | null } | null
  hedef_veritabani: string
  /** Üstüne geri yüklemede eski veritabanının yeni adı (erp_onceki_*) */
  onceki_veritabani: string | null
  isteyen: string | null
  adimlar: { adim: string; durum: 'basladi' | 'tamam' | 'basarisiz'; zaman: string | null }[]
  hata_kodu: string | null
  basladi: string | null
  bitti: string | null
  /** Kopya veritabanının silindiği an */
  veritabani_silinme_zamani: string | null
  /** Tamamlanmış, henüz silinmemiş farklı ad kopyası */
  bekleyen_kopya: boolean
  /** Otomatik silinme anı (bekleme süresi sonu) */
  silinecegi_zaman: string | null
  /** Bayt; okunamazsa null */
  boyut: number | null
}

/**
 * Ekrandan silinebilecek ad: yalnız bu sistemin açtığı farklı ad kopyası
 * (backend VeritabaniAdi::KOPYA_DESENI ile aynı). `erp` ve kalıp dışı adlar
 * için silme düğmesi çizilmez; backend de reddeder (409 GERI_YUKLEME_KOPYA_YOK).
 */
export const KOPYA_ADI = /^erp_geri_[0-9]{8}_[0-9]{6}(?:_[a-z0-9]{1,16})?$/

/** Kopya adının isteğe bağlı eki (backend VeritabaniAdi::EK_DESENI) */
export const KOPYA_EKI = /^[a-z0-9]{1,16}$/

/** Son 200 geri yükleme (yeniden eskiye) */
export async function geriYuklemeleriGetir(): Promise<GeriYukleme[]> {
  const yanit = await api.get<{ data: GeriYukleme[] }>('/api/v1/ayarlar/geri-yuklemeler')

  return yanit.data.data
}

/** "Farklı adla geri yükle": canlı veritabanına dokunmaz; ad sistem üretir (409 GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ) */
export function kopyaGeriYukle(
  yedekKimligi: string,
  ek: string | null,
  istekAnahtari: string,
): Promise<IstekKabulu> {
  return istekBirak(
    'post',
    `/api/v1/ayarlar/yedekler/${yedekKimligi}/kopya-geri-yukle`,
    istekAnahtari,
    { ek },
  )
}

/** Bekleyen kopyayı siler (409 GERI_YUKLEME_KOPYA_YOK); yeniden denemede aynı istek anahtarı */
export function kopyaSil(geriYuklemeKimligi: string, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak(
    'delete',
    `/api/v1/ayarlar/geri-yuklemeler/${geriYuklemeKimligi}/kopya`,
    istekAnahtari,
  )
}
