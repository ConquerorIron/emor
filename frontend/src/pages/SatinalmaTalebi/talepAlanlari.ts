import type { SatirKaynagi } from './satirKayitlari'
import type { TalepSatiri } from './talepSchema'

/**
 * Satınalma Talebi SATIR kolonları.
 *
 * NOT: Başlık alanları artık burada değil — ekran tasarım motoruna taşındı
 * (backend App\Ekranlar\SatinalmaTalebiKatalogu + ekran_tasarimlari tablosu).
 * Satırlar da sırası gelince aynı motora taşınacak; o zaman bu liste backend
 * katalogundan gelecek, hücre çizimi ise burada kaldığı gibi kalacak.
 */

/** Hücrenin nasıl çizildiği */
export type SatirHucreTanimi =
  /** Serbest metin — değeri satırda kendi anahtarında durur */
  | { tip: 'metin'; ad: keyof TalepSatiri }
  /** Kullanıcı giremez; değer başlıktan türer (satırda saklanmaz) */
  | { tip: 'yansima' }
  /** ERP kaydının bir yüzü — seçim tüm yüzleri birden doldurur */
  | { tip: 'secim'; kaynak: SatirKaynagi; goster: string }

export interface TalepAlani {
  /** i18n etiketi (satinalma.alan.*) */
  etiketAnahtari: string
  hucre: SatirHucreTanimi
  /** Zorunlu kolonlar başlıkta * ile işaretlenir */
  zorunlu?: boolean
}

/** Satır grid kolonları (# ve sil kolonu hariç) */
export const SATIR_ALANLARI: TalepAlani[] = [
  // Proje başlıktan gelir ve satırda kilitlidir (kullanıcı kararı 2026-08-07)
  { etiketAnahtari: 'projemiz', hucre: { tip: 'yansima' } },
  { etiketAnahtari: 'aktiviteKodu', hucre: { tip: 'secim', kaynak: 'aktivite', goster: 'kod' } },
  {
    etiketAnahtari: 'aktiviteAciklamasi',
    hucre: { tip: 'secim', kaynak: 'aktivite', goster: 'aciklama' },
  },
  {
    etiketAnahtari: 'masrafMerkeziKodu',
    hucre: { tip: 'secim', kaynak: 'masrafMerkezi', goster: 'kod' },
  },
  {
    etiketAnahtari: 'masrafMerkeziAdi',
    hucre: { tip: 'secim', kaynak: 'masrafMerkezi', goster: 'ad' },
  },
  // Ürünün üç yüzü: birinden seçmek yeterli, diğer ikisi dolar
  {
    etiketAnahtari: 'urunKodu',
    hucre: { tip: 'secim', kaynak: 'urun', goster: 'kod' },
    zorunlu: true,
  },
  { etiketAnahtari: 'barkod', hucre: { tip: 'secim', kaynak: 'urun', goster: 'barkod' } },
  { etiketAnahtari: 'urunAdi', hucre: { tip: 'secim', kaynak: 'urun', goster: 'ad' } },
  { etiketAnahtari: 'urunTarifi', hucre: { tip: 'metin', ad: 'urun_tarifi' } },
  { etiketAnahtari: 'miktar', hucre: { tip: 'metin', ad: 'miktar' } },
  { etiketAnahtari: 'kullanimAmaci', hucre: { tip: 'metin', ad: 'kullanim_amaci' } },
]
