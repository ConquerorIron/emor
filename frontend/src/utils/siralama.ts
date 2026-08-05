/**
 * Türkçe metin sıralayıcısı (kullanıcı isteği 2026-07-13): JS'in varsayılan
 * string karşılaştırması Ç/Ğ/İ/Ö/Ş/Ü'yü ASCII kod noktalarına göre a-z'nin
 * ARDINA attığından Türkçe adlar listenin en altına düşüyordu. Intl.Collator('tr')
 * doğru harf sırasını verir; `numeric` sayısal parçaları (kod/TC) doğru sıralar,
 * `sensitivity: 'base'` büyük/küçük ve aksan farkının sırayı bozmasını engeller.
 */
export const trKarsilastirici = new Intl.Collator('tr', { numeric: true, sensitivity: 'base' })

/** İki metni Türkçe alfabe sırasına göre karşılaştırır (Array.prototype.sort uyumlu). */
export function trSirala(a: string | null | undefined, b: string | null | undefined): number {
  return trKarsilastirici.compare(String(a ?? ''), String(b ?? ''))
}
