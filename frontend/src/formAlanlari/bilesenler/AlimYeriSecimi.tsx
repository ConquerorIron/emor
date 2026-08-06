import { useTranslation } from 'react-i18next'

import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import { ALIM_YERLERI, VARSAYILAN_ALIM_YERI, type AlimYeri } from '../veri/sabitler'

interface AlimYeriSecimiProps {
  id: string
  label: string
  deger: string
  degisti: (deger: AlimYeri) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/** Alım yeri seçimi — sipariş/talep ekranlarında ortak kullanılır. */
export function AlimYeriSecimi({
  id,
  label,
  deger,
  degisti,
  hata,
  disabled = false,
}: AlimYeriSecimiProps) {
  const { t } = useTranslation()

  const secenekler: SecenekOgesi[] = ALIM_YERLERI.map((yer) => ({
    value: yer,
    label: t(`satinalma.alimYeri.${yer}`),
  }))

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => degisti((secim?.value as AlimYeri) ?? VARSAYILAN_ALIM_YERI)}
      isClearable={false}
      disabled={disabled}
      hata={hata}
    />
  )
}
