/**
 * Numeric metin tutarı (ör. "1250.5000") Türkçe biçimde gösterir.
 * Number dönüşümü büyük tutarların kuruşlarını bozacağı için metin üzerinde
 * yarımdan yukarı yuvarlama yapılır (PostgreSQL round(numeric, 2) ile aynı).
 */
export function tutarGoster(tutar: string | null): string {
  if (tutar === null || tutar === '') {
    return '—'
  }

  const parca = /^(-?)(\d+)(?:\.(\d+))?$/.exec(tutar)
  if (parca === null) {
    return tutar
  }

  const kesir = (parca[3] ?? '').padEnd(3, '0')
  const kurus = BigInt(parca[2]) * 100n + BigInt(kesir.slice(0, 2)) + (kesir[2] >= '5' ? 1n : 0n)
  const tam = (kurus / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.')
  const kalan = (kurus % 100n).toString().padStart(2, '0')

  return `${parca[1]}${tam},${kalan}`
}

/** Faz 1 ilk tarama tarihi (kullanıcı kararı S11) — backend config ile aynı */
export const ILK_TARAMA_TARIHI = '2026-01-01'

/** Liste aralığı ve elle senkron sınırları — backend config/efatura.php ile aynı */
export const LISTE_AZAMI_GUN = 366
export const MANUEL_AZAMI_GUN = 92
