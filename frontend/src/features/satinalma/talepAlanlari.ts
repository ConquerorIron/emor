/**
 * Satınalma Talebi form alanları — ERP'deki "Satınalma Talep Formu" ekranının
 * karşılığı. Alanlar config olarak tutulur; sonraki adımlarda buraya
 * görünürlük (gizli) ve giriş tipi (dürbün/tarih/sayı) tanımları eklenecek.
 *
 * ŞİMDİLİK tüm alanlar düz metin girişidir (kullanıcı kararı 2026-08-05);
 * tipler adım adım özelleşecek.
 */

export interface TalepAlani {
  /** Form verisindeki anahtar (snake_case — proc parametre eşlemesine hazırlık) */
  ad: string
  /** i18n etiketi (satinalma.alan.*) */
  etiketAnahtari: string
  /** Çok satırlı metin (ör. teslimat adresi) */
  cokSatir?: boolean
}

/** Sol blok — talep başlık bilgileri (ERP ekranının sol paneli) */
export const TALEP_ALANLARI: TalepAlani[] = [
  { ad: 'personel_adi', etiketAnahtari: 'personelAdi' },
  { ad: 'no', etiketAnahtari: 'no' },
  { ad: 'tarih', etiketAnahtari: 'tarih' },
  { ad: 'termin', etiketAnahtari: 'termin' },
  { ad: 'oncelik', etiketAnahtari: 'oncelik' },
  { ad: 'aciklama', etiketAnahtari: 'aciklama' },
  { ad: 'ilgi_konusu', etiketAnahtari: 'ilgiKonusu' },
  { ad: 'projemiz', etiketAnahtari: 'projemiz' },
]

/** Sağ blok — teslimat bilgileri (ERP ekranının sağ paneli) */
export const TESLIMAT_ALANLARI: TalepAlani[] = [
  { ad: 'depo_adi', etiketAnahtari: 'depoAdi' },
  { ad: 'teslimat_adresi', etiketAnahtari: 'teslimatAdresi', cokSatir: true },
  { ad: 'teslimat_bicimi', etiketAnahtari: 'teslimatBicimi' },
  { ad: 'teslimat_sekli', etiketAnahtari: 'teslimatSekli' },
  { ad: 'teslimat_suresi_tarih', etiketAnahtari: 'teslimatSuresiTarih' },
  { ad: 'teslimat_suresi_sure', etiketAnahtari: 'teslimatSuresiSure' },
  { ad: 'alim_yeri', etiketAnahtari: 'alimYeri' },
]

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
