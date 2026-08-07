import { ALAN_ETIKETI, ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'
import { Switch } from '@/components/Switch'

/**
 * ERP BOOL tipi. Değer forma '0'/'1' olarak yazılır — proc'a ve SQL bit
 * alanına dönüştürmeden gider (diğer alanlarla aynı metin sözleşmesi).
 *
 * Anahtar, diğer alanlarla aynı ölçüdeki bir kutunun içinde durur: çıplak
 * anahtar komşu alanlardan kısa kalıyor ve satırı bozuyordu.
 */
interface EvetHayirAlaniProps {
  id: string
  label: string
  /** '1' = açık, diğer her şey kapalı */
  value: string
  onChange: (deger: string) => void
  hata?: string
  disabled?: boolean
  /** Izgara hücrelerinde etiket thead'de durur */
  etiketGizli?: boolean
}

export function EvetHayirAlani({
  id,
  label,
  value,
  onChange,
  hata,
  disabled = false,
  etiketGizli = false,
}: EvetHayirAlaniProps) {
  return (
    <div>
      <label htmlFor={id} className={etiketGizli ? 'sr-only' : ALAN_ETIKETI}>
        {label}
      </label>
      <div
        className={`flex w-full items-center px-3 ${etiketGizli ? '' : 'mt-1'} ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)} ${
          // Kutu bir <div>; disabled: varyantları işlemediği için pasif
          // görünüm elle verilir
          disabled ? 'cursor-not-allowed bg-slate-50 opacity-60 dark:bg-slate-900' : ''
        }`}
      >
        <Switch
          id={id}
          label={label}
          etiketGizli
          checked={value === '1'}
          onChange={(acik) => onChange(acik ? '1' : '0')}
          disabled={disabled}
        />
      </div>
      {hata && !etiketGizli ? (
        <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p>
      ) : null}
    </div>
  )
}
