import { useTranslation } from 'react-i18next'

/**
 * Ekranın yalnız görüntüleme yetkisiyle açıldığını bildiren not — güncelleme
 * izni olmayan kullanıcıya düğmelerin neden gizli/pasif olduğunu açıklar.
 */
export function SaltOkunurUyarisi() {
  const { t } = useTranslation()

  return (
    <p
      role="note"
      className="mt-4 rounded-xl border border-blue-200 bg-blue-50 px-5 py-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200"
    >
      {t('ortak.saltOkunur')}
    </p>
  )
}
