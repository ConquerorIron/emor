import { api } from '@/api/client'

import { yedeklemeSayfaBoyutu } from './bicim'
import { ISTEK_ANAHTARI_BASLIGI } from './useIstekAnahtarlari'

export type YedekKaynagi = 'elle' | 'plan' | 'yayin_oncesi' | 'geri_yukleme_oncesi'
export type YedekDurumu = 'kuyrukta' | 'calisiyor' | 'tamam' | 'basarisiz' | 'silindi'
export type DogrulamaDurumu = 'dogrulanmadi' | 'ozet_tamam' | 'sinama_gecti' | 'basarisiz'
export type KopyaDurumu = 'bekliyor' | 'kopyalaniyor' | 'tamam' | 'basarisiz' | 'silindi'

export const YEDEK_KAYNAKLARI: YedekKaynagi[] = [
  'elle',
  'plan',
  'yayin_oncesi',
  'geri_yukleme_oncesi',
]
export const DOGRULAMA_DURUMLARI: DogrulamaDurumu[] = [
  'dogrulanmadi',
  'ozet_tamam',
  'sinama_gecti',
  'basarisiz',
]

export interface YedekKopyasi {
  hedef_id: number
  hedef_ad: string | null
  hedef_tur: string | null
  durum: KopyaDurumu
  sifreli: boolean
  /** `tamam` aktarımın doğrulandığını söyler; bu, kurtarma paketiyle boş sunucuda da açılabildiğini (şifresizde tamam ile aynı) */
  kurtarma_hazir: boolean
  tamamlanma_zamani: string | null
  hata_kodu: string | null
}

/** Yedekler listesinin satırı (backend YedekResource); dosya yolu ve serbest hata metni dönmez */
export interface Yedek {
  kimlik: string
  dosya_adi: string
  kaynak: YedekKaynagi
  durum: YedekDurumu
  dogrulama_durumu: DogrulamaDurumu
  /** false: manifest imzası yok/geçersiz — "Güvenilmeyen"; geri yüklenemez */
  imzali: boolean
  /** Eski yedeklerden içe alındı (BY-013) */
  ice_alindi: boolean
  /** İmzalı ve tamamlanmış (doğrulama bununla açılır) */
  geri_yuklenebilir: boolean
  /** Ekrandan geri yüklenebilecek kaynak var mı: yerel dosya ya da şifresiz uzak kopya */
  webden_geri_yuklenebilir: boolean
  /** Ekrandan geri yüklenemiyorsa nedeni (hata kodu; ör. yalnız şifreli kopya: GERI_YUKLEME_OZEL_ANAHTAR_GEREKLI) */
  geri_yukleme_engeli: string | null
  /** Bayt */
  boyut: number | null
  alinma_zamani: string | null
  sabit: boolean
  aciklama: string | null
  alan_kullanici: string | null
  hata_kodu: string | null
  yerel_dosya_var: boolean
  kopyalar: YedekKopyasi[]
}

export interface SayfaMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface YedekListesi {
  data: Yedek[]
  meta: SayfaMeta
}

/** Üçlü süzgeç: '' hepsi, '1' yalnız işaretliler, '0' işaretsizler */
export type UcluSecim = '' | '1' | '0'

export interface YedekFiltresi {
  kaynak: YedekKaynagi | ''
  dogrulama: DogrulamaDurumu | ''
  sabit: UcluSecim
  guvenilmeyen: UcluSecim
  silinenler: boolean
}

export type YedekSiralamaAnahtari = 'alinma_zamani' | 'boyut'

export interface YedekSiralamasi {
  anahtar: YedekSiralamaAnahtari
  yon: 'asc' | 'desc'
}

export interface YedekOzeti {
  son_yerel: string | null
  son_uzak: string | null
  son_sinama: string | null
  sessizlik_saat: number | null
  siradaki: string | null
  toplam_adet: number
  toplam_boyut: number
  bos_alan: number | null
  toplam_alan: number | null
  /** Üstüne geri yükleme onayında aynen yazılacak canlı veritabanı adı (BY-030) */
  canli_veritabani?: string | null
}

/** 202 yanıtı: iş çalıştırıcıya bırakıldı; durum `istekDurumuGetir` ile yoklanır */
export interface IstekKabulu {
  istek_kimligi: string
  durum: 'kuyrukta'
}

