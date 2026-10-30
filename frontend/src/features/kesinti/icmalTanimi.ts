import {
  YANSITMA_TURLERI,
  type IcmalApi,
  type IcmalSatiri,
  type KesintiSecenekleri,
  type KesintiTanimi,
} from './icmalApi'

/** Tanım seçimleri: formda id ile gönderilir, listede koduyla gösterilir */
export type TanimAlani = 'kesinti_turu' | 'taseron' | 'proje' | 'para_birimi'

/**
 * Bir icmal kolonunun türü — listede, önizlemede ve formda nasıl çizileceğini
 * belirler. Etiketler `kesinti.alan.<alan>`, seçenekler
 * `kesinti.secenek.<alan>.<değer>` çevirilerindedir.
 */
export type AlanTuru =
  | { tur: 'tanim' }
  | { tur: 'tarih' }
  | { tur: 'metin'; sinir: 20 | 50 | 100 | 200; zorunlu?: boolean }
  | { tur: 'secenek'; degerler: readonly string[] }
  | { tur: 'sayi'; basamak: number }
  /** Yansıtılan / kalan: elle girişte hesaplanır, gönderilmez */
  | { tur: 'hesap' }

/** Form bölümündeki alan; geniş alan iki kolon kaplar */
export type FormAlani = string | { alan: string; genis: true }

/**
 * Bir icmal ekranının tanımı: kolonları, sıraları ve uçları. Liste, form,
 * içe aktarım ve yüklemeler bu tanımdan çizilir (features/kesinti/Icmal*).
 */
export interface IcmalTanimi<T extends IcmalSatiri> {
  /** Çeviri (kesinti.<anahtar>.baslik / aciklamaMetni), sorgu anahtarları, kalıcı tercihler, element id'leri */
  anahtar: string
  /** İzin öneki: `<izin>.goruntule` / `<izin>.guncelle` */
  izin: string
  api: IcmalApi<T>
  alanlar: Record<string, AlanTuru>
  /** Liste kolonlarının sırası (sıralama anahtarı = alan adı) */
  liste: string[]
  /** İçe aktarım önizlemesinde gösterilen kolonlar (hepsi yüklemeden sonra listede) */
  onizleme: string[]
  /** Form bölümleri: başlık çeviri anahtarı + alanlar */
  form: { baslik: string; alanlar: FormAlani[] }[]
  /** Silme onayında satırı tanıtan metin (ör. adı soyadı, ürün) */
  satirAdi: (satir: T) => string
}

/**
 * Her icmalde aynı adla duran kolonlar. Yansıtma türü taşeronun hemen
 * sağındadır (kullanıcı kararı 2026-10-30).
 */
export const ORTAK_ALANLAR: Record<string, AlanTuru> = {
  kesinti_turu: { tur: 'tanim' },
  taseron: { tur: 'tanim' },
  yansitma_turu: { tur: 'secenek', degerler: YANSITMA_TURLERI },
  proje: { tur: 'tanim' },
  mahal: { tur: 'metin', sinir: 200 },
  tarih: { tur: 'tarih' },
  kaynak_firma: { tur: 'metin', sinir: 200 },
  birim_fiyat: { tur: 'sayi', basamak: 4 },
  para_birimi: { tur: 'tanim' },
  toplam_tutar: { tur: 'sayi', basamak: 2 },
  iptal_edilen_tutar: { tur: 'sayi', basamak: 2 },
  yansitilan_tutar: { tur: 'hesap' },
  kesilen_tutar: { tur: 'sayi', basamak: 2 },
  kalan_tutar: { tur: 'hesap' },
}

/** Tanım alanının satırdaki ilişkisi, form/gövde anahtarı ve seçim listesi */
export const TANIMLAR: Record<
  TanimAlani,
  { iliski: keyof IcmalSatiri; anahtar: string; liste: keyof KesintiSecenekleri }
> = {
  kesinti_turu: { iliski: 'kesinti_turu', anahtar: 'kesinti_turu_id', liste: 'kesinti_turleri' },
  taseron: { iliski: 'firma', anahtar: 'firma_id', liste: 'firmalar' },
  proje: { iliski: 'proje', anahtar: 'proje_id', liste: 'projeler' },
  para_birimi: { iliski: 'para_birimi', anahtar: 'para_birimi_id', liste: 'para_birimleri' },
}

/** Satırdaki ham değer (alan adıyla) */
export function alanDegeri(satir: IcmalSatiri, alan: string): unknown {
  return (satir as unknown as Record<string, unknown>)[alan]
}

/** Satırdaki tanım (tür, taşeron, proje, para birimi) */
export function satirTanimi(satir: IcmalSatiri, alan: TanimAlani): KesintiTanimi {
  return satir[TANIMLAR[alan].iliski] as KesintiTanimi
}

/** Hesaplanan ve sayı alanlarının ondalık basamağı */
export function basamak(tur: AlanTuru): number {
  return tur.tur === 'sayi' ? tur.basamak : 2
}
