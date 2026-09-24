import { api } from '@/api/client'

export interface Rol {
  id: number
  ad: string
  aciklama: string | null
  izinler: string[]
  kullanici_sayisi: number
}

export interface RolGovdesi {
  ad: string
  aciklama: string | null
  izinler: string[]
}

/**
 * Kullanıcılar ekranının satırı: ERP'deki kullanıcı + uygulamadaki tanımı.
 * `id` null ise kişi henüz uygulamaya tanımlanmamıştır (giriş yapamaz).
 */
export interface YonetilenKullanici {
  id: number | null
  erp_kullanici_id: number | null
  ad: string
  kullanici_adi: string
  kaynak: 'lokal' | 'erp'
  sistem_yoneticisi: boolean
  /** Uygulamaya giriş izni */
  aktif_mi: boolean
  rol_idleri: number[]
  /** Yedek (lokal) admin ya da oturumdaki kişinin kendisi */
  pasif_yapilamaz: boolean
  /** Uygulamada tanımlı ama ERP'de artık bulunmuyor */
  erpde_yok: boolean
}

export interface KullaniciListesi {
  kullanicilar: YonetilenKullanici[]
  /** ERP okunamadı: yalnız uygulamada tanımlı kullanıcılar listelendi */
  erpOkunamadi: boolean
}

/** Ad ve şifre ERP'den gelir; yalnız giriş izni ve roller değişir. */
export interface KullaniciGuncelleGovdesi {
  aktif_mi?: boolean
  rol_idleri?: number[]
}

export interface ErpKullaniciTanimlaGovdesi {
  erp_kullanici_id: number
  aktif_mi: boolean
  rol_idleri: number[]
}

/**
 * Bir ekranın izinleri (sıra = sol menü sırası). Güncelleme görüntülemeyi
 * gerektirir; ek izinler (ör. `efatura.pdf`) de ekranın görüntüleme iznine
 * bağlıdır — backend kayıtta bu kuralı uygular.
 */
export interface EkranIzni {
  /** Ekran adı; etiketi i18n `yetki.ekran.*` */
  ekran: string
  goruntule: string
  /** Salt görüntülenen ekranlarda null */
  guncelle: string | null
  /** Ekrana özgü ek izinler; etiketleri i18n `yetki.izin.*` */
  ekler: string[]
}

/** Ekran bazlı izin kataloğu. */
export async function izinleriGetir(): Promise<EkranIzni[]> {
  const yanit = await api.get<{ data: EkranIzni[] }>('/api/v1/ayarlar/izinler')

  return yanit.data.data
}

export async function rolleriGetir(): Promise<Rol[]> {
  const yanit = await api.get<{ data: Rol[] }>('/api/v1/ayarlar/roller')

  return yanit.data.data
}

export async function rolKaydet(id: number | null, govde: RolGovdesi): Promise<Rol> {
  const yanit =
    id === null
      ? await api.post<{ data: Rol }>('/api/v1/ayarlar/roller', govde)
      : await api.put<{ data: Rol }>(`/api/v1/ayarlar/roller/${id}`, govde)

  return yanit.data.data
}

export async function rolSil(id: number): Promise<void> {
  await api.delete(`/api/v1/ayarlar/roller/${id}`)
}

export async function kullanicilariGetir(): Promise<KullaniciListesi> {
  const yanit = await api.get<{ data: YonetilenKullanici[]; meta: { erp_okunamadi: boolean } }>(
    '/api/v1/ayarlar/kullanicilar',
  )

  return { kullanicilar: yanit.data.data, erpOkunamadi: yanit.data.meta.erp_okunamadi }
}

/** ERP kullanıcısını uygulamaya tanımlar (giriş izni + roller). */
export async function erpKullanicisiTanimla(
  govde: ErpKullaniciTanimlaGovdesi,
): Promise<YonetilenKullanici> {
  const yanit = await api.post<{ data: YonetilenKullanici }>('/api/v1/ayarlar/kullanicilar', govde)

  return yanit.data.data
}

export async function kullaniciGuncelle(
  id: number,
  govde: KullaniciGuncelleGovdesi,
): Promise<YonetilenKullanici> {
  const yanit = await api.put<{ data: YonetilenKullanici }>(
    `/api/v1/ayarlar/kullanicilar/${id}`,
    govde,
  )

  return yanit.data.data
}

/** i18next anahtar ayırıcısı nokta olduğu için izin kodu `_` ile yazılır. */
export function izinEtiketAnahtari(izin: string): string {
  return `yetki.izin.${izin.replaceAll('.', '_')}`
}
