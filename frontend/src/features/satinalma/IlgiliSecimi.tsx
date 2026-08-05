import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import type { IlgiCinsi } from './ilgiCinsleri'
import { ilgiliSecenekleriGetir } from './ilgiliApi'

interface IlgiliSecimiProps {
  /** İlgi cinsi — seçenek kaynağını belirler (7=Proje, 8=Uygulama Sözleşmesi…) */
  cins: IlgiCinsi
  id: string
  label: string
  /** Seçili kaydın ERP KAYIT_ID'si — SOHOM_SIPARIS_KAYDET @ILGILI_ID */
  deger: string
  degisti: (kayitId: string) => void
  hata?: string
}

/**
 * İlgi konusuna bağlı kayıt seçimi — cinse göre farklı ERP view'ından aramalı
 * liste; etiket "KOD — AD (EK)" biçimindedir. Seçenekler cins başına 5 dk
 * önbelleklenir.
 */
export function IlgiliSecimi({ cins, id, label, deger, degisti, hata }: IlgiliSecimiProps) {
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
        // Projemiz (7): KOD zaten proje adını içerir ("A01 - ZONE 4-5 …") —
        // yalnız kod gösterilir; diğer cinslerde kod + ünvan (+ proje) birleşir
        label:
          cins === '7'
            ? kayit.kod
            : kayit.kod +
              (kayit.ad && kayit.ad !== kayit.kod ? ` — ${kayit.ad}` : '') +
              (kayit.ek ? ` (${kayit.ek})` : ''),
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
      yukleniyor={kayitlar.isPending}
      hata={hata ?? (kayitlar.isError ? t(apiErrorKey(kayitlar.error)) : undefined)}
    />
  )
}
