import { api } from '@/api/client'

import type { KopyaDurumu } from './yedekApi'

export type HedefAlanTuru = 'metin' | 'sayi' | 'parola' | 'evet_hayir' | 'uzun_metin'

/** Bağlayıcının form alanı tanımı — form bundan çizilir (türe özgü ekran kodu yok) */
export interface HedefAlanTanimi {
  ad: string
  /** i18n anahtarı (ör. `yedekleme.hedef.alan.sunucu`) */
  etiket: string
  tur: HedefAlanTuru
  zorunlu: boolean
  /** Sır: hiçbir yanıtta dönmez; düzenlemede boş bırakılırsa değişmez */
  gizli: boolean
  varsayilan: string | number | boolean | null
}

export interface HedefTuruTanimi {
  tur: string
  alanlar: HedefAlanTanimi[]
  /** Sunucu dışı: kopya açık anahtarla şifrelenir; anahtar yoksa gönderilmez */
  sunucu_disi: boolean
  /** i18n anahtarı (ör. düz FTP uyarısı) */
  uyari: string | null
  /** Hesap bağlantısı tarayıcıdan yapılır (Google Drive) */
  oauth: boolean
  /** Tarayıcıdan bağlanan türde sunucu yapılandırması tam (Google istemci kimliği, API anahtarı…) */
  oauth_hazir: boolean
}

export type HedefDegeri = string | number | boolean | null

export interface Hedef {
  id: number
  ad: string
  tur: string
  aktif: boolean
  sunucu_disi: boolean
  /** Formun sır olmayan alanları + bağlayıcının durum alanları (sunucu_parmak_izi, durum, eposta, klasor_kimligi, klasor_adi) */
  baglanti: Record<string, HedefDegeri | undefined>
  /** Sır alanlarının kayıtlı olup olmadığı (değerleri asla dönmez) */
  gizli_kayitli: Record<string, boolean>
  plan_sayisi: number
  /** Kopyası duran hedefte değiştirilemeyen konum alanları (sunucu, klasör, kova…); kopya yoksa boş */
  konum_kilitli_alanlar: string[]
  son_kopya: { durum: KopyaDurumu; zaman: string | null; hata_kodu: string | null } | null
}

export interface HedefGovdesi {
  ad: string
  aktif: boolean
  /** Yalnız eklemede (tür sonradan değişmez) */
  tur?: string
  /** Boş sır gönderilmez: kayıtlı değer korunur */
  alanlar: Record<string, HedefDegeri>
}

export type SinamaAdimi = 'baglan' | 'yaz' | 'oku' | 'dogrula' | 'sil'

export interface HedefSinamaSonucu {
  basarili: boolean
  adimlar: {
    adim: SinamaAdimi
    /** null: önceki adım düştüğü için atlandı */
    basarili: boolean | null
    kod: string | null
    mesaj: string | null
  }[]
  kalan_alan: number | null
  /** S3 nesne kilidi (silinmeye karşı koruma); S3 dışında null */
  silinme_korumasi: 'etkin' | 'devre_disi' | 'sorgulanamadi' | null
  /** true: kayıtlı hedefin kendisi sınandı (SFTP sunucu anahtarı ona yazıldı) */
  kaydedildi: boolean
  sunucu_parmak_izi: string | null
}

const KOK = '/api/v1/ayarlar/yedek-hedefleri'

export async function hedefleriGetir(): Promise<Hedef[]> {
  const yanit = await api.get<{ data: Hedef[] }>(KOK)

  return yanit.data.data
}

export async function hedefTurleriGetir(): Promise<HedefTuruTanimi[]> {
  const yanit = await api.get<{ data: HedefTuruTanimi[] }>(`${KOK}/turler`)

  return yanit.data.data
}

/** Güncellemede 409 HEDEF_KONUMU_DEGISMEZ {alanlar}: kopyası duran hedefin konumu değişmez */
export async function hedefKaydet(id: number | null, govde: HedefGovdesi): Promise<Hedef> {
  const yanit =
    id === null
      ? await api.post<{ data: Hedef }>(KOK, govde)
      : await api.put<{ data: Hedef }>(`${KOK}/${id}`, govde)

  return yanit.data.data
}

/** 409 HEDEF_KULLANILIYOR {plan_sayisi, kopya_sayisi} */
export async function hedefSil(id: number): Promise<void> {
  await api.delete(`${KOK}/${id}`)
}

/** Kaydedilmemiş form değerleriyle de sınanır; `id` verilirse boş sırlar kayıtlıya düşer */
export async function hedefSina(govde: {
  id?: number
  tur: string
  alanlar: Record<string, HedefDegeri>
}): Promise<HedefSinamaSonucu> {
  const yanit = await api.post<{ data: HedefSinamaSonucu }>(`${KOK}/sina`, govde)

  return yanit.data.data
}

/** SFTP: kayıtlı sunucu anahtarını unutur; sonraki "Sına" yenisini kaydeder */
export async function sunucuAnahtariniSifirla(id: number): Promise<Hedef> {
  const yanit = await api.post<{ data: Hedef }>(`${KOK}/${id}/sunucu-anahtari-sifirla`)

  return yanit.data.data
}

/** Google yetki adresi: tarayıcı oraya gider, dönüş `/ayarlar/yedek-hedefleri/google-donus` */
export async function googleBaglantiAdresi(id: number): Promise<string> {
  const yanit = await api.post<{ data: { adres: string } }>(`${KOK}/${id}/google/baglan`)

  return yanit.data.data.adres
}

/** Google dönüşündeki kod ve durum değeri (422 GOOGLE_DURUM_GECERSIZ); bağlanan hedef döner */
export async function googleDonusunuTamamla(kod: string, durum: string): Promise<Hedef> {
  const yanit = await api.post<{ data: Hedef }>(`${KOK}/google/donus`, { kod, durum })

  return yanit.data.data
}

/** Klasör seçme penceresi (Google Picker) için: kısa ömürlü erişim anahtarı ve pencere ayarları */
export interface GoogleSeciciBilgisi {
  erisim_anahtari: string
  api_anahtari: string
  proje_numarasi: string
  eposta: string | null
}

/** 409 GOOGLE_SECICI_YAPILANDIRILMADI / HEDEF_YENIDEN_BAGLAN */
export async function googleSeciciBilgisi(id: number): Promise<GoogleSeciciBilgisi> {
  const yanit = await api.post<{ data: GoogleSeciciBilgisi }>(`${KOK}/${id}/google/secici`)

  return yanit.data.data
}

/**
 * Yedek klasörünü kaydeder: seçilen klasör ya da Drive'ın köküne açılacak yeni
 * klasör. Sunucu klasöre deneme dosyası yazıp siler; 422 HEDEF_KLASOR_GECERSIZ /
 * HEDEF_YAZILAMADI, 409 HEDEF_KONUMU_DEGISMEZ / HEDEF_AYNI_DEPO / GOOGLE_HESAP_DEGISTI.
 */
export async function googleKlasorKaydet(
  id: number,
  govde: { klasor_kimligi: string } | { yeni_klasor_adi: string },
): Promise<Hedef> {
  const yanit = await api.post<{ data: Hedef }>(`${KOK}/${id}/google/klasor`, govde)

  return yanit.data.data
}

export async function googleBaglantisiniKes(id: number): Promise<Hedef> {
  const yanit = await api.post<{ data: Hedef }>(`${KOK}/${id}/google/kes`)

  return yanit.data.data
}
