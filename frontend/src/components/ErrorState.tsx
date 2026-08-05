import { useTranslation } from 'react-i18next'

import { Button } from './Button'

interface ErrorStateProps {
  mesaj: string
  tekrarDene?: () => void
}

/** Liste/veri yükleme hataları için zorunlu hata durumu bileşeni (rules.md §2). */
export function ErrorState({ mesaj, tekrarDene }: ErrorStateProps) {
  const { t } = useTranslation()

  return (
    <div
      role="alert"
      className="rounded-xl border border-red-300 bg-red-50 px-4 py-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300"
    >
      <p>{mesaj}</p>
      {tekrarDene ? (
        <Button variant="secondary" className="mt-3" onClick={tekrarDene}>
          {t('ortak.tekrarDene')}
        </Button>
      ) : null}
    </div>
  )
}
