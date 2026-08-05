import { useTranslation } from 'react-i18next'

const diller = [
  { kod: 'tr', etiket: 'TR' },
  { kod: 'en', etiket: 'EN' },
] as const

export function LanguageSwitcher() {
  const { i18n } = useTranslation()

  const dilDegistir = (kod: string): void => {
    void i18n.changeLanguage(kod)
    // Dil tercihi şimdilik localStorage'da; AUTH-8 sonrası kullanıcı profiline taşınacak
    localStorage.setItem('locale', kod)
  }

  return (
    <div className="flex gap-1" role="group" aria-label="Language">
      {diller.map((dil) => (
        <button
          key={dil.kod}
          type="button"
          onClick={() => dilDegistir(dil.kod)}
          className={`rounded px-2 py-1 text-sm font-semibold transition-colors ${
            i18n.language === dil.kod
              ? 'bg-blue-600 text-white'
              : 'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800'
          }`}
        >
          {dil.etiket}
        </button>
      ))}
    </div>
  )
}
