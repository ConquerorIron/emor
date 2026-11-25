import { useTranslation } from 'react-i18next'

import { zamanGoster } from '@/utils/tarih'

import type { FaturaRed } from './efaturaApi'

/**
 * RED fatura: "İşlendi" anahtarı yerine ve SAP kolonunda yalnız "Red" yazar.
 * Kaynakta RED (entegratör, liste) ile yetkili kullanıcının çektiği RED
 * ayrılır; harici RED'de üzerine gelince kim, ne zaman, neden görünür.
 */
export function RedRozeti({ red }: { red: FaturaRed }) {
  const { t } = useTranslation()

  const aciklama =
    red.kaynak === 'harici'
      ? t('efatura.red.hariciAciklama', {
          eden: red.eden ?? '—',
          zaman: zamanGoster(red.zaman),
          neden: red.neden ?? '—',
        })
      : t('efatura.red.kaynakAciklama')

  return (
    <span
      title={aciklama}
      className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-red-700 dark:bg-red-950 dark:text-red-300"
    >
      {t(red.kaynak === 'harici' ? 'efatura.red.harici' : 'efatura.sapRed')}
    </span>
  )
}