export interface IstekDurumu {
  kimlik: string
  durum: 'kuyrukta' | 'calisiyor' | 'tamam' | 'basarisiz'
  kod?: string | null
  zaman?: string | null
  yedek_kimligi?: string | null
  /** kopya_geri_yukle */
  geri_yukleme_kimligi?: string | null
  hedef_veritabani?: string | null
  /** dogrula */
  dogrulama_durumu?: DogrulamaDurumu | null
  /** sil */
  yedek_durumu?: YedekDurumu | null
  /** tara: DizinKurucu sayaçları (eklenen, guvenilmeyen, …) */
  tarama?: Record<string, number> | null
}

const KOK = '/api/v1/ayarlar/yedekler'

export async function yedekleriGetir(
  filtre: YedekFiltresi,
  siralama: YedekSiralamasi,
  sayfa: number,
): Promise<YedekListesi> {
  const yanit = await api.get<YedekListesi>(KOK, {
    params: {
      ...(filtre.kaynak !== '' ? { kaynak: filtre.kaynak } : {}),
      ...(filtre.dogrulama !== '' ? { dogrulama: filtre.dogrulama } : {}),
      ...(filtre.sabit !== '' ? { sabit: filtre.sabit } : {}),
      ...(filtre.guvenilmeyen !== '' ? { guvenilmeyen: filtre.guvenilmeyen } : {}),
      ...(filtre.silinenler ? { silinenler: 1 } : {}),
      siralama: siralama.anahtar,
      yon: siralama.yon,
      page: sayfa,
      // Ortak tercih 200 / "Hepsi" olabilir; bu uç 10–100 kabul eder
      sayfa_boyutu: yedeklemeSayfaBoyutu(),
    },
  })

  return yanit.data
}

export async function yedekOzetiGetir(): Promise<YedekOzeti> {
  const yanit = await api.get<{ data: YedekOzeti }>(`${KOK}/ozet`)

  return yanit.data.data
}

/** 404 ISTEK_BULUNAMADI: istek dosyası yok (süresi geçti / temizlendi) */
export async function istekDurumuGetir(kimlik: string): Promise<IstekDurumu> {
  const yanit = await api.get<{ data: IstekDurumu }>(`${KOK}/istekler/${kimlik}`)

  return yanit.data.data
}

/**
 * İşi çalıştırıcıya bırakır. `istekAnahtari`: aynı eylemin yeniden denemesinde
 * aynı (useIstekAnahtarlari) — sunucu ikinci iş bırakmaz, mevcut kabulü döner.
 */
export async function istekBirak(
  yontem: 'post' | 'put' | 'delete',
  adres: string,
  istekAnahtari: string,
  govde?: unknown,
): Promise<IstekKabulu> {
  const ayar = { headers: { [ISTEK_ANAHTARI_BASLIGI]: istekAnahtari } }
  const yanit =
    yontem === 'delete'
      ? await api.delete<{ data: IstekKabulu }>(adres, ayar)
      : await api[yontem]<{ data: IstekKabulu }>(adres, govde, ayar)

  return yanit.data.data
}

/** "Şimdi Yedek Al" */
export function yedekAl(aciklama: string | null, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('post', KOK, istekAnahtari, { aciklama })
}

/** "Klasörü Tara": dizini manifestlerden yeniden kurar */
export function klasoruTara(istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('post', `${KOK}/tara`, istekAnahtari)
}

/** Sınama geri yüklemesi; yalnız geri yüklenebilir yedekte (409 YEDEK_DOGRULANAMAZ) */
export function yedegiDogrula(kimlik: string, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('post', `${KOK}/${kimlik}/dogrula`, istekAnahtari)
}

export function aciklamaKaydet(
  kimlik: string,
  aciklama: string | null,
  istekAnahtari: string,
): Promise<IstekKabulu> {
  return istekBirak('put', `${KOK}/${kimlik}/aciklama`, istekAnahtari, { aciklama })
}

export function yedegiSabitle(kimlik: string, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('post', `${KOK}/${kimlik}/sabit`, istekAnahtari)
}

export function sabitlemeyiKaldir(kimlik: string, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('delete', `${KOK}/${kimlik}/sabit`, istekAnahtari)
}

/** 409 YEDEK_SABIT / YEDEK_KULLANIMDA / YEDEK_SILINMIS */
export function yedegiSil(kimlik: string, istekAnahtari: string): Promise<IstekKabulu> {
  return istekBirak('delete', `${KOK}/${kimlik}`, istekAnahtari)
}
