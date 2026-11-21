import { useTranslation } from 'react-i18next'

import type { AyarGrubu, AyarSecimi } from '@/features/yedekleme/ustuneGeriYuklemeApi'

export type AyarSecimDurumu = Partial<Record<AyarGrubu, AyarSecimi>>

const SECENEKLER: AyarSecimi[] = ['guncel', 'geri_yuklenen']

/**
 * Onaydan önce farkı olan her ayar grubu için açık seçim (README §5.3;
 * BY-028-R2): "güncel" (ayarların dosya kopyası — bugün) ya da "geri
 * yüklenen" (yedekteki). Varsayılan seçim yoktur; seçimler tamamlanmadan
 * sayfa "Onayla"yı kapalı tutar. Dosya kopyası okunamadıysa "güncel" seçilemez.
 */
export function AyarSecimleri({
  gruplar,
  kopyaYok,
  secimler,
  degistir,
  disabled,
}: {
  gruplar: AyarGrubu[]
  kopyaYok: boolean
  secimler: AyarSecimDurumu
  degistir: (grup: AyarGrubu, secim: AyarSecimi) => void
  disabled: boolean
}) {
  const { t } = useTranslation()

  return (
    <section
      aria-labelledby="ayar-secimleri-baslik"
      className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
    >
      <div>
        <h3 id="ayar-secimleri-baslik" className="font-bold">
          {t('yedekleme.ustune.ayarSecimi.baslik')}
        </h3>
        <p className="mt-1">{t('yedekleme.ustune.ayarSecimi.aciklama')}</p>
        {kopyaYok ? (
          <p role="alert" className="mt-1 font-semibold">
            {t('yedekleme.ustune.ayarSecimi.kopyaYok')}
          </p>
        ) : null}
      </div>
      {gruplar.map((grup) => (
        <fieldset key={grup} className="space-y-1">
          <legend className="font-semibold">
            {t(`yedekleme.ustune.bilgi.bolum.${grup}`, { defaultValue: grup })}
          </legend>
          <div className="flex flex-wrap gap-4">
            {SECENEKLER.map((secim) => (
              <label key={secim} className="inline-flex items-center gap-2">
                <input
                  type="radio"
                  name={`ayar-${grup}`}
                  value={secim}
                  checked={secimler[grup] === secim}
                  disabled={disabled || (kopyaYok && secim === 'guncel')}
                  onChange={() => degistir(grup, secim)}
                  className="h-4 w-4 accent-blue-600"
                />
                {t(`yedekleme.ustune.ayarSecimi.${secim}`)}
              </label>
            ))}
          </div>
        </fieldset>
      ))}
    </section>
  )
}
