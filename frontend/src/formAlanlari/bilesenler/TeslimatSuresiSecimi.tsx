import { useTranslation } from 'react-i18next'

import { ALAN_ETIKETI, ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'
import { selectStilleri, type SecenekOgesi } from '@/components/selectStilleri'
import { useTheme } from '@/hooks/useTheme'
import Select from 'react-select'

import { TESLIMAT_SURESI_BIRIMLERI, type TeslimatSuresiBirimi } from '../veri/sabitler'

interface TeslimatSuresiSecimiProps {
  id: string
  label: string
  /** Süre miktarı (metin — boş bırakılabilir) */
  sure: string
  birim: TeslimatSuresiBirimi
  degisti: (sure: string, birim: TeslimatSuresiBirimi) => void
  hata?: string
  /** Salt okunur (ekran tasarımı) */
  disabled?: boolean
}

/**
 * Teslimat süresi girişi — miktar + birim (gün/hf/ay) yan yana, ERP'deki
 * "21 gün" hücresinin karşılığı. Değerler @TESLIMAT_SURESI ve
 * @TESLIMAT_SURESI_BIRIMI parametrelerine gider.
 */
export function TeslimatSuresiSecimi({
  id,
  label,
  sure,
  birim,
  degisti,
  hata,
  disabled = false,
}: TeslimatSuresiSecimiProps) {
  const { t } = useTranslation()
  const { theme } = useTheme()

  const secenekler: SecenekOgesi[] = TESLIMAT_SURESI_BIRIMLERI.map((deger) => ({
    value: deger,
    label: t(`teslimat.sureBirimi.${deger}`),
  }))

  return (
    <div>
      <label htmlFor={id} className={ALAN_ETIKETI}>
        {label}
      </label>
      <div className="mt-1 flex items-start gap-2">
        <input
          id={id}
          inputMode="numeric"
          autoComplete="off"
          maxLength={5}
          disabled={disabled}
          value={sure}
          // Yalnız rakam: negatif/ondalık süre ERP'de yok
          onChange={(olay) => degisti(olay.target.value.replace(/\D/g, ''), birim)}
          aria-invalid={hata ? true : undefined}
          className={`block w-24 shrink-0 px-3 py-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)}`}
        />
        <div className="min-w-0 flex-1">
          <Select<SecenekOgesi, false>
            inputId={`${id}-birim`}
            aria-label={t('teslimat.sureBirimiEtiketi')}
            value={secenekler.find((s) => s.value === birim) ?? null}
            onChange={(secim) => degisti(sure, (secim?.value as TeslimatSuresiBirimi) ?? birim)}
            options={secenekler}
            isClearable={false}
            isSearchable={false}
            isDisabled={disabled}
            classNamePrefix="erp-select"
            menuPortalTarget={document.body}
            styles={selectStilleri<false>(theme === 'dark')}
          />
        </div>
      </div>
      {hata ? <p className="mt-1 text-sm text-red-600 dark:text-red-400">{hata}</p> : null}
    </div>
  )
}
