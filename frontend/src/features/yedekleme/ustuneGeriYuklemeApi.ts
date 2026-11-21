import { api } from '@/api/client'

import { ISTEK_ANAHTARI_BASLIGI } from './useIstekAnahtarlari'

/** 13 ileri adım (backend UstuneGeriYuklemeAdimi) — ekranın adım listesi */
export type UstuneDurum =
  | 'kuyrukta'
  | 'calisiyor'
  | 'onay_bekliyor'
  | 'hata'
  | 'onaylandi'
  | 'geri_donuldu'
  | 'basarisiz'
  | 'bilinmiyor'

/** İşlemin bittiği durumlar: anahtar artık gerekmez, yoklama durur */
export const BITMIS_DURUMLAR: readonly UstuneDurum[] = ['onaylandi', 'geri_donuldu', 'basarisiz']

export interface Taraf<T> {
  /** Bugünkü veri (yer değiştirmeden hemen önce canlıdan alındı) */
  onceki: T | null
  /** Yedeğin verisi — şu an canlıda */
  geri_yuklenen: T
  farkli: boolean
}

export interface KullaniciOzeti {
  ad: string
  aktif: boolean
  sistem_yoneticisi: boolean
  roller: string[]
}

/** Onayda ayrı ayrı seçilen ayar grupları (backend AyarKopyasi::GRUPLAR) */
export type AyarGrubu = 'mail' | 'yedek_ayarlari' | 'planlar' | 'hedefler'

/** guncel = ayarların dosya kopyası (bugün), geri_yuklenen = yedekteki */
export type AyarSecimi = 'guncel' | 'geri_yuklenen'

export interface OnayBilgisi {
  /** "Önceki" yan okunamadı: farklar gösterilemez */
  onceki_bilinmiyor: boolean
  aktif_erp_ortami: Taraf<string | null>
  entegratorler: Taraf<string[]>
  alarm_kurallari: Taraf<string[]>
  ayar_farklari: {
    bolum: string
    ad: string | null
    degisiklik: 'degisti' | 'geri_yuklenende_yok' | 'yalniz_geri_yuklenende'
    alanlar: string[]
  }[]
  /** Farkı olan her grup için onayda seçim zorunlu (varsayılan yok); eski kayıtlarda yok */
  ayar_gruplari?: { grup: AyarGrubu; farkli: boolean }[]
  /** Ayarların dosya kopyası okunamadı: "güncel" seçilemez */
  ayar_kopyasi_yok?: boolean
  kullanici_farklari: {
    kullanici_adi: string
    ad: string
    degisiklik: string
    alanlar: ('aktif' | 'sistem_yoneticisi' | 'roller')[]
    onceki: KullaniciOzeti | null
    geri_yuklenen: KullaniciOzeti | null
  }[]
  rol_farklari: {
    rol: string
    degisiklik: string
    geri_gelen_izinler: string[]
    kaybolan_izinler: string[]
  }[]
  /** Yedekten sonra yapılmış, geri yüklenen veride olmayan içe aktarım sayısı */
  kaybolacak_yukleme_sayisi: number | null
}

export interface UstuneGorunum {
  islem_kimligi: string
  durum: UstuneDurum
  adim: string | null
  acik: boolean
  yer_degistirildi: boolean
  hata: { kod: string; mesaj: string } | null
  baslangic?: string | null
  son_guncelleme?: string | null
  erisim_anahtari_bitis?: string | null
  yedek_kimligi?: string | null
  hedef_veritabani?: string | null
  onceki_veritabani?: string | null
  adimlar: { adim: string; durum: 'tamam' | 'suruyor' | 'hata' | 'bekliyor' }[]
  yapilabilir: { onayla: boolean; geri_don: boolean }
  onay_bilgisi: OnayBilgisi | null
  /** `?istek=` verildiyse onay / geri dön isteğinin durumu */
  istek?: { durum: string; kod: string | null; mesaj: string | null } | null
}

export interface UstuneBaslatma {
  istek_kimligi: string
  islem_kimligi: string
  /** YALNIZ bu yanıtta döner; ilerleme / onay uçlarının tek yetkisi */
  erisim_anahtari: string
  erisim_anahtari_bitis: string
  durum: 'kuyrukta'
}

