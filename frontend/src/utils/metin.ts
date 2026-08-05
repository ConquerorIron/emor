/**
 * Tanım adları BÜYÜK HARF saklanır (kullanıcı kararı 2026-07-11): Görev,
 * Departman, Proje, Firma adları + Personel ad/soyad. Backend eşleniği
 * App\Support\Metin::buyukHarf — tr-TR yereli i→İ, ı→I dönüşümünü doğru yapar.
 */
export function trBuyukHarf(deger: string): string {
  return deger.toLocaleUpperCase('tr-TR')
}

/**
 * react-hook-form register seçenekleri: form STATE'i her zaman büyük harf
 * (setValueAs — submit garantisi) + input yazarken büyük gösterir (onChange
 * DOM değerini dönüştürür). Kullanım: {...register('ad', buyukHarfKaydi)}
 */
export const buyukHarfKaydi = {
  setValueAs: (deger: unknown): unknown => (typeof deger === 'string' ? trBuyukHarf(deger) : deger),
  onChange: (olay: { target: { value: string } }): void => {
    olay.target.value = trBuyukHarf(olay.target.value)
  },
}
