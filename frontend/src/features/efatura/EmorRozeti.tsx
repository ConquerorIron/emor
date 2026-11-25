import { useTranslation } from 'react-i18next'

import type { EmorDurumu } from './efaturaApi'

const EMOR_ROZETLERI: Record<Exclude<EmorDurumu, 'yok'>, string> = {
  islendi: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
  elle_islendi: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
  havuzda: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
}

/**
 * eMOR: İşlendi (muhasebeleşmiş) yeşil, elle işlendi (ETTN'siz, no + VKN ile)
 * mavi, Havuzda (ERP almış, muhasebeleşmemiş) sarı; ERP'de yoksa boş; henüz
 * kontrol edilmediyse (null) soluk tire. İzibiz, Tüm Faturalar, Protel ve
 * statik kaynak ekranları ortak kullanır.
 */
export function EmorRozeti({ deger }: { deger: EmorDurumu | null }) {
  const { t } = useTranslation()

  if (deger === null) {
    return (
      <span className="text-slate-400" title={t('efatura.emor.bilinmiyor')}>
        —
      </span>
    )
  }

  return deger === 'yok' ? null : (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap ${EMOR_ROZETLERI[deger]}`}
      title={t(`efatura.emor.aciklama.${deger}`)}
    >
      {t(`efatura.emor.${deger}`)}
    </span>
  )
}
