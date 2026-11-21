import { api } from '@/api/client'

export interface YedekAyarlari {
  /** Zamanlanmamış yedeklerin saklama süreleri (README §4.3) */
  yayin_oncesi_son_adet: number
  yayin_oncesi_gun: number
  elle_gun: number
  geri_yukleme_oncesi_gun: number
  /** Hedef başına en az tutulan başarılı yedek (en az 1) */
  enaz_adet: number
  enaz_bos_alan_yuzde: number
  bildirim_alicilari: string[]
  /** Yaşam sinyali adresleri (https) */
  sinyal_yerel_adresi: string | null
  sinyal_uzak_adresi: string | null
  sinyal_sinama_adresi: string | null
  /** Sunucu dışı kopyaların açık anahtarı; yoksa sunucu dışına kopya gönderilmez */
  sifreleme: { parmak_izi: string; kullanici: string | null; bitis: string | null } | null
}

export type YedekAyarGovdesi = Omit<YedekAyarlari, 'sifreleme'>

const KOK = '/api/v1/ayarlar/yedek-ayarlari'

export async function yedekAyarlariniGetir(): Promise<YedekAyarlari> {
  const yanit = await api.get<{ data: YedekAyarlari }>(KOK)

  return yanit.data.data
}

export async function yedekAyarlariniKaydet(govde: YedekAyarGovdesi): Promise<YedekAyarlari> {
  const yanit = await api.put<{ data: YedekAyarlari }>(KOK, govde)

  return yanit.data.data
}

/** 422: YEDEK_ANAHTAR_OZEL / YEDEK_ANAHTAR_GECERSIZ / YEDEK_ANAHTAR_SURESI_DOLMUS */
export async function sifrelemeAnahtariYukle(acikAnahtar: string): Promise<YedekAyarlari> {
  const yanit = await api.put<{ data: YedekAyarlari }>(`${KOK}/sifreleme-anahtari`, {
    acik_anahtar: acikAnahtar,
  })

  return yanit.data.data
}

export async function sifrelemeAnahtariniKaldir(): Promise<YedekAyarlari> {
  const yanit = await api.delete<{ data: YedekAyarlari }>(`${KOK}/sifreleme-anahtari`)

  return yanit.data.data
}
