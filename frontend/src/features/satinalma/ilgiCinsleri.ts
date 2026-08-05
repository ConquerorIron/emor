/**
 * İlgi cinsi — SOHOM_SIPARIS_KAYDET @ILGI_CINSI (TUR) sabit değerleri
 * (ERP Satınalma Talep Formu "İlgi konusu" listesi, kullanıcı tanımı 2026-08-05).
 * Seçime göre "ilgili" alanının etiketi ve arama kaynağı (@ILGILI_ID) değişir;
 * kaynak view sorguları alan bazında eklenecek.
 */
export const ILGI_CINSLERI = ['7', '8', '11', '12', '13'] as const

export type IlgiCinsi = (typeof ILGI_CINSLERI)[number]

export const VARSAYILAN_ILGI_CINSI: IlgiCinsi = '7' // Projemiz

/**
 * Arama kaynağı (view sorgusu) tanımlanmış cinsler — diğerleri şimdilik
 * serbest metinle girilir; sorgular geldikçe genişler.
 */
export const ARAMALI_ILGI_CINSLERI: readonly IlgiCinsi[] = ['7', '8']
