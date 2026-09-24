/**
 * Fatura durumuna göre renk (kullanıcı isteği 2026-09-24): satır arka planı ve
 * durum filtresindeki işaret AYNI tablodan beslenir. Renkler bilinçli olarak
 * açık tonda — metin her iki temada da okunur kalır.
 *
 * Alındı (RECEIVED) ve bilinmeyen kodlar renksizdir.
 */
type DurumRengi = 'kirmizi' | 'yesil' | 'sari' | 'gri'

const DURUM_RENKLERI: Record<string, DurumRengi> = {
  REJECTED: 'kirmizi',
  UNDELIVERED: 'kirmizi',
  RESPONSE_UNDELIVERED: 'kirmizi',
  ACCEPTED: 'yesil',
  DELIVERED: 'yesil',
  WAITING_FOR_RESPONSE: 'sari',
  NEW: 'sari',
  IN_PROCESSING: 'sari',
  SEND_TO_GIB: 'sari',
  SEND_TO_RECEIVER: 'sari',
  RESPONSE_TIME_EXPIRED: 'gri',
}

const SATIR_SINIFLARI: Record<DurumRengi, string> = {
  kirmizi: 'bg-red-50 hover:bg-red-100 dark:bg-red-950/30 dark:hover:bg-red-950/50',
  yesil: 'bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:hover:bg-emerald-950/50',
  sari: 'bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/30 dark:hover:bg-amber-950/50',
  gri: 'bg-slate-100 hover:bg-slate-200 dark:bg-slate-800/70 dark:hover:bg-slate-800',
}

const ISARET_SINIFLARI: Record<DurumRengi, string> = {
  kirmizi: 'border-red-300 bg-red-100 dark:border-red-700 dark:bg-red-950',
  yesil: 'border-emerald-300 bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950',
  sari: 'border-amber-300 bg-amber-100 dark:border-amber-700 dark:bg-amber-950',
  gri: 'border-slate-300 bg-slate-200 dark:border-slate-600 dark:bg-slate-700',
}

/** Satır arka planı (hover dahil); renksiz durumda undefined. */
export function durumSatirSinifi(durum: string | null): string | undefined {
  const renk = durum ? DURUM_RENKLERI[durum] : undefined

  return renk ? SATIR_SINIFLARI[renk] : undefined
}

/** Filtre seçeneğindeki renk işareti; renksiz durumda çerçeveli boş işaret. */
export function durumIsaretSinifi(durum: string): string {
  const renk = DURUM_RENKLERI[durum]

  return renk
    ? ISARET_SINIFLARI[renk]
    : 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900'
}
