interface SinirliMetinInputProps {
  id: string
  label: string
  value: string
  onChange: (deger: string) => void
  /** ERP domain tipinin karakter sınırı (ör. ACIKLAMA200 → 200) */
  limit: number
  hata?: string
  rows?: number
  disabled?: boolean
}

/**
 * Karakter sınırlı metin girişi — ERP'nin ACIKLAMA200 / ACIKLAMA3072 gibi
 * domain tiplerinin karşılığı. Sınırı aşan giriş/yapıştırma sessizce kırpılır
 * (maxLength + programatik güvence) ve sağ üstte canlı sayaç gösterilir.
 */
export function SinirliMetinInput({
  id,
  label,
  value,
  onChange,
  limit,
  hata,
  rows = 2,
  disabled = false,
}: SinirliMetinInputProps) {
  const limitte = value.length >= limit

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
          {value.length}/{limit}
        </span>
      </div>
      <textarea
        id={id}
        rows={rows}
        maxLength={limit}
        value={value}
        disabled={disabled}
        onChange={(olay) => onChange(olay.target.value.slice(0, limit))}
        aria-invalid={hata ? true : undefined}
        className={`mt-1 block w-full rounded-xl border bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none placeholder:text-slate-400 focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900 ${
          hata
            ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
            : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
        }`}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
