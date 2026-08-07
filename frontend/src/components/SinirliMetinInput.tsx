import {
  ALAN_ETIKET_SATIRI,
  ALAN_GORUNUMU,
  ALAN_KUTUSU_ESNEK,
  alanCercevesi,
} from '@/components/alanStilleri'

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
      <div className={`${ALAN_ETIKET_SATIRI} justify-between gap-2`}>
        <label htmlFor={id} className="text-sm font-semibold text-slate-700 dark:text-slate-300">
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
        className={`mt-1 block w-full px-3 py-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU_ESNEK} ${alanCercevesi(hata)}`}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
