/**
 * Yüklenebilecek en büyük dosya. Sunucuyla aynı: backend kuralı `max:10240`
 * (KB), PHP `upload_max_filesize = 10M` (G14). Değişirse üçü birlikte değişir.
 */
export const AZAMI_YUKLEME_MB = 10

/** Sınırı aşan dosyanın boyutu (MB, tek ondalık, Türkçe); sınırdaysa null */
export function sinirAsanBoyut(dosya: File | null): string | null {
  if (dosya === null || dosya.size <= AZAMI_YUKLEME_MB * 1024 * 1024) {
    return null
  }

  return (dosya.size / (1024 * 1024)).toLocaleString('tr-TR', { maximumFractionDigits: 1 })
}
