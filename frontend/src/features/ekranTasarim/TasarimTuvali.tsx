import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { bolumGenisligiSinifi, genislikSinifi } from './types'
import type { BolumTanimi, DuzenAlani, EkranDuzeni, KatalogAlani } from './types'

/** Sürüklenen öğe — palete mi tuvale mi ait olduğu bırakma anında gerekir. */
export interface SurukleVerisi {
  alan: string
  kaynak: 'palet' | 'tuval'
}

export const SURUKLE_TURU = 'application/x-erp-alan'

interface TasarimTuvaliProps {
  duzen: EkranDuzeni
  bolumTanimlari: BolumTanimi[]
  katalogHaritasi: Map<string, KatalogAlani>
  seciliAlan: string | null
  alanSecildi: (alan: string | null) => void
  /** hedefIndeks null → bölümün sonuna */
  birakildi: (alan: string, bolumAnahtari: string, hedefIndeks: number | null) => void
  bolumGenisligiDegisti: (bolumAnahtari: string, genislik: number) => void
}

const BOLUM_GENISLIKLERI = [3, 4, 6, 8, 9, 12]

export function TasarimTuvali({
  duzen,
  bolumTanimlari,
  katalogHaritasi,
  seciliAlan,
  alanSecildi,
  birakildi,
  bolumGenisligiDegisti,
}: TasarimTuvaliProps) {
  const { t } = useTranslation()
  // Bırakma göstergesi: hangi bölümün kaçıncı sırasına düşecek
  const [hedef, setHedef] = useState<{ bolum: string; indeks: number | null } | null>(null)

  const baslikAnahtari = (anahtar: string) =>
    bolumTanimlari.find((b) => b.anahtar === anahtar)?.baslik_anahtari ?? anahtar

  const surukleBitti = () => setHedef(null)

  const birakmayiIsle = (olay: React.DragEvent, bolum: string, indeks: number | null) => {
    olay.preventDefault()
    olay.stopPropagation()
    setHedef(null)

    const ham = olay.dataTransfer.getData(SURUKLE_TURU) || olay.dataTransfer.getData('text/plain')
    if (!ham) {
      return
    }
    try {
      const veri = JSON.parse(ham) as SurukleVerisi
      birakildi(veri.alan, bolum, indeks)
    } catch {
      // Dışarıdan sürüklenen içerik — yok say
    }
  }

  return (
    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-12">
      {duzen.bolumler.map((bolum) => (
        <section
          key={bolum.anahtar}
          className={`rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 ${bolumGenisligiSinifi(bolum.genislik)}`}
          onDragOver={(olay) => {
            olay.preventDefault()
            setHedef({ bolum: bolum.anahtar, indeks: null })
          }}
          onDrop={(olay) => birakmayiIsle(olay, bolum.anahtar, null)}
        >
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-2 dark:border-slate-700">
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
              {t(baslikAnahtari(bolum.anahtar))}
            </h3>
            <div className="flex items-center gap-1">
              <span className="text-xs text-slate-400 dark:text-slate-500">
                {t('tasarim.bolumGenisligi')}
              </span>
              {BOLUM_GENISLIKLERI.map((genislik) => (
                <button
                  key={genislik}
                  type="button"
                  aria-label={`${t('tasarim.bolumGenisligiEtiketi')} ${genislik}`}
                  aria-pressed={bolum.genislik === genislik}
                  onClick={() => bolumGenisligiDegisti(bolum.anahtar, genislik)}
                  className={`h-6 min-w-7 cursor-pointer rounded border px-1 text-xs font-semibold transition-colors ${
                    bolum.genislik === genislik
                      ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300'
                      : 'border-slate-300 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-800'
                  }`}
                >
                  {genislik}
                </button>
              ))}
            </div>
          </div>

          <div className="grid min-h-24 grid-cols-1 gap-2 sm:grid-cols-12">
            {bolum.alanlar.map((alan, indeks) => (
              <AlanKarti
                key={alan.alan}
                alan={alan}
                katalog={katalogHaritasi.get(alan.alan)}
                secili={seciliAlan === alan.alan}
                hedefGosterge={hedef?.bolum === bolum.anahtar && hedef.indeks === indeks}
                sec={() => alanSecildi(alan.alan)}
                surukleBasladi={(olay) => {
                  const veri: SurukleVerisi = { alan: alan.alan, kaynak: 'tuval' }
                  olay.dataTransfer.setData(SURUKLE_TURU, JSON.stringify(veri))
                  olay.dataTransfer.setData('text/plain', JSON.stringify(veri))
                  olay.dataTransfer.effectAllowed = 'move'
                }}
                surukleBitti={surukleBitti}
                uzerindeyken={(olay) => {
                  olay.preventDefault()
                  olay.stopPropagation()
                  setHedef({ bolum: bolum.anahtar, indeks })
                }}
                birak={(olay) => birakmayiIsle(olay, bolum.anahtar, indeks)}
              />
            ))}

            {/* Sona bırakma alanı — bölüm boşken de hedef olur */}
            <div
              className={`col-span-full flex h-10 items-center justify-center rounded-lg border border-dashed text-xs transition-colors ${
                hedef?.bolum === bolum.anahtar && hedef.indeks === null
                  ? 'border-blue-500 bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300'
                  : 'border-slate-300 text-slate-400 dark:border-slate-700 dark:text-slate-500'
              }`}
            >
              {t('tasarim.burayaBirak')}
            </div>
          </div>
        </section>
      ))}
    </div>
  )
}

