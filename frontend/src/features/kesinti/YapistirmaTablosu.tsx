import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'
import { Pagination } from '@/components/Pagination'

import { baslikEslesmesi, bosSatirlar, panoyuCoz, type YapistirmaKolonu } from './yapistirma'

/** Gösterilen satır sayısı (2.000+ satırlık yapıştırma tek seferde çizilmez) */
const SAYFA_BOYUTU = 100

/**
 * Excel benzeri yapıştırma tablosu: Excel'den kopyalanan satırlar Ctrl+V ile
 * hücrelere yerleşir, hücreye tıklanıp düzeltilebilir. Başlık satırıyla
 * kopyalanırsa kolonlar adlarından eşlenir (Excel'deki sıra önemsiz);
 * başlıksızsa seçili hücreden başlayarak yerleşir.
 */
export function YapistirmaTablosu({
  kolonlar,
  satirlar,
  degistir,
  hataliSatirlar,
}: {
  kolonlar: YapistirmaKolonu[]
  satirlar: string[][]
  degistir: (satirlar: string[][]) => void
  /** Önizlemede hatalı çıkan satır numaraları (1'den) */
  hataliSatirlar: ReadonlySet<number>
}) {
  const { t } = useTranslation()
  // Seçili hücre: [satır, kolon] (0'dan); yapıştırma buradan başlar
  const [secili, setSecili] = useState<[number, number] | null>(null)
  const [sayfa, setSayfa] = useState(1)

  const toplamSayfa = Math.max(1, Math.ceil(satirlar.length / SAYFA_BOYUTU))
  const gecerliSayfa = Math.min(sayfa, toplamSayfa)
  const ilkSatir = (gecerliSayfa - 1) * SAYFA_BOYUTU
  const doluSatir = satirlar.filter((satir) => satir.some((hucre) => hucre.trim() !== '')).length

  const hucreyiDegistir = (satir: number, kolon: number, deger: string) => {
    degistir(satirlar.map((s, i) => (i === satir ? s.map((h, j) => (j === kolon ? deger : h)) : s)))
  }

  const yapistir = (olay: React.ClipboardEvent<HTMLDivElement>) => {
    const pano = panoyuCoz(olay.clipboardData.getData('text/plain'))

    // Tek hücrelik yapıştırma düzenlenen hücreye normal yazılır
    if (pano.length === 1 && pano[0].length === 1 && secili !== null) {
      return
    }

    olay.preventDefault()
    const eslesme = baslikEslesmesi(pano[0] ?? [], kolonlar)

    if (eslesme !== null) {
      // Başlıklı kopya: tablonun tamamı yenilenir, kolonlar adlarından eşlenir
      degistir(
        pano
          .slice(1)
          .map((satir) => eslesme.map((sira) => (sira === -1 ? '' : (satir[sira] ?? '')))),
      )
      setSecili(null)
      setSayfa(1)

      return
    }

    // Başlıksız kopya: seçili hücreden (yoksa ilk hücreden) başlayarak yerleşir
    const [satir0, kolon0] = secili ?? [0, 0]
    const yeni = satirlar.map((satir) => [...satir])

    pano.forEach((satir, i) => {
      yeni[satir0 + i] ??= Array<string>(kolonlar.length).fill('')
      satir.forEach((hucre, j) => {
        if (kolon0 + j < kolonlar.length) {
          yeni[satir0 + i][kolon0 + j] = hucre
        }
      })
    })
    degistir(yeni)
  }

  const tusaBasildi = (
    olay: React.KeyboardEvent<HTMLInputElement>,
    satir: number,
    kolon: number,
  ) => {
    if (olay.key === 'Enter') {
      olay.preventDefault()
      setSecili([Math.min(satir + 1, satirlar.length - 1), kolon])
    } else if (olay.key === 'Tab') {
      olay.preventDefault()
      setSecili([satir, Math.min(kolon + (olay.shiftKey ? -1 : 1), kolonlar.length - 1)])
    } else if (olay.key === 'Escape') {
      setSecili(null)
    }
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-600 dark:text-slate-400">
          {t('kesinti.yapistirma.satirSayisi', { sayi: doluSatir })}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          {toplamSayfa > 1 ? (
            <Pagination
              sayfa={gecerliSayfa}
              toplamSayfa={toplamSayfa}
              sayfaDegistir={setSayfa}
              boyutSecici={false}
            />
          ) : null}
          <Button
            type="button"
            variant="secondary"
            onClick={() => degistir([...satirlar, ...bosSatirlar(kolonlar.length, 10)])}
          >
            {t('kesinti.yapistirma.satirEkle')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              degistir(bosSatirlar(kolonlar.length))
              setSecili(null)
              setSayfa(1)
            }}
          >
            {t('kesinti.yapistirma.temizle')}
          </Button>
        </div>
      </div>
      {/* Yapıştırma tablonun herhangi bir yerine tıklanıp Ctrl+V ile yapılır */}
      <div
        role="grid"
        aria-label={t('kesinti.yapistirma.etiket')}
        tabIndex={0}
        onPaste={yapistir}
        className="max-h-[45vh] overflow-auto rounded-xl border border-slate-200 focus:outline-2 focus:outline-violet-500 dark:border-slate-700"
      >
        <table className="border-collapse text-xs">
          <thead className="sticky top-0 z-10 bg-slate-100 dark:bg-slate-800">
            <tr>
              <th className="sticky left-0 z-20 w-12 border-r border-b border-slate-300 bg-slate-100 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-800">
                #
              </th>
              {kolonlar.map((kolon) => (
                <th
                  key={kolon.alan}
                  scope="col"
                  className="min-w-32 border-r border-b border-slate-300 px-2 py-1.5 text-left font-semibold whitespace-nowrap dark:border-slate-600"
                >
                  {kolon.etiket}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="bg-white dark:bg-slate-900">
            {satirlar.slice(ilkSatir, ilkSatir + SAYFA_BOYUTU).map((satir, sira) => {
              const satirNo = ilkSatir + sira
              const hatali = hataliSatirlar.has(satirNo + 1)

              return (
                <tr key={satirNo} className={hatali ? 'bg-red-50 dark:bg-red-950/40' : undefined}>
                  <th
                    scope="row"
                    className={`sticky left-0 border-r border-b border-slate-200 px-2 py-1 text-right font-normal text-slate-500 tabular-nums dark:border-slate-700 ${hatali ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-slate-50 dark:bg-slate-800/60'}`}
                  >
                    {satirNo + 1}
                  </th>
                  {kolonlar.map((kolon, kolonNo) => {
                    const seciliMi = secili?.[0] === satirNo && secili[1] === kolonNo

                    return (
                      <td
                        key={kolon.alan}
                        onClick={() => setSecili([satirNo, kolonNo])}
                        className={`h-7 max-w-64 cursor-cell truncate border-r border-b border-slate-200 p-0 dark:border-slate-700 ${seciliMi ? 'outline-2 -outline-offset-2 outline-violet-500' : ''}`}
                      >
                        {seciliMi ? (
                          <input
                            // Seçilen hücre doğrudan düzenlenir
                            autoFocus
                            aria-label={`${kolon.etiket} ${satirNo + 1}`}
                            value={satir[kolonNo] ?? ''}
                            onChange={(olay) =>
                              hucreyiDegistir(satirNo, kolonNo, olay.target.value)
                            }
                            onKeyDown={(olay) => tusaBasildi(olay, satirNo, kolonNo)}
                            className="h-7 w-full bg-transparent px-2 outline-none"
                          />
                        ) : (
                          <span className="block px-2 leading-7">{satir[kolonNo]}</span>
                        )}
                      </td>
                    )
                  })}
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-slate-500 dark:text-slate-400">{t('kesinti.yapistirma.ipucu')}</p>
    </div>
  )
}
