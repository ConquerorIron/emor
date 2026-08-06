import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import type { IlgiCinsi } from './ilgiCinsleri'
import { ilgiliSecenekleriGetir } from './api'

interface IlgiliSecimiProps {
  /** İlgi cinsi — seçenek kaynağını belirler (7=Proje, 8=Uygulama Sözleşmesi…) */
  cins: IlgiCinsi
  id: string
  label: string
  /** Seçili kaydın ERP KAYIT_ID'si — SOHOM_SIPARIS_KAYDET @ILGILI_ID */
  deger: string
  degisti: (kayitId: string) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/**
 * İlgi konusuna bağlı kayıt seçimi — cinse göre farklı ERP view'ından aramalı
 * liste; etiket "KOD — AD (EK)" biçimindedir. Seçenekler cins başına 5 dk
 * önbelleklenir.
 */
export function IlgiliSecimi({
  cins,
  id,
  label,
  deger,
  degisti,
  hata,
  disabled = false,
}: IlgiliSecimiProps) {
  const { t } = useTranslation()

  const kayitlar = useQuery({
    queryKey: queryKeys.secenekler.ilgili(cins),
    queryFn: () => ilgiliSecenekleriGetir(cins),
    staleTime: 5 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (kayitlar.data ?? []).map((kayit) => ({
        value: String(kayit.kayit_id),
        // Cins bazlı gösterim (kullanıcı tanımı 2026-08-05):
        //   7 Projemiz → yalnız KOD (kod zaten proje adını içerir)
        //   8 Uygulama Sözleşmesi → SIPARIS_NO — UNVAN
        //   11 Arızalı Yedek Parça → yalnız ÜRÜN ADI
        //   12 İş Paketi / 13 Satınalma Sözleşmesi → yalnız SIPARIS_NO
        label:
          cins === '8'
            ? kayit.kod + (kayit.ad ? ` — ${kayit.ad}` : '')
            : cins === '11'
              ? kayit.ad
              : kayit.kod,
      })),
    [kayitlar.data, cins],
  )

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => degisti(secim?.value ?? '')}
      placeholder={t('ortak.secVeyaAra')}
      isClearable
      disabled={disabled}
      yukleniyor={kayitlar.isPending}
      hata={hata ?? (kayitlar.isError ? t(apiErrorKey(kayitlar.error)) : undefined)}
    />
  )
}
