/**
 * Satınalma Talebi SATIR kolonları.
 *
 * NOT: Başlık alanları artık burada değil — ekran tasarım motoruna taşındı
 * (backend App\Ekranlar\SatinalmaTalebiKatalogu + ekran_tasarimlari tablosu).
 * Satırlar da sırası gelince aynı motora taşınacak.
 */

export interface TalepAlani {
  /** Form verisindeki anahtar (snake_case — proc parametre eşlemesine hazırlık) */
  ad: string
  /** i18n etiketi (satinalma.alan.*) */
  etiketAnahtari: string
}

/** Satır grid kolonları (# ve sil kolonu hariç) */
export const SATIR_ALANLARI: TalepAlani[] = [
  { ad: 'projemiz', etiketAnahtari: 'projemiz' },
  { ad: 'aktivite_kodu', etiketAnahtari: 'aktiviteKodu' },
  { ad: 'aktivite_aciklamasi', etiketAnahtari: 'aktiviteAciklamasi' },
  { ad: 'urun_kodu', etiketAnahtari: 'urunKodu' },
  { ad: 'urun_adi', etiketAnahtari: 'urunAdi' },
  { ad: 'urun_tarifi', etiketAnahtari: 'urunTarifi' },
  { ad: 'miktar', etiketAnahtari: 'miktar' },
  { ad: 'kullanim_amaci', etiketAnahtari: 'kullanimAmaci' },
]
