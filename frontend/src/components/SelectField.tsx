import Select, { type MultiValue, type SingleValue } from 'react-select'

import { ALAN_ETIKETI } from '@/components/alanStilleri'
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
  /** Salt okunur alanlar (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
  /**
   * Etiket görsel olarak gizlenir (ızgara hücrelerinde başlık thead'de durur);
   * erişilebilirlik için etiket DOM'da kalır.
   */
  etiketGizli?: boolean
}

function AlanSarici({
  id,
  label,
  hata,
  etiketGizli = false,
  children,
}: {
  id: string
  label: string
  hata?: string
  etiketGizli?: boolean
  children: React.ReactNode
}) {
  return (
    <div>
      <label htmlFor={id} className={etiketGizli ? 'sr-only' : `mb-1 ${ALAN_ETIKETI}`}>
        {label}
      </label>
      {children}
      {/* Izgara hücresinde mesaja yer yok; hata kırmızı çerçeve ve title ile bildirilir */}
      {hata && !etiketGizli ? (
        <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p>
      ) : null}
    </div>
  )
}

interface SelectFieldProps extends OrtakProps {
  value: SecenekOgesi | null
  onChange: (deger: SingleValue<SecenekOgesi>) => void
  /** Sunucu taraflı arama: girilen metni bildirir; client filtresi kapanır. */
  aramaDegisti?: (girdi: string) => void
  yukleniyor?: boolean
  /** Kısa listelerde ilk harfle seçim gibi özel klavye davranışları için */
  tusaBasildi?: (olay: React.KeyboardEvent<HTMLDivElement>) => void
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
  disabled = false,
  etiketGizli = false,
  tusaBasildi,
}: SelectFieldProps) {
  const { theme } = useTheme()

  return (
    <AlanSarici id={id} label={label} hata={hata} etiketGizli={etiketGizli}>
      <Select<SecenekOgesi, false>
        inputId={id}
        aria-invalid={hata ? true : undefined}
        value={value}
        onChange={onChange}
        onKeyDown={tusaBasildi}
        options={options}
        placeholder={placeholder}
        isClearable={isClearable && !disabled}
        isSearchable={isSearchable && !disabled}
        isDisabled={disabled}
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
        styles={selectStilleri<false>(theme === 'dark', hata !== undefined)}
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
