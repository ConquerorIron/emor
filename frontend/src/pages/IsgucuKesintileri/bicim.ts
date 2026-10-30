/** Noktalı decimal metni ("2562.15") Türkçe biçimde gösterir: "2.562,15". */
export function tutarGoster(deger: string | number, basamak = 2): string {
  const sayi = Number(deger)

  return Number.isFinite(sayi)
    ? sayi.toLocaleString('tr-TR', {
        minimumFractionDigits: basamak,
        maximumFractionDigits: basamak,
      })
    : String(deger)
}
