import Select, { type MultiValue, type SingleValue } from 'react-select'

import { selectStilleri, type SecenekOgesi } from '@/components/selectStilleri'
import { useTheme } from '@/hooks/useTheme'

export type { SecenekOgesi }

interface OrtakProps {
  id: string
  label: string
  hata?: string
  options: SecenekOgesi[]
  placeholder?: string
  isClearable?: boolean
  isSearchable?: boolean
}

function AlanSarici({
  id,
  label,
  hata,
  children,
}: {
  id: string
  label: string
  hata?: string
  children: React.ReactNode
}) {
  return (
    <div>
      <label
        htmlFor={id}
        className="mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-300"
      >
        {label}
      </label>
      {children}
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}

interface SelectFieldProps extends OrtakProps {
  value: SecenekOgesi | null
  onChange: (deger: SingleValue<SecenekOgesi>) => void
  /** Sunucu taraflı arama: girilen metni bildirir; client filtresi kapanır. */
  aramaDegisti?: (girdi: string) => void
  yukleniyor?: boolean
}

export function SelectField({
  id,
  label,
  hata,
  options,
  value,
  onChange,
  placeholder,
  isClearable = false,
  isSearchable = true,
  aramaDegisti,
  yukleniyor = false,
}: SelectFieldProps) {
  const { theme } = useTheme()

  return (
    <AlanSarici id={id} label={label} hata={hata}>
      <Select<SecenekOgesi, false>
        inputId={id}
        value={value}
        onChange={onChange}
        options={options}
        placeholder={placeholder}
        isClearable={isClearable}
        isSearchable={isSearchable}
        isLoading={yukleniyor}
        onInputChange={
          aramaDegisti
            ? (girdi, meta) => {
                if (meta.action === 'input-change') {
                  aramaDegisti(girdi)
                }
              }
            : undefined
        }
        // Sunucu zaten süzdü — client filtresi sonuçları ikinci kez daraltmasın
        filterOption={aramaDegisti ? () => true : undefined}
        classNamePrefix="erp-select"
        menuPortalTarget={document.body}
        styles={selectStilleri<false>(theme === 'dark')}
      />
    </AlanSarici>
  )
}

interface MultiSelectFieldProps extends OrtakProps {
  value: SecenekOgesi[]
  onChange: (deger: MultiValue<SecenekOgesi>) => void
}

export function MultiSelectField({
  id,
  label,
  hata,
  options,
  value,
  onChange,
  placeholder,
  isSearchable = true,
}: MultiSelectFieldProps) {
  const { theme } = useTheme()

  return (
    <AlanSarici id={id} label={label} hata={hata}>
      <Select<SecenekOgesi, true>
        inputId={id}
        isMulti
        value={value}
        onChange={onChange}
        options={options}
        placeholder={placeholder}
        isSearchable={isSearchable}
        closeMenuOnSelect={false}
        classNamePrefix="erp-select"
        menuPortalTarget={document.body}
        styles={selectStilleri<true>(theme === 'dark')}
      />
    </AlanSarici>
  )
}
