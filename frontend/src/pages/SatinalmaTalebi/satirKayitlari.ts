import type { TalepSatiri } from './talepSchema'

/**
 * Satırdaki ÇOK YÜZLÜ ERP kayıtları.
 *
 * Bir ERP kaydı ızgarada birden çok sütunda görünür (ürün: kod + barkod + ad).
 * Kullanıcı hangi sütundan seçerse seçsin hepsi birlikte dolar ve ERP'ye tek
 * KAYIT_ID gider. Bu tablo "hangi kaydın hangi yüzü hangi form anahtarına
 * yazılır" sorusunun TEK cevabıdır; yeni bir çok yüzlü kayıt eklemek burada
 * bir satır yazmaktır.
 */
export interface SatirKaydi {
  /** Seçilen kaydın KAYIT_ID'sini tutan form anahtarı (ERP'ye giden değer) */
  idAnahtari: keyof TalepSatiri
  /** ERP kaydındaki alan adı → satırdaki form anahtarı */
  yuzler: Readonly<Record<string, keyof TalepSatiri>>
}

export const SATIR_KAYITLARI = {
  // Poz no'nun ekranda kendi sütunu var ama seçimle birlikte dolar; kullanıcı
  // gerekirse üzerine yazabilir (kullanıcı kararı 2026-08-07)
  aktivite: {
    idAnahtari: 'aktivite_id',
    yuzler: { kod: 'aktivite_kodu', aciklama: 'aktivite_aciklamasi', poz_no: 'poz_no' },
  },
  masrafMerkezi: {
    idAnahtari: 'masraf_merkezi_id',
    yuzler: { kod: 'masraf_merkezi_kodu', ad: 'masraf_merkezi_adi' },
  },
  // TOHOM_SIPARIS_SATIRI.URUN_YAMASI_ID — üç yüzden biri seçilmesi yeterli.
  // `basamak` ekranda sütunu olmayan bir yüzdür: Miktar alanının ondalık
  // hassasiyetini ürünün ölçü sisteminden taşır.
  urun: {
    idAnahtari: 'urun_yamasi_id',
    yuzler: {
      kod: 'urun_kodu',
      barkod: 'barkod',
      ad: 'urun_adi',
      birim: 'urun_birimi',
      birim_id: 'urun_birim_id',
      basamak: 'urun_basamak_sayisi',
    },
  },
  ambalaj: {
    idAnahtari: 'ambalaj_id',
    yuzler: { ad: 'ambalaj_adi', basamak: 'ambalaj_basamak_sayisi' },
  },
  // Aşağıdakiler tek yüzlü: ekranda yalnız AD görünür, ERP'ye KAYIT_ID gider
  ekipman: { idAnahtari: 'ekipman_id', yuzler: { ad: 'ekipman_adi' } },
  butceKalemi: { idAnahtari: 'butce_kalemi_id', yuzler: { ad: 'butce_kalemi_adi' } },
  butceBolumu: { idAnahtari: 'butce_bolumu_id', yuzler: { ad: 'butce_bolumu_adi' } },
  duranVarlik: { idAnahtari: 'duran_varlik_id', yuzler: { ad: 'duran_varlik_adi' } },
  // Satırdaki personel parti yaması ağacından gelir (başlıktaki İK kartı değil)
  personel: { idAnahtari: 'personel_id', yuzler: { ad: 'personel_adi' } },
} as const satisfies Record<string, SatirKaydi>

export type SatirKaynagi = keyof typeof SATIR_KAYITLARI
