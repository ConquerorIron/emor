import { useTranslation } from 'react-i18next'

import { tutarGoster } from './bicim'
import type { KesintiToplami } from './icmalApi'

const TUTARLAR = [
  'toplam_tutar',
  'iptal_edilen_tutar',
  'yansitilan_tutar',
  'kesilen_tutar',
  'kalan_tutar',
] as const

/** Para birimine göre satır sayısı ve tutar toplamları (Excel'deki ara toplam satırı) */
export function ToplamOzeti({ toplamlar }: { toplamlar: KesintiToplami[] }) {
  const { t } = useTranslation()

  return (
    <ul className="space-y-1 text-sm" aria-label={t('kesinti.icmal.toplamlar')}>
      {toplamlar.map((toplam) => (
        <li key={toplam.para_birimi} className="flex flex-wrap gap-x-4 gap-y-1">
          <strong>{toplam.para_birimi}</strong>
          <span>{t('kesinti.icmal.satirSayisi', { sayi: toplam.satir_sayisi })}</span>
          {TUTARLAR.map((alan) => (
            <span key={alan}>
              <span className="text-slate-500 dark:text-slate-400">
                {t(`kesinti.alan.${alan}`)}:
              </span>{' '}
              <span className="tabular-nums">{tutarGoster(toplam[alan])}</span>
            </span>
          ))}
        </li>
      ))}
    </ul>
  )
}
