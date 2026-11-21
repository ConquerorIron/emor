import { api } from '@/api/client'

import type { IstekKabulu } from '@/features/yedekleme/yedekApi'

export type SiklikTuru = 'saatlik' | 'gunluk' | 'n_gunluk' | 'haftalik' | 'aylik'

export const SIKLIK_TURLERI: SiklikTuru[] = ['saatlik', 'gunluk', 'n_gunluk', 'haftalik', 'aylik']

/** Sıklık türüne göre yalnız anlamlı alanlar dolu (backend fazlasını atar) */
export interface Siklik {
  aralik?: number
  saat_baslangic?: string
  saat_bitis?: string
  /** 1 = Pazartesi … 7 = Pazar */
  gunler?: number[]
  baslangic_tarihi?: string
  ayin_gunu?: number
}

export interface PlanHedefi {
  hedef_id: number
  ad: string
  tur: string
  sakla_son_adet: number | null
  sakla_gun: number | null
  /** Bu planın bu hedefteki kopyası (varsa hedef plandan çıkarılamaz — F6) */
  kopya_sayisi: number
}

export interface Plan {
  id: number
  ad: string
  aktif: boolean
  siklik_turu: SiklikTuru
  /** SS:DD, İstanbul saati */
  saat: string
  siklik: Siklik
  son_calisma: string | null
  /** Pasif planda null */
  sonraki_calisma: string | null
  ay_sonu_uyarisi: boolean
  hedefler: PlanHedefi[]
}

export interface PlanGovdesi {
  ad: string
  aktif: boolean
  siklik_turu: SiklikTuru
  saat: string
  siklik: Siklik
  hedefler: { hedef_id: number; sakla_son_adet: number | null; sakla_gun: number | null }[]
}

export interface PlanOnizlemesi {
  /** Sıradaki 5 çalışma (ISO) */
  siradakiler: string[]
  ay_sonu_uyarisi: boolean
  cakisan_planlar: { id: number; ad: string; an: string }[]
  silinecekler: {
    tur: 'bag' | 'dosya'
    yedek_id: number
    hedef_id: number | null
    kopya_id: number | null
    plan_id: number | null
    neden: string
    dosya_adi: string | null
    alinma_zamani: string | null
  }[]
}

const KOK = '/api/v1/ayarlar/yedek-planlari'

export async function planlariGetir(): Promise<Plan[]> {
  const yanit = await api.get<{ data: Plan[] }>(KOK)

  return yanit.data.data
}

/** Güncellemede 409 PLAN_HEDEFI_KOPYALI {hedefler: [{hedef_id, ad, kopya_sayisi}]} */
export async function planKaydet(id: number | null, govde: PlanGovdesi): Promise<Plan> {
  const yanit =
    id === null
      ? await api.post<{ data: Plan }>(KOK, govde)
      : await api.put<{ data: Plan }>(`${KOK}/${id}`, govde)

  return yanit.data.data
}

/** 409 PLAN_KOPYALI {kopya_sayisi}: kopyası olan plan silinmez, pasife alınır */
export async function planSil(id: number): Promise<void> {
  await api.delete(`${KOK}/${id}`)
}

export async function planCalistir(id: number): Promise<IstekKabulu> {
  const yanit = await api.post<{ data: IstekKabulu }>(`${KOK}/${id}/calistir`)

  return yanit.data.data
}

/** Kaydetmeden: sıradaki çalışmalar, uyarılar ve bu kuralla silinecekler (hiçbir şey kaydedilmez) */
export async function planOnizle(id: number | null, govde: PlanGovdesi): Promise<PlanOnizlemesi> {
  const yanit = await api.post<{ data: PlanOnizlemesi }>(`${KOK}/onizleme`, {
    ...(id === null ? {} : { id }),
    ...govde,
  })

  return yanit.data.data
}