function AlanKarti({
  alan,
  katalog,
  secili,
  hedefGosterge,
  sec,
  surukleBasladi,
  surukleBitti,
  uzerindeyken,
  birak,
}: {
  alan: DuzenAlani
  katalog: KatalogAlani | undefined
  secili: boolean
  hedefGosterge: boolean
  sec: () => void
  surukleBasladi: (olay: React.DragEvent) => void
  surukleBitti: () => void
  uzerindeyken: (olay: React.DragEvent) => void
  birak: (olay: React.DragEvent) => void
}) {
  const { t } = useTranslation()

  return (
    <div
      draggable
      onDragStart={surukleBasladi}
      onDragEnd={surukleBitti}
      onDragOver={uzerindeyken}
      onDrop={birak}
      onClick={sec}
      className={`${genislikSinifi(alan.genislik)} cursor-grab rounded-lg border-2 px-3 py-2 transition-colors active:cursor-grabbing ${
        hedefGosterge ? 'border-l-4 border-l-blue-500' : ''
      } ${
        secili
          ? 'border-blue-600 bg-blue-50 dark:bg-blue-950'
          : 'border-slate-200 bg-slate-50 hover:border-slate-300 dark:border-slate-700 dark:bg-slate-800'
      } ${alan.gizli ? 'opacity-60' : ''}`}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="truncate text-sm font-semibold text-slate-700 dark:text-slate-200">
          {alan.gizli ? '👁️‍🗨️ ' : ''}
          {katalog ? t(katalog.etiket_anahtari) : alan.alan}
          {alan.zorunlu ? <span className="text-red-600 dark:text-red-400"> *</span> : null}
        </span>
        <span className="shrink-0 rounded bg-slate-200 px-1.5 text-xs font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
          {alan.genislik}
        </span>
      </div>
      <div className="mt-0.5 flex gap-2 text-xs text-slate-400 dark:text-slate-500">
        {alan.gizli ? <span>{t('tasarim.rozetGizli')}</span> : null}
        {alan.salt_okunur ? <span>{t('tasarim.rozetSaltOkunur')}</span> : null}
        {alan.gorunum === 'textarea' ? <span>{t('tasarim.rozetTextarea')}</span> : null}
        {alan.varsayilan ? <span>{t('tasarim.rozetVarsayilan')}</span> : null}
      </div>
    </div>
  )
}
