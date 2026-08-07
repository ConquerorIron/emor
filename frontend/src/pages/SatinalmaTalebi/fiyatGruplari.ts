import type { TalepSatiri } from './talepSchema'

/**
 * Satırdaki FİYAT GRUPLARI. ERP satır tablosunda fiyat üç kez tekrar eder ve
 * her biri kendi parası, kuru ve tutarıyla gelir:
 *
 *   Birim fiyatı → BIRIM_FIYATI / PARA_ID  / KUR  / TUTAR
 *   Teklif       → FIYAT2       / PARA2_ID / KUR2 / TUTAR2
 *   Bütçe        → FIYAT3       / PARA3_ID / KUR3 / TUTAR3
 *
 * Tutarlar SAKLANMAZ, hesaplanır (Miktar × Fiyat × Kur) — aynı sayıyı iki
 * yerde tutmak er geç tutarsızlık üretir. Kayıt sırasında proc'a giderken
 * aynı formülle üretilir.
 */
export interface FiyatGrubu {
  fiyat: keyof TalepSatiri
  para: keyof TalepSatiri
  kur: keyof TalepSatiri
}

export const FIYAT_GRUPLARI = {
  birim: { fiyat: 'birim_fiyati', para: 'birim_fiyati_para_id', kur: 'birim_fiyati_kuru' },
  teklif: { fiyat: 'teklif_birim_fiyati', para: 'teklif_para_id', kur: 'teklif_kuru' },
  butce: {
    fiyat: 'butce_birim_fiyati',
    para: 'butce_para_id',
    kur: 'butce_birim_fiyati_kuru',
  },
} as const satisfies Record<string, FiyatGrubu>

export type FiyatGrubuAdi = keyof typeof FIYAT_GRUPLARI

/** Tutar = Miktar × Fiyat × Kur; yabancı para tutarında kur uygulanmaz */
export function tutarHesapla(
  miktar: string,
  fiyat: string,
  kur: string,
  kurUygula: boolean,
): number {
  const sayi = (metin: string) => {
    const deger = Number(metin)

    return Number.isFinite(deger) ? deger : 0
  }

  return sayi(miktar) * sayi(fiyat) * (kurUygula ? sayi(kur) : 1)
}
