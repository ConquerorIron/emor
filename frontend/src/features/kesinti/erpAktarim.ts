/**
 * ERP'den tanım aktarımının ortak tipleri (Projeler, Firmalar) — backend
 * App\Services\Kesinti\ErpAktarimi.
 *
 * yeni → eklenir, guncellenecek → bilgiler ERP'deki gibi olur, eslesecek →
 * elle açılmış kayıt ERP'ye bağlanır, ayni → değişiklik yok, cakisma /
 * gecersiz → aktarılamaz (neden alanında).
 */
export type ErpAktarimDurumu =
  'yeni' | 'guncellenecek' | 'eslesecek' | 'ayni' | 'cakisma' | 'gecersiz'

export interface ErpAktarimSatiri {
  /** ERP sırası — satır anahtarı (GUID tekrar edebilir) */
  sira: number
  guid: string
  durum: ErpAktarimDurumu
  neden: string | null
}

export interface ErpAktarimSonucu {
  eklenen: number
  guncellenen: number
  eslesen: number
  atlanan: number
}

/** Seçilip aktarılabilen durumlar */
export const AKTARILABILIR_DURUMLAR: readonly ErpAktarimDurumu[] = [
  'yeni',
  'guncellenecek',
  'eslesecek',
]
