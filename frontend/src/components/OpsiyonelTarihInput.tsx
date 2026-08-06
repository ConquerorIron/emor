import { useEffect, useState } from 'react'

import { Switch } from '@/components/Switch'
import { TarihInput } from '@/components/TarihInput'

interface OpsiyonelTarihInputProps {
  id: string
  label: string
  /** ISO (YYYY-MM-DD) veya '' — anahtar kapalıyken her zaman '' */
  value: string
  onChange: (iso: string) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) — anahtar da kilitlenir */
  disabled?: boolean
}

/**
 * Anahtarla açılan tarih girişi (ERP Termin/Opsiyon deseni): varsayılan
 * kapalıdır ve tarih İÇERMEZ; kullanıcı önce anahtarı açar, sonra tarih
 * seçebilir. Anahtar kapatılınca değer temizlenir.
 */
export function OpsiyonelTarihInput({
  id,
  label,
  value,
  onChange,
  hata,
  disabled = false,
}: OpsiyonelTarihInputProps) {
  const [aktif, setAktif] = useState(value !== '')

  // Dış değer değişimi (düzenleme formu açılışı): dolu değer anahtarı açar
  useEffect(() => {
    if (value !== '') {
      setAktif(true)
    }
  }, [value])

  const anahtarDegisti = (acik: boolean) => {
    setAktif(acik)
    if (!acik) {
      onChange('')
    }
  }

  return (
    <div>
      <Switch
        id={`${id}-anahtar`}
        label={label}
        checked={aktif}
        onChange={anahtarDegisti}
        disabled={disabled}
        vurgulu={false}
      />
      <div className="mt-1">
        <TarihInput
          id={id}
          label={label}
          etiketGizli
          value={value}
          onChange={onChange}
          disabled={disabled || !aktif}
          hata={hata}
        />
      </div>
    </div>
  )
}
