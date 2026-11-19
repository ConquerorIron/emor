import { useTranslation } from 'react-i18next'

import { tutarGoster } from './bicim'
import type { ParaBirimiOzeti } from './efaturaApi'

/* e-Fatura liste ekranlarının (İzibiz, Protel) ortak küçük parçaları. */

export function OzetKartlari({ ozet, toplam }: { ozet: ParaBirimiOzeti[]; toplam: number }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-wrap gap-3" aria-label={t('efatura.ozetBaslik')}>
      <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
        <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
          {t('efatura.toplamFatura')}
        </p>
        <p className="text-lg font-bold">{toplam.toLocaleString('tr-TR')}</p>
      </div>
      {ozet.map((grup) => (
        <div
          key={grup.para_birimi}
          className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"
        >
          <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
            {t('efatura.ozetGrup', { para: grup.para_birimi, adet: grup.adet })}
          </p>
          <p className="text-lg font-bold tabular-nums">
            {tutarGoster(grup.tutar)} {grup.para_birimi}
          </p>
          <p className="text-xs text-slate-500 tabular-nums dark:text-slate-400">
            {t('efatura.ozetVergi', { tutar: tutarGoster(grup.vergi_tutari) })}
          </p>
        </div>
      ))}
    </div>
  )
}

/** Tablo hücresi metni: boşsa "—", uzunsa kısaltılır, tamamı üzerine gelince görünür. */
export function Metin({ deger, genis = false }: { deger: string | null; genis?: boolean }) {
  if (deger === null || deger === '') {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span className={`block truncate ${genis ? 'max-w-xs' : 'max-w-[12rem]'}`} title={deger}>
      {deger}
    </span>
  )
}

export function EvetHayirRozeti({ deger }: { deger: boolean | null }) {
  const { t } = useTranslation()

  if (deger === null) {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
        deger
          ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
          : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
      }`}
    >
      {deger ? t('efatura.evet') : t('efatura.hayir')}
    </span>
  )
}
