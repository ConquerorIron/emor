/**
 * Tanım adları BÜYÜK HARF saklanır (kullanıcı kararı 2026-07-11): Görev,
 * Departman, Proje, Firma adları + Personel ad/soyad. Backend eşleniği
 * App\Support\Metin::buyukHarf — tr-TR yereli i→İ, ı→I dönüşümünü doğru yapar.
 */
export function trBuyukHarf(deger: string): string {
  return deger.toLocaleUpperCase('tr-TR')
}

/**
 * Serbest metin araması için karşılaştırma biçimi: Türkçe küçük harf ve
 * I/İ/ı/i farksız. Yalnız tr küçültmede "INS" → "ıns" olur ve "ins" aranınca
 * bulunmaz (ERP kodları ASCII büyük harfle gelir); iki taraf da bununla çevrilir.
 */
export function aramaBicimi(deger: string): string {
  return deger.toLocaleLowerCase('tr-TR').replaceAll('ı', 'i')
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
