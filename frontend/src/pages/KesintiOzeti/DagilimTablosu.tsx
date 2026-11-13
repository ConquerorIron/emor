import { useTranslation } from 'react-i18next'

import { tutarVeyaTire } from '@/features/kesinti/bicim'

import type { OzetGrubu, OzetTutari, OzetTutarlari } from './kesintiOzetiApi'

export interface DagilimKolonu {
  alan: OzetTutari
  /** i18n: kesinti.ozet.kolon.<baslik> */
  baslik: string
}

/** Kolonların üstündeki grup başlığı (GENEL / HAB / TAŞERON) */
export interface KolonGrubu {
  /** i18n: kesinti.ozet.<baslik> */
  baslik: 'genel' | 'hab' | 'taseron'
  kolonlar: DagilimKolonu[]
}

/**
 * Lacivert şeritli dağılım tablosu (kullanıcının örnek raporu): grup başlıkları,
 * kolon başlıkları, satırlar (kod; adı üzerine gelince) ve verildiyse TOPLAM.
 */
export function DagilimTablosu({
  baslik,
  ilkKolon,
  gruplar,
  satirlar,
  toplam,
}: {
  baslik: string
  ilkKolon: string
  gruplar: KolonGrubu[]
  satirlar: OzetGrubu[]
  /** Kısmi listede (en yüksek 10 taşeron) toplam satırı yok */
  toplam?: OzetTutarlari
}) {
  const { t } = useTranslation()
  const kolonlar = gruplar.flatMap((grup) => grup.kolonlar)
  const tutarHucresi = 'px-3 py-1.5 text-right whitespace-nowrap tabular-nums'

  return (
    <div className="mt-4">
      <h5 className="bg-slate-800 px-3 py-2 text-xs font-bold tracking-wide text-white dark:bg-slate-700">
        {baslik}
      </h5>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[40rem] border-collapse text-sm">
          <thead>
            <tr>
              <td />
              {gruplar.map((grup) => (
                <th
                  key={grup.baslik}
                  scope="colgroup"
                  colSpan={grup.kolonlar.length}
                  className="border border-slate-300 px-3 py-1 text-center text-xs font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                >
                  {t(`kesinti.ozet.${grup.baslik}`)}
                </th>
              ))}
            </tr>
            <tr className="border-b-2 border-slate-800 dark:border-slate-400">
              <th
                scope="col"
                className="px-3 pt-3 pb-1.5 text-left text-xs font-semibold text-slate-500 dark:text-slate-400"
              >
                {ilkKolon}
              </th>
              {kolonlar.map((kolon) => (
                <th
                  key={kolon.alan}
                  scope="col"
                  className="px-3 pt-3 pb-1.5 text-right text-xs font-semibold whitespace-nowrap text-slate-500 dark:text-slate-400"
                >
                  {t(`kesinti.ozet.kolon.${kolon.baslik}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {satirlar.length === 0 ? (
              <tr>
                <td
                  colSpan={kolonlar.length + 1}
                  className="px-3 py-4 text-center text-slate-500 dark:text-slate-400"
                >
                  {t('kesinti.ozet.kayitYok')}
                </td>
              </tr>
            ) : (
              satirlar.map((satir) => (
                <tr key={satir.kod} className="border-b border-slate-200 dark:border-slate-700">
                  <th
                    scope="row"
                    title={satir.ad !== satir.kod ? satir.ad : undefined}
                    className="px-3 py-1.5 text-left font-bold whitespace-nowrap text-slate-800 dark:text-slate-100"
                  >
                    {satir.kod}
                  </th>
                  {kolonlar.map((kolon) => (
                    <td key={kolon.alan} className={tutarHucresi}>
                      {tutarVeyaTire(satir[kolon.alan])}
                    </td>
                  ))}
                </tr>
              ))
            )}
          </tbody>
          {toplam ? (
            <tfoot>
              <tr className="bg-slate-800 font-bold text-white dark:bg-slate-700">
                <th scope="row" className="px-3 py-2 text-left">
                  {t('kesinti.ozet.toplam')}
                </th>
                {kolonlar.map((kolon) => (
                  <td key={kolon.alan} className={tutarHucresi}>
                    {tutarVeyaTire(toplam[kolon.alan])}
                  </td>
                ))}
              </tr>
            </tfoot>
          ) : null}
        </table>
      </div>
    </div>
  )
}
