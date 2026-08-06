/**
 * ERP MIKTAR / ORAN tipleri için sayı girişi. Türkçe biçim: ondalık ayracı
 * virgül. Değer forma NOKTALI (makine okunur) biçimde yazılır ki proc'a ve
 * SQL'e dönüştürmeden gidebilsin.
 */

interface SayiAlaniProps {
  id: string
  label: string
  /** Noktalı biçim ("12.5"); boş bırakılabilir */
  value: string
  onChange: (deger: string) => void
  hata?: string
  disabled?: boolean
  /** Yüzde alanları için sonda % gösterilir (ERP ORAN tipi) */
  yuzde?: boolean
  /** İzin verilen ondalık basamak (MIKTAR 4, ORAN 2) */
  ondalik?: number
}

/** Kullanıcı virgül yazar, veri nokta tutar. */
function gosterime(deger: string): string {
  return deger.replace('.', ',')
}

function veriye(metin: string, ondalik: number): string {
  // Yalnız rakam, virgül/nokta ve baştaki eksi
  let temiz = metin.replace(/[^\d,.-]/g, '').replace(/(?!^)-/g, '')
  temiz = temiz.replace(/,/g, '.')

  // Birden fazla ondalık ayracı olmasın
  const ilk = temiz.indexOf('.')
  if (ilk !== -1) {
    temiz = temiz.slice(0, ilk + 1) + temiz.slice(ilk + 1).replace(/\./g, '')
    const [tam, kesir = ''] = temiz.split('.')
    temiz = ondalik === 0 ? tam : `${tam}.${kesir.slice(0, ondalik)}`
  }

  return temiz
}

export function SayiAlani({
  id,
  label,
  value,
  onChange,
  hata,
  disabled = false,
  yuzde = false,
  ondalik = 4,
}: SayiAlaniProps) {
  return (
    <div>
      <label
        htmlFor={id}
        className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
      >
        {label}
      </label>
      <div className="relative mt-1">
        <input
          id={id}
          inputMode="decimal"
          autoComplete="off"
          disabled={disabled}
          value={gosterime(value)}
          onChange={(olay) => onChange(veriye(olay.target.value, ondalik))}
          aria-invalid={hata ? true : undefined}
          className={`block h-[42px] w-full rounded-xl border bg-white py-2 pl-3 text-right text-sm text-slate-900 shadow-sm transition-colors outline-none focus:ring-2 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900 ${
            yuzde ? 'pr-8' : 'pr-3'
          } ${
            hata
              ? 'border-red-500 focus:border-red-500 focus:ring-red-200 dark:focus:ring-red-900'
              : 'border-slate-300 focus:border-blue-600 focus:ring-blue-200 dark:border-slate-700 dark:focus:ring-blue-900'
          }`}
        />
        {yuzde ? (
          <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-slate-400 dark:text-slate-500">
            %
          </span>
        ) : null}
      </div>
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
