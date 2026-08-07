import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'

/** ERP'de kodu ve adı ayrı sütunlarda görünen tek kayıt */
export interface KodAdSecenegi {
  kayit_id: number
  kod: string
  ad: string
}

export interface KodAdSecim {
  kayitId: string
  kod: string
  ad: string
}

interface KodAdSecimiProps {
  id: string
  label: string
  /** Seçili kaydın ERP KAYIT_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: KodAdSecim | null) => void
  secenekler: KodAdSecenegi[]
  /** Hangi yüz gösteriliyor — seçim her iki yüzde de aynı kaydı işaretler */
  goster: 'kod' | 'ad'
  hata?: string
  disabled?: boolean
  yukleniyor?: boolean
  etiketGizli?: boolean
}

/**
 * ÇİFT YÜZLÜ kayıt seçimi. ERP ızgaralarında bir kayıt iki sütunda birden
 * durur (Aktivite kodu / Aktivite açıklaması, Masraf merkezi kodu / adı);
 * kullanıcı hangisinden seçerse diğeri kendiliğinden dolar. İki sütun ayrı
 * bileşen değildir — aynı bileşenin `goster` ile değişen iki yüzüdür, seçim
 * her ikisinde de tek KAYIT_ID'yi yazar.
 */
export function KodAdSecimi({
  id,
  label,
  deger,
  degisti,
  secenekler,
  goster,
  hata,
  disabled = false,
  yukleniyor = false,
  etiketGizli = false,
}: KodAdSecimiProps) {
  const { t } = useTranslation()

  const ogeler = useMemo<SecenekOgesi[]>(
    () =>
      secenekler.map((secenek) => ({
        value: String(secenek.kayit_id),
        label: goster === 'kod' ? secenek.kod : secenek.ad,
      })),
    [secenekler, goster],
  )

  return (
    <SelectField
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      options={ogeler}
      value={ogeler.find((oge) => oge.value === deger) ?? null}
      onChange={(secim) => {
        const kayit = secim ? secenekler.find((s) => String(s.kayit_id) === secim.value) : undefined

        degisti(kayit ? { kayitId: String(kayit.kayit_id), kod: kayit.kod, ad: kayit.ad } : null)
      }}
      placeholder={t('ortak.secVeyaAra')}
      isClearable
      disabled={disabled}
      yukleniyor={yukleniyor}
      hata={hata}
    />
  )
}