/**
 * Üstüne geri yüklemeyi başlatır (oturumla; yalnız sistem yöneticisi).
 * `onayMetni` canlı veritabanının adıyla birebir aynı olmalı (422 aksi hâlde).
 */
export async function ustuneGeriYukle(
  yedekKimligi: string,
  onayMetni: string,
): Promise<UstuneBaslatma> {
  const yanit = await api.post<{ data: UstuneBaslatma }>(
    `/api/v1/ayarlar/yedekler/${yedekKimligi}/ustune-geri-yukle`,
    { onay_metni: onayMetni },
  )

  return yanit.data.data
}

/** İlerleme / karar uçlarının hatası: HTTP durumu (0 = ağ) ve makine okunur kod */
export class UstuneGeriYuklemeHatasi extends Error {
  readonly durum: number
  readonly kod: string | null

  constructor(durum: number, kod: string | null) {
    super(kod ?? `HTTP ${durum}`)
    this.durum = durum
    this.kod = kod
  }
}

const KOK = '/api/v1/yedekleme/ustune-geri-yukleme'

/**
 * İlerleme ve karar uçları OTURUMSUZDUR: işlem sırasında uygulama bakım
 * modundadır, veritabanı yer değiştirir ve oturumlar düşer. Bu yüzden ortak
 * axios örneği kullanılmaz — onun 401 yakalayıcısı oturumu düşürüp girişe
 * yönlendirir; burada 401 "anahtar yok" demektir. Çerez gönderilmez; tek
 * yetki `X-Erisim-Anahtari` başlığıdır.
 */
async function anahtarliIstek<T>(
  yontem: 'GET' | 'POST',
  yol: string,
  anahtar: string,
  istekGovdesi?: Record<string, unknown>,
  istekAnahtari?: string,
): Promise<T> {
  let yanit: Response
  try {
    yanit = await fetch(yol, {
      method: yontem,
      credentials: 'omit',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Erisim-Anahtari': anahtar,
        ...(istekGovdesi === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(istekAnahtari === undefined ? {} : { [ISTEK_ANAHTARI_BASLIGI]: istekAnahtari }),
      },
      ...(istekGovdesi === undefined ? {} : { body: JSON.stringify(istekGovdesi) }),
    })
  } catch {
    throw new UstuneGeriYuklemeHatasi(0, 'AG_HATASI')
  }

  const govde = (await yanit.json().catch(() => null)) as { data?: T; kod?: string } | null
  if (!yanit.ok || govde?.data === undefined) {
    throw new UstuneGeriYuklemeHatasi(yanit.status, govde?.kod ?? null)
  }

  return govde.data
}

export function ustuneDurumGetir(
  islem: string,
  anahtar: string,
  kararIstegi: string | null,
): Promise<UstuneGorunum> {
  const sorgu = kararIstegi === null ? '' : `?istek=${encodeURIComponent(kararIstegi)}`

  return anahtarliIstek('GET', `${KOK}/${islem}/durum${sorgu}`, anahtar)
}

/**
 * "Onayla" / "Geri dön" — 409 GERI_YUKLEME_ISLEM_DURUMU_UYGUN_DEGIL yapılabilir
 * değilse. Onay, farkı olan her ayar grubunun seçimini taşır (422
 * GERI_YUKLEME_AYAR_SECIMI_EKSIK aksi hâlde). `istekAnahtari`: aynı kararın
 * yeniden denemesinde aynı — sunucu ikinci karar isteği bırakmaz (BY-030-R3).
 */
export function ustuneKararVer(
  islem: string,
  anahtar: string,
  istekAnahtari: string,
  karar: 'onayla' | 'geri-don',
  ayarSecimleri?: Partial<Record<AyarGrubu, AyarSecimi>>,
): Promise<{ istek_kimligi: string; durum: 'kuyrukta' }> {
  return anahtarliIstek(
    'POST',
    `${KOK}/${islem}/${karar}`,
    anahtar,
    karar === 'onayla' ? { ayar_secimleri: ayarSecimleri ?? {} } : undefined,
    istekAnahtari,
  )
}
