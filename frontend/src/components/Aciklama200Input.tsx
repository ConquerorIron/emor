/** ERP ACIKLAMA200 domain tipi — proc'lardaki ACIKLAMA200 parametrelerinin limiti */
export const ACIKLAMA200_LIMIT = 200

interface Aciklama200InputProps {
  id: string
  label: string
  value: string
  onChange: (deger: string) => void
  hata?: string
  rows?: number
  /** Salt okunur (ekran tasarımı) */
  disabled?: boolean
}

/**
 * ERP ACIKLAMA200 alanı girişi (ör. SOHOM_SIPARIS_KAYDET @ACIKLAMA):
 * 200 karakteri aşan giriş/yapıştırma sessizce kırpılır (maxLength +
 * programatik güvence) ve sağ üstte canlı sayaç gösterilir. Açıklama tipli
 * her proc parametresi bu bileşenle girilir.
 */
export function Aciklama200Input({
  id,
  label,
  value,
  onChange,
  hata,
  rows = 2,
  disabled = false,
}: Aciklama200InputProps) {
  const limitte = value.length >= ACIKLAMA200_LIMIT

  return (
    <div>
      <div className="flex items-end justify-between gap-2">
        <label
          htmlFor={id}
          className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
        >
          {label}
        </label>
        <span
          className={`text-xs tabular-nums ${
            limitte
              ? 'font-semibold text-amber-600 dark:text-amber-400'
              : 'text-slate-400 dark:text-slate-500'
          }`}
        >
          {value.length}/{ACIKLAMA200_LIMIT}
        </span>
      </div>
      <textarea
        id={id}
        rows={rows}
        maxLength={ACIKLAMA200_LIMIT}
        value={value}
        disabled={disabled}
        onChange={(olay) => onChange(olay.target.value.slice(0, ACIKLAMA200_LIMIT))}
        aria-invalid={hata ? true : undefined}
        className={`mt-1 block w-full rounded-xl border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none placeholder:text-slate-400 focus:ring-2 dark:bg-slate-950 dark:text-slate-100 ${
          hata
            ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
            : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
        }`}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
