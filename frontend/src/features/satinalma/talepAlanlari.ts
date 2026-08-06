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
  /** Kullanıcı yazamaz — program/ERP doldurur (ör. No: SOHOM_NUMERATOR_URET) */
  saltOkunur?: boolean
  /** Alan grid'de satırı tek başına kaplar (tam genişlik) */
  tamSatir?: boolean
  /**
   * Özel giriş tipi — sayfada ozelBilesenler map'i ile eşleşir
   * (ör. 'personel' → ERP view'ından aramalı react-select, 'tarih' → takvim,
   * 'opsiyonelTarih' → anahtarla açılan takvim, 'oncelik' → tablo maddesi,
   * 'aciklama200' → 200 karakter limitli açıklama, 'ilgiCinsi' → sabit liste,
   * 'ilgili' → ilgi cinsine göre etiketi değişen bağlı alan, 'depo' → depo seçimi,
   * 'teslimatAdresi' → firmamız adresleri seçimi)
   */
  tip?:
    | 'personel'
    | 'tarih'
    | 'opsiyonelTarih'
    | 'oncelik'
    | 'aciklama200'
    | 'ilgiCinsi'
    | 'ilgili'
    | 'depo'
    | 'teslimatAdresi'
}

/** Sol blok — talep başlık bilgileri (ERP ekranının sol paneli) */
export const TALEP_ALANLARI: TalepAlani[] = [
  { ad: 'personel_adi', etiketAnahtari: 'personelAdi', tip: 'personel' },
  { ad: 'no', etiketAnahtari: 'no', saltOkunur: true },
  { ad: 'tarih', etiketAnahtari: 'tarih', tip: 'tarih' },
  { ad: 'termin', etiketAnahtari: 'termin', tip: 'opsiyonelTarih' },
  { ad: 'oncelik', etiketAnahtari: 'oncelik', tip: 'oncelik' },
  { ad: 'aciklama', etiketAnahtari: 'aciklama', tip: 'aciklama200', tamSatir: true },
  { ad: 'ilgi_konusu', etiketAnahtari: 'ilgiKonusu', tip: 'ilgiCinsi' },
  // Etiketi seçilen ilgi cinsine göre değişir (Proje / Uygulama Sözleşmesi / …)
  { ad: 'ilgili', etiketAnahtari: 'projemiz', tip: 'ilgili' },
]

/** Sağ blok — teslimat bilgileri (ERP ekranının sağ paneli) */
export const TESLIMAT_ALANLARI: TalepAlani[] = [
  { ad: 'depo_adi', etiketAnahtari: 'depoAdi', tip: 'depo' },
  {
    ad: 'teslimat_adresi',
    etiketAnahtari: 'teslimatAdresi',
    tip: 'teslimatAdresi',
    tamSatir: true,
  },
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
