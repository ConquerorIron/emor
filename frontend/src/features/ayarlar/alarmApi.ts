import { api } from '@/api/client'

export type AlarmTuru = 'senkron_arizasi' | 'gunluk_ozet' | 'erp_okumadi'

export interface AlarmKurali {
  tur: AlarmTuru
  aktif: boolean
  alicilar: string[]
  /** Türe göre: ardisik_hata/gecikme_saat, saat, gun/saat */
  parametreler: Record<string, number | string>
  updated_at: string | null
}

export interface AlarmBildirimi {
  id: number
  kural_turu: AlarmTuru
  ortam: 'test' | 'canli'
  tur: 'acildi' | 'cozuldu' | 'ozet'
  durum: 'bekliyor' | 'gonderildi' | 'basarisiz' | 'atlandi'
  deneme: number
  hata_kodu: string | null
  hata_mesaji: string | null
  gonderildi: string | null
  olusturuldu: string
}

export interface AlarmKurallariYaniti {
  data: AlarmKurali[]
  bildirimler: AlarmBildirimi[]
}

export async function alarmKurallariniGetir(): Promise<AlarmKurallariYaniti> {
  const yanit = await api.get<AlarmKurallariYaniti>('/api/v1/ayarlar/alarm-kurallari')

  return yanit.data
}

export async function alarmKuraliKaydet(
  tur: AlarmTuru,
  govde: Pick<AlarmKurali, 'aktif' | 'alicilar' | 'parametreler'>,
): Promise<AlarmKurali> {
  const yanit = await api.put<{ data: AlarmKurali }>(
    `/api/v1/ayarlar/alarm-kurallari/${tur}`,
    govde,
  )

  return yanit.data.data
}
