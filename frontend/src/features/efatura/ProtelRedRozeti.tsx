import { useTranslation } from 'react-i18next'

/**
 * Protel portalında reddedilmiş fatura (kullanıcı isteği 2026-11-24): "İşlendi"
 * anahtarı yerine ve Tüm Faturalar'ın SAP kolonunda yalnız "Red" yazar.
 * Durum senkronla gelir; red kendiliğinden görünür.
 */
export function ProtelRedRozeti() {
  const { t } = useTranslation()

  return (
    <span
      title={t('efatura.protelRedAciklama')}
      className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-red-700 dark:bg-red-950 dark:text-red-300"
    >
      {t('efatura.sapRed')}
    </span>
  )
}
