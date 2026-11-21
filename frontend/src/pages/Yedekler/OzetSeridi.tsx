import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { boyutGoster, istanbulZamani } from '@/features/yedekleme/bicim'
import type { YedekOzeti } from '@/features/yedekleme/yedekApi'

function Kart({ baslik, deger, alt }: { baslik: string; deger: string; alt?: ReactNode }) {
  return (
    <div className="min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
      <dt className="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
        {baslik}
      </dt>
      <dd className="mt-1 text-base font-bold text-slate-800 tabular-nums dark:text-slate-100">
        {deger}
      </dd>
      {alt ? <dd className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{alt}</dd> : null}
    </div>
  )
}

/**
 * Özet şeridi (README §4.8): üç zaman AYRI gösterilir — yerel yedek, sunucu
 * dışı kopya ve sınama geri yüklemesi; biri ilerlerken diğerinin haftalardır
 * durduğu gizlenmesin. Ayrıca sıradaki planlı yedek, toplam ve boş alan.
 */
export function OzetSeridi({ ozet }: { ozet: YedekOzeti }) {
  const { t } = useTranslation()
  const bosYuzde =
    ozet.bos_alan !== null && ozet.toplam_alan !== null && ozet.toplam_alan > 0
      ? Math.round((ozet.bos_alan / ozet.toplam_alan) * 100)
      : null

  return (
    <dl className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
      <Kart
        baslik={t('yedekleme.ozet.sonYerel')}
        deger={istanbulZamani(ozet.son_yerel)}
        alt={
          ozet.sessizlik_saat === null
            ? t('yedekleme.ozet.hicYedekYok')
            : t('yedekleme.ozet.saatOnce', {
                saat: ozet.sessizlik_saat.toLocaleString('tr-TR', { maximumFractionDigits: 1 }),
              })
        }
      />
      <Kart baslik={t('yedekleme.ozet.sonUzak')} deger={istanbulZamani(ozet.son_uzak)} />
      <Kart baslik={t('yedekleme.ozet.sonSinama')} deger={istanbulZamani(ozet.son_sinama)} />
      <Kart
        baslik={t('yedekleme.ozet.siradaki')}
        deger={istanbulZamani(ozet.siradaki)}
        alt={ozet.siradaki === null ? t('yedekleme.ozet.planYok') : undefined}
      />
      <Kart
        baslik={t('yedekleme.ozet.toplam')}
        deger={boyutGoster(ozet.toplam_boyut)}
        alt={t('yedekleme.ozet.adet', { adet: ozet.toplam_adet })}
      />
      <Kart
        baslik={t('yedekleme.ozet.bosAlan')}
        deger={boyutGoster(ozet.bos_alan)}
        alt={
          bosYuzde === null
            ? undefined
            : t('yedekleme.ozet.bosAlanOrani', {
                yuzde: bosYuzde,
                toplam: boyutGoster(ozet.toplam_alan),
              })
        }
      />
    </dl>
  )
}
