import { Switch } from '@/components/Switch'

/**
 * ERP BOOL tipi. Değer forma '0'/'1' olarak yazılır — proc'a ve SQL bit
 * alanına dönüştürmeden gider (diğer alanlarla aynı metin sözleşmesi).
 */
interface EvetHayirAlaniProps {
  id: string
  label: string
  /** '1' = açık, diğer her şey kapalı */
  value: string
  onChange: (deger: string) => void
  hata?: string
  disabled?: boolean
}

export function EvetHayirAlani({
  id,
  label,
  value,
  onChange,
  hata,
  disabled = false,
}: EvetHayirAlaniProps) {
  return (
    <div>
      <Switch
        id={id}
        label={label}
        checked={value === '1'}
        onChange={(acik) => onChange(acik ? '1' : '0')}
        disabled={disabled}
        vurgulu={false}
      />
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
