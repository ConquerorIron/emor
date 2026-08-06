/**
 * Tasarlanabilir ekranların anahtarları — backend katalog anahtarlarıyla birebir
 * (App\Ekranlar\*Katalogu::ANAHTAR). Hem ilgili sayfa hem tasarım editörü
 * kullandığı için ortak yerde durur; sayfalar birbirinden import etmez.
 */
export const SATINALMA_TALEP_EKRANI = 'satinalma.talep'

/** Tasarım editöründe listelenen ekranlar (katalog eklendikçe genişler). */
export const TASARLANABILIR_EKRANLAR = [
  { anahtar: SATINALMA_TALEP_EKRANI, etiketAnahtari: 'satinalma.baslik' },
] as const
