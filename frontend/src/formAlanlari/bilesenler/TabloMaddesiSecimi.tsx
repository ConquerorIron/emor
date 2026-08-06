import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import { tabloMaddesiSecenekleriGetir, type MaddeSiralamasi } from '../veri/secenekApi'

export interface TabloMaddesiSecim {
  /** ERP TABLO_MADDESI_ID — proc parametrelerine set edilecek değer */
  kayitId: string
  ad: string
}

interface TabloMaddesiSecimiProps {
  /** ERP madde türü (ör. 36 = Öncelik) */
  tur: number
  id: string
  label: string
  /** Seçili maddenin TABLO_MADDESI_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: TabloMaddesiSecim | null) => void
  hata?: string
  isClearable?: boolean
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
  /** ERP sorgusunun sıralaması (tür bazında değişir) */
  siralama?: MaddeSiralamasi
}

/**
 * ERP tablo maddesi seçimi — VOHOM_TABLO_MADDESI'nden TUR'a göre aramalı
 * liste. Öncelik gibi alan-özel sarmalayıcılar bu bileşeni kullanır
 * (OncelikSecimi deseni); seçenekler TUR başına 5 dk önbelleklenir.
 */
export function TabloMaddesiSecimi({
  tur,
  id,
  label,
  deger,
  degisti,
  hata,
  isClearable = true,
  disabled = false,
  siralama = 'ad',
}: TabloMaddesiSecimiProps) {
  const { t } = useTranslation()

  const maddeler = useQuery({
    queryKey: queryKeys.secenekler.tabloMaddesi(tur, siralama),
    queryFn: () => tabloMaddesiSecenekleriGetir(tur, siralama),
    staleTime: 5 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (maddeler.data ?? []).map((m) => ({
        value: String(m.kayit_id),
        label: m.ad,
      })),
    [maddeler.data],
  )

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => {
        degisti(secim ? { kayitId: secim.value, ad: secim.label } : null)
      }}
      placeholder={t('ortak.secVeyaAra')}
      isClearable={isClearable}
      disabled={disabled}
      yukleniyor={maddeler.isPending}
      hata={hata ?? (maddeler.isError ? t(apiErrorKey(maddeler.error)) : undefined)}
    />
  )
}
