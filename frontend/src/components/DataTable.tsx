import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

export interface TabloSiralamasi {
  anahtar: string
  yon: 'asc' | 'desc'
}

export interface DataTableKolonu<T> {
  anahtar: string
  baslik: string
  hizala?: 'sol' | 'orta' | 'sag'
  /** Doluysa başlık tıklanabilir olur ve sunucu tarafında bu anahtara göre sıralanır. */
  siralamaAnahtari?: string
  render: (satir: T) => ReactNode
}

interface DataTableProps<T> {
  kolonlar: DataTableKolonu<T>[]
  satirlar: T[]
  satirAnahtari: (satir: T) => string | number
  yukleniyor?: boolean
  siralama?: TabloSiralamasi | null
  siralamaDegistir?: (anahtar: string) => void
  /** Doluysa satır seçim kolonu açılır (toplu işlemler). Checkbox kullanılmaz (rules.md §2). */
  seciliAnahtarlar?: Set<string | number>
  secimDegistir?: (anahtar: string | number) => void
  tumunuSec?: (seciliMi: boolean) => void
  /** Satır seçilebilir mi (ör. kullanıcı kendi hesabını toplu silemez). */
  secilebilir?: (satir: T) => boolean
}

const hizaSiniflari: Record<NonNullable<DataTableKolonu<unknown>['hizala']>, string> = {
  sol: 'text-left',
  orta: 'text-center',
  sag: 'text-right',
}

/**
 * Yuvarlak seçim düğmesi — native checkbox bilinçli kullanılmıyor (rules.md §2).
 * Export düğmesi liste ekranlarında ortak kullanılır.
 */
export function SecimDugmesi({
  secili,
  etiket,
  onClick,
}: {
  secili: boolean
  etiket: string
  onClick: () => void
}) {
  return (
    <button
      type="button"
      aria-pressed={secili}
      aria-label={etiket}
      title={etiket}
      onClick={onClick}
      className={`inline-flex size-5 cursor-pointer items-center justify-center rounded-full border-2 transition-colors ${
        secili
          ? 'border-violet-600 bg-violet-600 text-white'
          : 'border-slate-300 bg-white hover:border-violet-400 dark:border-slate-600 dark:bg-slate-900'
      }`}
    >
      {secili ? (
        <svg viewBox="0 0 12 12" className="size-3" fill="none" aria-hidden="true">
          <path
            d="M2.5 6.5 5 9l4.5-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      ) : null}
    </button>
  )
}

/** ornek/dashboard datatable tasarımı: rounded-xl çerçeve, gridli başlıklar, hover satır. */
export function DataTable<T>({
  kolonlar,
  satirlar,
  satirAnahtari,
  yukleniyor = false,
  siralama = null,
  siralamaDegistir,
  seciliAnahtarlar,
  secimDegistir,
  tumunuSec,
  secilebilir,
}: DataTableProps<T>) {
  const { t } = useTranslation()

  const secimAcik = seciliAnahtarlar !== undefined && secimDegistir !== undefined
  const secilebilenler = secimAcik
    ? satirlar.filter((satir) => (secilebilir ? secilebilir(satir) : true))
    : []
  const hepsiSecili =
    secilebilenler.length > 0 &&
    secilebilenler.every((satir) => seciliAnahtarlar?.has(satirAnahtari(satir)))
  const kolonSayisi = kolonlar.length + (secimAcik ? 1 : 0)

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
      <table className="w-full border-collapse text-sm">
        <thead className="bg-slate-50 dark:bg-slate-800/60">
          <tr>
            {secimAcik ? (
              <th className="w-10 border-r border-b border-slate-200 px-3 py-2 text-center dark:border-slate-700">
                {tumunuSec ? (
                  <SecimDugmesi
                    secili={hepsiSecili}
                    etiket={t('ortak.tumunuSec')}
                    onClick={() => tumunuSec(!hepsiSecili)}
                  />
                ) : null}
              </th>
            ) : null}
            {kolonlar.map((kolon) => {
              const siralanabilir = kolon.siralamaAnahtari !== undefined && siralamaDegistir
              const aktif = siralama?.anahtar === kolon.siralamaAnahtari

              return (
                <th
                  key={kolon.anahtar}
                  aria-sort={
                    aktif ? (siralama?.yon === 'asc' ? 'ascending' : 'descending') : undefined
                  }
                  className={`border-r border-b border-slate-200 px-3 py-2 font-semibold whitespace-nowrap text-slate-700 last:border-r-0 dark:border-slate-700 dark:text-slate-200 ${hizaSiniflari[kolon.hizala ?? 'sol']}`}
                >
                  {siralanabilir ? (
                    <button
                      type="button"
                      onClick={() => siralamaDegistir(kolon.siralamaAnahtari ?? '')}
                      className="inline-flex cursor-pointer items-center gap-1 hover:text-blue-600 dark:hover:text-blue-400"
                    >
                      {kolon.baslik}
                      <span aria-hidden="true" className={aktif ? '' : 'opacity-30'}>
                        {aktif ? (siralama?.yon === 'asc' ? '▲' : '▼') : '↕'}
                      </span>
                    </button>
                  ) : (
                    kolon.baslik
                  )}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody className="bg-white dark:bg-slate-900">
          {yukleniyor ? (
            <tr>
              <td
                colSpan={kolonSayisi}
                className="px-4 py-10 text-center text-slate-500 dark:text-slate-400"
              >
                {t('ortak.yukleniyor')}
              </td>
            </tr>
          ) : null}
          {!yukleniyor && satirlar.length === 0 ? (
            <tr>
              <td
                colSpan={kolonSayisi}
                className="px-4 py-10 text-center text-slate-500 dark:text-slate-400"
              >
                {t('ortak.kayitBulunamadi')}
              </td>
            </tr>
          ) : null}
          {!yukleniyor &&
            satirlar.map((satir) => {
              const anahtar = satirAnahtari(satir)
              const satirSecilebilir = secilebilir ? secilebilir(satir) : true

              return (
                <tr
                  key={anahtar}
                  className={`transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/50 ${
                    seciliAnahtarlar?.has(anahtar) ? 'bg-violet-50 dark:bg-violet-950/30' : ''
                  }`}
                >
                  {secimAcik ? (
                    <td className="w-10 border-r border-b border-slate-100 px-3 py-2 text-center dark:border-slate-800">
                      {satirSecilebilir ? (
                        <SecimDugmesi
                          secili={seciliAnahtarlar.has(anahtar)}
                          etiket={t('ortak.satirSec')}
                          onClick={() => secimDegistir(anahtar)}
                        />
                      ) : null}
                    </td>
                  ) : null}
                  {kolonlar.map((kolon) => (
                    <td
                      key={kolon.anahtar}
                      className={`border-r border-b border-slate-100 px-3 py-2 last:border-r-0 dark:border-slate-800 ${hizaSiniflari[kolon.hizala ?? 'sol']}`}
                    >
                      {kolon.render(satir)}
                    </td>
                  ))}
                </tr>
              )
            })}
        </tbody>
      </table>
    </div>
  )
}
