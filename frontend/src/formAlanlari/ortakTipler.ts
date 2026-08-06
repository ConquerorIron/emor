import type { FieldValues, UseFormReturn } from 'react-hook-form'

import type { DuzenAlani, KatalogAlani } from '@/features/ekranTasarim/types'

/**
 * Alan bileşenlerinin motorla sözleşmesi. Tasarım kuralları (etiket + zorunluluk
 * yıldızı, çevrilmiş hata, salt okunur kilidi) TEK yerde — AlanGirisi'nde —
 * hesaplanıp burada verilir; giriş tanımları `{...ortak}` diye geçirir.
 */
export interface OrtakGirisProps {
  id: string
  label: string
  hata?: string
  disabled: boolean
}

/** Ekrandan bağımsız form erişimi (hangi ekran olursa olsun aynı sözleşme). */
export type GenelForm = UseFormReturn<FieldValues>

/** Bir giriş tanımının çizim sırasında eline geçen her şey. */
export interface GirisBaglami {
  ortak: OrtakGirisProps
  katalog: KatalogAlani
  duzen: DuzenAlani
  form: GenelForm
  /** Ana alanın (veri_anahtarlari[0]) anlık değeri */
  deger: string
  /** Ana alanı günceller */
  degistir: (deger: string) => void
  /** Alanın yazdığı diğer anahtarlar (veri_anahtarlari[1], …) */
  yanDegistir: (indeks: number, deger: string) => void
  /** Katalogda tanımlı bağlı alanı okur (ör. ilgi cinsi) */
  bagliOku: () => string
  /** Katalogda tanımlı bağlı alanı yazar (ör. cins değişince ilgiliyi sıfırla) */
  bagliYaz: (deger: string) => void
}

/** Bir giriş tipinin YALNIZ kendine özgü kısmı; ortak kurallar motorda. */
export interface GirisTanimi {
  /**
   * Etiketi alanın kendisi üretiyorsa (ör. ilgi cinsine göre "Proje"/"İş Paketi").
   * Zorunluluk yıldızını yine MOTOR ekler — burada ham metin döndürülür.
   */
  etiket?: (baglam: { bagliOku: () => string; t: (anahtar: string) => string }) => string
  ciz: (baglam: GirisBaglami) => React.ReactNode
}
