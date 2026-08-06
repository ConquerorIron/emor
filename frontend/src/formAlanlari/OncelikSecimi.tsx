import { TabloMaddesiSecimi, type TabloMaddesiSecim } from './TabloMaddesiSecimi'

/** ERP tablo maddesi türü: Öncelik (Sipariş Önceliği) */
const ONCELIK_TURU = 36

interface OncelikSecimiProps {
  id: string
  label: string
  /** Seçili önceliğin TABLO_MADDESI_ID'si — SOHOM_SIPARIS_KAYDET @ONCELIK_ID */
  deger: string
  degisti: (secim: TabloMaddesiSecim | null) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/** Öncelik seçimi — başka ekranlarda da aynı bileşen kullanılır. */
export function OncelikSecimi(props: OncelikSecimiProps) {
  return <TabloMaddesiSecimi tur={ONCELIK_TURU} {...props} />
}
