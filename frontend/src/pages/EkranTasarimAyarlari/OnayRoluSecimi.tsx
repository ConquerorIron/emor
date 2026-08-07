import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { onayRolleriGetir } from '@/formAlanlari'

/**
 * Ekranın onay rolü — alan değil, ekranın AYARI.
 *
 * Talep onaya sunulurken ERP'ye hangi ROL_ID'nin gideceğini belirler; tasarımla
 * birlikte sürümlenir, yani taslakta değiştirilip yayınlanınca yürürlüğe girer.
 */
export function OnayRoluSecimi({
  deger,
  degisti,
}: {
  deger: number | null
  degisti: (rolId: number | null) => void
}) {
  const { t } = useTranslation()

  const roller = useQuery({
    queryKey: queryKeys.secenekler.onayRolleri,
    queryFn: onayRolleriGetir,
    staleTime: 30 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (roller.data ?? []).map((rol) => ({
        value: String(rol.kayit_id),
        label: rol.kod === '' ? rol.ad : `${rol.kod} — ${rol.ad}`,
      })),
    [roller.data],
  )

  return (
    <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
      <SelectField
        id="onay-rolu"
        label={t('tasarim.onayRolu')}
        options={secenekler}
        value={secenekler.find((oge) => oge.value === String(deger ?? '')) ?? null}
        onChange={(secim) => degisti(secim ? Number(secim.value) : null)}
        placeholder={t('ortak.secVeyaAra')}
        isClearable
        yukleniyor={roller.isPending}
        hata={roller.isError ? t(apiErrorKey(roller.error)) : undefined}
      />
      <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
        {t('tasarim.onayRoluAciklama')}
      </p>
    </div>
  )
}
