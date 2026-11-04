import { Fragment, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Input } from '@/components/Input'
import { Switch } from '@/components/Switch'
import { izinEtiketAnahtari, type EkranIzni } from '@/features/ayarlar/yetkiApi'
import { NAV_GRUPLARI } from '@/layouts/navGruplari'
import { aramaBicimi } from '@/utils/metin'

import { grupDegistir, izinDegistir, izinGruplari } from './izinKurallari'

// Başlık liste kayarken üstte kalır: zemin opak
const BASLIK_HUCRESI =
  'sticky top-0 z-10 border-b border-slate-200 bg-slate-50 px-3 py-2 font-semibold whitespace-nowrap text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200'
const GRUP_HUCRESI =
  'border-b border-slate-200 bg-slate-50/70 px-3 py-2 dark:border-slate-700 dark:bg-slate-800/40'
const GOVDE_HUCRESI = 'border-b border-slate-100 px-3 py-1.5 dark:border-slate-800'

/**
 * Rolün ekran izinleri (kullanıcı isteği 2026-11-04: form ekrana sığmıyordu):
 * ekranlar menüdeki gruplarına göre katlanır; grup satırı açık ekran sayısını
 * gösterir ve grubun tüm ekranlarını tek anahtarla açar / kapar. Ekrana özgü
 * ek izinler (ör. e-Fatura PDF) ekranın altında. Liste kendi içinde kayar:
 * formun adı ve Kaydet düğmesi görünür kalır.
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
  // Açılışta gruplar kapalı; aramada eşleşen gruplar açık gelir
  const [acikGruplar, setAcikGruplar] = useState<string[]>([])
  const [arama, setArama] = useState('')
  const seciliKume = new Set(secili)
  const aranan = aramaBicimi(arama.trim())

  const ekranAdi = (ekran: EkranIzni) => t(`yetki.ekran.${ekran.ekran}`)
  const eslesir = (ekran: EkranIzni) =>
    aranan === '' ||
    [ekranAdi(ekran), ...ekran.ekler.map((ek) => t(izinEtiketAnahtari(ek)))].some((metin) =>
      aramaBicimi(metin).includes(aranan),
    )
  const gruplar = izinGruplari(katalog, NAV_GRUPLARI)
    .map((grup) => ({ ...grup, ekranlar: grup.ekranlar.filter(eslesir) }))
    .filter((grup) => grup.ekranlar.length > 0)
  const acikEkranSayisi = katalog.filter((ekran) => seciliKume.has(ekran.goruntule)).length

  const grubuAcKapa = (baslikAnahtari: string) =>
    setAcikGruplar((onceki) =>
      onceki.includes(baslikAnahtari)
        ? onceki.filter((anahtar) => anahtar !== baslikAnahtari)
        : [...onceki, baslikAnahtari],
    )

  const anahtar = (ekran: EkranIzni, izin: string, etiket: string) => (
    <Switch
      id={`izin-${izin}`}
      label={etiket}
      etiketGizli
      vurgulu={false}
      checked={seciliKume.has(izin)}
      onChange={(acik) => degistir(izinDegistir(katalog, secili, ekran, izin, acik))}
    />
  )

  /** Grubun (aramada: görünen) ekranlarının hepsi açıksa açık görünür */
  const grupAnahtari = (
    indeks: number,
    grupAdi: string,
    ekranlar: EkranIzni[],
    duzey: 'goruntule' | 'guncelle',
  ) => {
    const kodlar = ekranlar.map((ekran) => ekran[duzey]).filter((kod) => kod !== null)

    return kodlar.length === 0 ? null : (
      <Switch
        id={`izin-grup-${indeks}-${duzey}`}
        label={t('yetki.grupDuzeyi', { grup: grupAdi, duzey: t(`yetki.${duzey}`) })}
        etiketGizli
        vurgulu={false}
        checked={kodlar.every((kod) => seciliKume.has(kod))}
        onChange={(acik) => degistir(grupDegistir(katalog, secili, ekranlar, duzey, acik))}
      />
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="w-full sm:w-72">
          <Input
            id="izin-ara"
            type="search"
            label={t('yetki.ekranAra')}
            placeholder={t('yetki.ekranAraIpucu')}
            maxLength={100}
            value={arama}
            onChange={(olay) => setArama(olay.target.value)}
            // Form içinde: Enter rolü kaydetmesin
            onKeyDown={(olay) => {
              if (olay.key === 'Enter') {
                olay.preventDefault()
              }
            }}
          />
        </div>
        <p className="text-sm text-slate-600 tabular-nums dark:text-slate-400">
          {t('yetki.acikEkranlar', { sayi: acikEkranSayisi, toplam: katalog.length })}
        </p>
      </div>

      {/* Yükseklik: formun geri kalanı (ad, arama, düğmeler) ekranda kalacak kadar */}
      <div className="max-h-[calc(100dvh-28rem)] min-h-48 overflow-auto rounded-xl border border-slate-200 dark:border-slate-700">
        <table className="w-full border-separate border-spacing-0 text-sm">
          <thead>
            <tr>
              <th scope="col" className={`${BASLIK_HUCRESI} text-left`}>
                {t('yetki.ekranBaslik')}
              </th>
              <th scope="col" className={`${BASLIK_HUCRESI} w-28 text-center`}>
                {t('yetki.goruntule')}
              </th>
              <th scope="col" className={`${BASLIK_HUCRESI} w-28 text-center`}>
                {t('yetki.guncelle')}
              </th>
            </tr>
          </thead>
          {gruplar.map((grup, indeks) => {
            const grupAdi = t(grup.baslikAnahtari)
            const acik = aranan !== '' || acikGruplar.includes(grup.baslikAnahtari)
            const acikSayisi = grup.ekranlar.filter((ekran) =>
              seciliKume.has(ekran.goruntule),
            ).length

            return (
              <tbody key={grup.baslikAnahtari}>
                <tr>
                  <th scope="rowgroup" className={`${GRUP_HUCRESI} text-left`}>
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        aria-expanded={acik}
                        disabled={aranan !== ''}
                        onClick={() => grubuAcKapa(grup.baslikAnahtari)}
                        className="flex cursor-pointer items-center gap-1.5 font-semibold whitespace-nowrap text-slate-800 disabled:cursor-default dark:text-slate-100"
                      >
                        <svg
                          aria-hidden="true"
                          viewBox="0 0 20 20"
                          fill="currentColor"
                          className={`size-4 text-slate-500 transition-transform ${acik ? 'rotate-90' : ''}`}
                        >
                          <path
                            fillRule="evenodd"
                            d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z"
                            clipRule="evenodd"
                          />
                        </svg>
                        {grupAdi}
                      </button>
                      <span className="text-xs whitespace-nowrap text-slate-500 tabular-nums dark:text-slate-400">
                        {t('yetki.acikSayisi', { sayi: acikSayisi, toplam: grup.ekranlar.length })}
                      </span>
                    </div>
                  </th>
                  <td className={GRUP_HUCRESI}>
                    <div className="flex justify-center">
                      {grupAnahtari(indeks, grupAdi, grup.ekranlar, 'goruntule')}
                    </div>
                  </td>
                  <td className={GRUP_HUCRESI}>
                    <div className="flex justify-center">
                      {grupAnahtari(indeks, grupAdi, grup.ekranlar, 'guncelle')}
                    </div>
                  </td>
                </tr>
                {acik
                  ? grup.ekranlar.map((ekran) => {
                      const ad = ekranAdi(ekran)

                      return (
                        <Fragment key={ekran.ekran}>
                          <tr>
                            <th
                              scope="row"
                              className={`${GOVDE_HUCRESI} pl-9 text-left font-normal whitespace-nowrap text-slate-700 dark:text-slate-200`}
                            >
                              {ad}
                            </th>
                            <td className={GOVDE_HUCRESI}>
                              <div className="flex justify-center">
                                {anahtar(ekran, ekran.goruntule, `${ad} — ${t('yetki.goruntule')}`)}
                              </div>
                            </td>
                            <td className={GOVDE_HUCRESI}>
                              <div className="flex justify-center">
                                {ekran.guncelle
                                  ? anahtar(ekran, ekran.guncelle, `${ad} — ${t('yetki.guncelle')}`)
                                  : null}
                              </div>
                            </td>
                          </tr>
                          {ekran.ekler.map((ek) => {
                            const etiket = t(izinEtiketAnahtari(ek))

                            return (
                              <tr key={ek}>
                                <th
                                  scope="row"
                                  className={`${GOVDE_HUCRESI} pl-14 text-left text-xs font-normal text-slate-500 dark:text-slate-400`}
                                >
                                  {etiket}
                                </th>
                                <td className={GOVDE_HUCRESI}>
                                  <div className="flex justify-center">
                                    {anahtar(ekran, ek, etiket)}
                                  </div>
                                </td>
                                <td className={GOVDE_HUCRESI} />
                              </tr>
                            )
                          })}
                        </Fragment>
                      )
                    })
                  : null}
              </tbody>
            )
          })}
        </table>
        {gruplar.length === 0 ? (
          <p className="px-3 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
            {t('yetki.eslesenEkranYok')}
          </p>
        ) : null}
      </div>
    </div>
  )
}
