/** Yalnız 5 tutarın toplandığı ekranların özet sekmeleri (Yemek ile aynı düzen) */
export const TUTAR_TOPLAMLARI = [
  'toplam_tutar',
  'iptal_edilen_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
]

/**
 * Taşeron | Yansıtma Türü | Proje | Tarih (Yıl + Ay) — her biri + Proje + Para
 * Birimi; backend sorgusunun PIVOTLAR'ı ile aynı olmalı.
 */
export const STANDART_PIVOTLAR = [
  { anahtar: 'taseron', gruplar: ['taseron', 'proje', 'para_birimi'] },
  { anahtar: 'yansitma_turu', gruplar: ['yansitma_turu', 'proje', 'para_birimi'] },
  { anahtar: 'proje', gruplar: ['proje', 'para_birimi'] },
  { anahtar: 'tarih', gruplar: ['yil', 'ay', 'proje', 'para_birimi'] },
].map((pivot) => ({ ...pivot, toplamlar: TUTAR_TOPLAMLARI }))
