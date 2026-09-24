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

export interface YonetilenKullanici {
  id: number
  ad: string
  kullanici_adi: string
  email: string | null
  kaynak: 'lokal' | 'erp'
  sistem_yoneticisi: boolean
  aktif_mi: boolean
  rol_idleri: number[]
  /** Yedek (lokal) admin ya da oturumdaki kişinin kendisi */
  pasif_yapilamaz: boolean
}

export interface LokalKullaniciGovdesi {
  kullanici_adi: string
  ad: string
  email: string | null
  sifre: string
  rol_idleri: number[]
}

/** ERP kullanıcısında ad/e-posta/şifre gönderilmez (ERP'den gelir). */
export interface KullaniciGuncelleGovdesi {
  ad?: string
  email?: string | null
  sifre?: string
  aktif_mi?: boolean
  rol_idleri?: number[]
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

export async function kullanicilariGetir(): Promise<YonetilenKullanici[]> {
  const yanit = await api.get<{ data: YonetilenKullanici[] }>('/api/v1/ayarlar/kullanicilar')

  return yanit.data.data
}

export async function lokalKullaniciOlustur(
  govde: LokalKullaniciGovdesi,
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
