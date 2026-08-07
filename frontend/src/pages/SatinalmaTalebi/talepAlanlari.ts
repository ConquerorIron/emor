import type { TalepSatiri } from './talepSchema'

/**
 * Satınalma Talebi SATIR kolonları.
 *
 * NOT: Başlık alanları artık burada değil — ekran tasarım motoruna taşındı
 * (backend App\Ekranlar\SatinalmaTalebiKatalogu + ekran_tasarimlari tablosu).
 * Satırlar da sırası gelince aynı motora taşınacak; o zaman bu liste backend
 * katalogundan gelecek, hücre çizimi ise burada kaldığı gibi kalacak.
 */

/**
 * Hücrenin nasıl çizildiği. `metin` dışındakiler ERP seçim listeleridir;
 * `yansima` kullanıcı giremediği, başlıktan türeyen değerdir.
 */
export type SatirHucreTipi =
  | 'metin'
  | 'yansima'
  | 'aktiviteKodu'
  | 'aktiviteAciklamasi'
  | 'masrafMerkeziKodu'
  | 'masrafMerkeziAdi'

export interface TalepAlani {
  /**
   * Form verisindeki anahtar. Yansıma kolonlarında yoktur — değer satırda
   * saklanmaz, başlıktan okunur (aynı veriyi iki yerde tutmamak için).
   */
  ad?: keyof TalepSatiri
  /** i18n etiketi (satinalma.alan.*) */
  etiketAnahtari: string
  hucre: SatirHucreTipi
  /** Zorunlu kolonlar başlıkta * ile işaretlenir */
  zorunlu?: boolean
}

/** Satır grid kolonları (# ve sil kolonu hariç) */
export const SATIR_ALANLARI: TalepAlani[] = [
  // Proje başlıktan gelir ve satırda kilitlidir (kullanıcı kararı 2026-08-07)
  { etiketAnahtari: 'projemiz', hucre: 'yansima' },
  { ad: 'aktivite_kodu', etiketAnahtari: 'aktiviteKodu', hucre: 'aktiviteKodu' },
  { ad: 'aktivite_aciklamasi', etiketAnahtari: 'aktiviteAciklamasi', hucre: 'aktiviteAciklamasi' },
  { ad: 'masraf_merkezi_kodu', etiketAnahtari: 'masrafMerkeziKodu', hucre: 'masrafMerkeziKodu' },
  { ad: 'masraf_merkezi_adi', etiketAnahtari: 'masrafMerkeziAdi', hucre: 'masrafMerkeziAdi' },
  { ad: 'urun_kodu', etiketAnahtari: 'urunKodu', hucre: 'metin', zorunlu: true },
  { ad: 'urun_adi', etiketAnahtari: 'urunAdi', hucre: 'metin' },
  { ad: 'urun_tarifi', etiketAnahtari: 'urunTarifi', hucre: 'metin' },
  { ad: 'miktar', etiketAnahtari: 'miktar', hucre: 'metin' },
  { ad: 'kullanim_amaci', etiketAnahtari: 'kullanimAmaci', hucre: 'metin' },
]
