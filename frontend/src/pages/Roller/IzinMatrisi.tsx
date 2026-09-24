import { useTranslation } from 'react-i18next'

import { Switch } from '@/components/Switch'
import { izinEtiketAnahtari, type EkranIzni } from '@/features/ayarlar/yetkiApi'

import { izinDegistir } from './izinKurallari'

const BASLIK_HUCRESI =
  'border-b border-slate-200 px-3 py-2 font-semibold whitespace-nowrap text-slate-700 dark:border-slate-700 dark:text-slate-200'
const GOVDE_HUCRESI = 'border-b border-slate-100 px-3 py-2 dark:border-slate-800'

/**
 * Rolün ekran izinleri: her ekran bir satır, Görüntüle / Güncelle birer
 * anahtar; ekrana özgü ek izinler (ör. e-Fatura PDF) kendi sütununda.
 */
export function IzinMatrisi({
  katalog,
  secili,
  degistir,
}: {
  katalog: EkranIzni[]
  secili: string[]
  degistir: (izinler: string[]) => void
}) {
  const { t } = useTranslation()
  const ekVar = katalog.some((ekran) => ekran.ekler.length > 0)

  const anahtar = (ekran: EkranIzni, izin: string, etiket: string, etiketGizli: boolean) => (
    <Switch
      id={`izin-${izin}`}
      label={etiket}
      etiketGizli={etiketGizli}
      vurgulu={false}
      checked={secili.includes(izin)}
      onChange={(acik) => degistir(izinDegistir(katalog, secili, ekran, izin, acik))}
    />
  )

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
      <table className="w-full border-collapse text-sm">
        <thead className="bg-slate-50 dark:bg-slate-800/60">
          <tr>
            <th scope="col" className={`${BASLIK_HUCRESI} text-left`}>
              {t('yetki.ekranBaslik')}
            </th>
            <th scope="col" className={`${BASLIK_HUCRESI} text-center`}>
              {t('yetki.goruntule')}
            </th>
            <th scope="col" className={`${BASLIK_HUCRESI} text-center`}>
              {t('yetki.guncelle')}
            </th>
            {ekVar ? (
              <th scope="col" className={`${BASLIK_HUCRESI} text-left`}>
                {t('yetki.ekIzinler')}
              </th>
            ) : null}
          </tr>
        </thead>
        <tbody>
          {katalog.map((ekran) => {
            const ekranAdi = t(`yetki.ekran.${ekran.ekran}`)

            return (
              <tr key={ekran.ekran}>
                <th
                  scope="row"
                  className={`${GOVDE_HUCRESI} text-left font-semibold whitespace-nowrap text-slate-700 dark:text-slate-200`}
                >
                  {ekranAdi}
                </th>
                <td className={GOVDE_HUCRESI}>
                  <div className="flex justify-center">
                    {anahtar(ekran, ekran.goruntule, `${ekranAdi} — ${t('yetki.goruntule')}`, true)}
                  </div>
                </td>
                <td className={GOVDE_HUCRESI}>
                  <div className="flex justify-center">
                    {ekran.guncelle
                      ? anahtar(ekran, ekran.guncelle, `${ekranAdi} — ${t('yetki.guncelle')}`, true)
                      : null}
                  </div>
                </td>
                {ekVar ? (
                  <td className={`${GOVDE_HUCRESI} min-w-64`}>
                    {ekran.ekler.map((ek) => (
                      <div key={ek}>{anahtar(ekran, ek, t(izinEtiketAnahtari(ek)), false)}</div>
                    ))}
                  </td>
                ) : null}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
