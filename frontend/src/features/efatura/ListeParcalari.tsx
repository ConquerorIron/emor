import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import { tutarGoster } from './bicim'
import type { ParaBirimiOzeti } from './efaturaApi'

/* e-Fatura liste ekranlarının (İzibiz, Protel) ortak küçük parçaları. */

export function OzetKartlari({ ozet, toplam }: { ozet: ParaBirimiOzeti[]; toplam: number }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-wrap gap-3" aria-label={t('efatura.ozetBaslik')}>
      <div className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
        <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
          {t('efatura.toplamFatura')}
        </p>
        <p className="text-lg font-bold">{toplam.toLocaleString('tr-TR')}</p>
      </div>
      {ozet.map((grup) => (
        <div
          key={grup.para_birimi}
          className="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900"
        >
          <p className="text-xs font-semibold text-slate-500 dark:text-slate-400">
            {t('efatura.ozetGrup', { para: grup.para_birimi, adet: grup.adet })}
          </p>
          <p className="text-lg font-bold tabular-nums">
            {tutarGoster(grup.tutar)} {grup.para_birimi}
          </p>
          <p className="text-xs text-slate-500 tabular-nums dark:text-slate-400">
            {t('efatura.ozetVergi', { tutar: tutarGoster(grup.vergi_tutari) })}
          </p>
        </div>
      ))}
    </div>
  )
}

/** Tablo hücresi metni: boşsa "—", uzunsa kısaltılır, tamamı üzerine gelince görünür. */
export function Metin({ deger, genis = false }: { deger: string | null; genis?: boolean }) {
  if (deger === null || deger === '') {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span className={`block truncate ${genis ? 'max-w-xs' : 'max-w-[12rem]'}`} title={deger}>
      {deger}
    </span>
  )
}

export function EvetHayirRozeti({ deger }: { deger: boolean | null }) {
  const { t } = useTranslation()

  if (deger === null) {
    return <span className="text-slate-400">—</span>
  }

  return (
    <span
      className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
        deger
          ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
          : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
      }`}
    >
      {deger ? t('efatura.evet') : t('efatura.hayir')}
    </span>
  )
}

/** Sıra No hücresi: sayfalar boyunca kesintisiz (sunucunun `meta.from` değerinden) */
export function SiraNo({ deger }: { deger: number }) {
  return <span className="text-slate-500 tabular-nums dark:text-slate-400">{deger}</span>
}

/**
 * Tablo işlem düğmesi: yalnız ikon (satır yüksekliği küçük kalır); erişilebilir
 * ad ve üzerine gelince ipucu etiketten.
 */
function IkonDugmesi({
  etiket,
  onClick,
  children,
}: {
  etiket: string
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      aria-label={etiket}
      title={etiket}
      onClick={onClick}
      className="inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-blue-600 dark:hover:bg-slate-800"
    >
      {children}
    </button>
  )
}

/** Fatura detayı: büyüteçli belge ikonu */
export function DetayDugmesi({ onClick }: { onClick: () => void }) {
  const { t } = useTranslation()

  return (
    <IkonDugmesi etiket={t('efatura.detay')} onClick={onClick}>
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        className="size-5 text-slate-600 dark:text-slate-300"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.6}
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v16.5c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Zm3.75 11.625a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"
        />
      </svg>
    </IkonDugmesi>
  )
}

/**
 * Fatura aslı (PDF): klasik PDF dosyası ikonu — kıvrık köşeli sayfa, kırmızı
 * "PDF" şeridi ve kırmızı kıvrım (kullanıcının örneğine benzer; marka logosu
 * kopyalanmadı). Satır içi SVG: harici dosya yok, her boyutta net.
 */
export function PdfDugmesi({ onClick }: { onClick: () => void }) {
  const { t } = useTranslation()

  return (
    <IkonDugmesi etiket={t('efatura.pdf')} onClick={onClick}>
      <svg aria-hidden="true" viewBox="0 0 24 24" className="size-6">
        {/* Sayfa ve kıvrık köşe */}
        <path
          d="M5.5 2h9.5l5 5v14.25a.75.75 0 0 1-.75.75H5.5a.75.75 0 0 1-.75-.75V2.75A.75.75 0 0 1 5.5 2Z"
          fill="#fafafa"
          stroke="#9ca3af"
          strokeWidth={0.8}
        />
        <path
          d="M15 2v5h5Z"
          fill="#d1d5db"
          stroke="#9ca3af"
          strokeWidth={0.8}
          strokeLinejoin="round"
        />
        {/* Kırmızı PDF şeridi (sayfanın soluna taşar) */}
        <rect x="2.5" y="7.5" width="14.5" height="5.6" rx="0.5" fill="#c62828" />
        <text
          x="9.75"
          y="11.85"
          textAnchor="middle"
          fontSize="4.6"
          fontWeight={700}
          fill="#fff"
          fontFamily="Arial, Helvetica, sans-serif"
        >
          PDF
        </text>
        {/* Kırmızı kıvrım */}
        <path
          d="M8.4 20.2c1.3-1.1 2.7-3 3.2-4.9.2-.8-.6-1-.8-.3-.4 1.6 1.9 3.6 4.9 3.9.9.1.9.8 0 .8-2.6-.2-5.4.1-7.3.5"
          fill="none"
          stroke="#c62828"
          strokeWidth={0.9}
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </IkonDugmesi>
  )
}
