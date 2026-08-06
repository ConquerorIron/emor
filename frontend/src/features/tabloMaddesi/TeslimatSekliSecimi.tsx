import { TabloMaddesiSecimi, type TabloMaddesiSecim } from './TabloMaddesiSecimi'

/** ERP tablo maddesi türü: Teslimat şekli */
const TESLIMAT_SEKLI_TURU = 53

interface TeslimatSekliSecimiProps {
  id: string
  label: string
  /** Seçili şeklin TABLO_MADDESI_ID'si — SOHOM_SIPARIS_KAYDET @TESLIMAT_SEKLI_ID */
  deger: string
  degisti: (secim: TabloMaddesiSecim | null) => void
  hata?: string
}

/** Teslimat şekli seçimi — başka ekranlarda da aynı bileşen kullanılır. */
export function TeslimatSekliSecimi(props: TeslimatSekliSecimiProps) {
  // ERP bu türü kayıt sırasına göre listeler (ORDER BY KAYIT_ID)
  return <TabloMaddesiSecimi tur={TESLIMAT_SEKLI_TURU} siralama="kayit_id" {...props} />
}
