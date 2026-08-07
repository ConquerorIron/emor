import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'

/** ERP'de birden çok sütunda görünen tek kayıt (kod, ad, barkod…) */
export type KayitYuzleri = { kayit_id: number } & Record<string, unknown>

/**
 * Izgara seçim bileşenlerinin ORTAK sözleşmesi. Hepsi aynı imzayı taşır ki
 * hücre çizimi kaynağa göre dallanmasın — kaynak → bileşen eşlemesi tek bir
 * kayıt defterinde durur. Bir bileşen kendisine uymayan alanı yok sayar
 * (ör. ekipman listesi projeye bağlı değildir).
 */
export interface KayitSecimProps {
  id: string
  label: string
  /** Seçili kaydın ERP KAYIT_ID'si */
  deger: string
  degisti: (secim: KayitYuzleri | null) => void
  /** Kaydın hangi yüzü (alanı) bu sütunda gösteriliyor */
  goster: string
  /** Projeye bağlı listelerin kaynağı; '' = proje seçilmedi */
  projemizId: string
  /** Sunucu aramalı listelerde satırda saklı gösterim */
  seciliEtiket?: string
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

interface CokYuzluSecimProps<T extends KayitYuzleri> {
  id: string
  label: string
  /** Seçili kaydın ERP KAYIT_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: T | null) => void
  secenekler: T[]
  /**
   * Kaydın hangi yüzü (alanı) bu sütunda gösteriliyor. ERP kaydındaki alan
   * adıyla birebir aynı olmalı — eşleşme `satirKayitlari` tablosunda tanımlı
   * ve seçim testleriyle doğrulanır.
   */
  goster: string
  /**
   * Seçili kaydın gösterimi. Liste sunucuda süzülüyorsa (ürün) seçili kayıt
   * o anki listede olmayabilir; etiket kaybolmasın diye dışarıdan verilir.
   */
  seciliEtiket?: string
  /** Sunucu taraflı arama — verildiğinde istemci filtresi kapanır */
  aramaDegisti?: (girdi: string) => void
  hata?: string
  disabled?: boolean
  yukleniyor?: boolean
  etiketGizli?: boolean
}

/**
 * ÇOK YÜZLÜ kayıt seçimi. ERP ızgaralarında bir kayıt birden çok sütunda
 * birden durur (Aktivite kodu / açıklaması, Masraf merkezi kodu / adı, Ürün
 * kodu / barkod / adı); kullanıcı hangisinden seçerse diğerleri kendiliğinden
 * dolar. Sütunlar ayrı bileşen DEĞİLDİR — aynı bileşenin `goster` ile değişen
 * yüzleridir ve hepsi tek KAYIT_ID'yi işaretler.
 */
export function CokYuzluSecim<T extends KayitYuzleri>({
  id,
  label,
  deger,
  degisti,
  secenekler,
  goster,
  seciliEtiket,
  aramaDegisti,
  hata,
  disabled = false,
  yukleniyor = false,
  etiketGizli = false,
}: CokYuzluSecimProps<T>) {
  const { t } = useTranslation()

  const ogeler = useMemo<SecenekOgesi[]>(
    () =>
      secenekler.map((secenek) => ({
        value: String(secenek.kayit_id),
        label: String(secenek[goster] ?? ''),
      })),
    [secenekler, goster],
  )

  // Seçili kayıt listede yoksa (sunucu araması) satırda saklı gösterim kullanılır
  const secili =
    ogeler.find((oge) => oge.value === deger) ??
    (deger !== '' && seciliEtiket ? { value: deger, label: seciliEtiket } : null)

  return (
    <SelectField
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      options={ogeler}
      value={secili}
      onChange={(secim) =>
        degisti(secim ? (secenekler.find((s) => String(s.kayit_id) === secim.value) ?? null) : null)
      }
      aramaDegisti={aramaDegisti}
      placeholder={t('ortak.secVeyaAra')}
      isClearable
      disabled={disabled}
      yukleniyor={yukleniyor}
      hata={hata}
    />
  )
}
