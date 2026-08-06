import { useTranslation } from 'react-i18next'

import { Switch } from '@/components/Switch'

import type { DuzenAlani, KatalogAlani } from '@/features/ekranTasarim/types'
import { VarsayilanDegerSecici } from './VarsayilanDegerSecici'

/** Artır/azalt düğmeli sayı girişi — sınır dışına çıkılamaz. */
function SayiAyari({
  id,
  label,
  deger,
  enAz,
  enCok,
  degisti,
}: {
  id: string
  label: string
  deger: number
  enAz: number
  enCok: number
  degisti: (deger: number) => void
}) {
  const sinirla = (sayi: number) => Math.max(enAz, Math.min(enCok, sayi))
  const dugmeSinifi =
    'flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-slate-300 text-lg font-semibold text-slate-600 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'

  return (
    <div>
      <label
        htmlFor={id}
        className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
      >
        {label}
      </label>
      <div className="mt-1 flex items-center gap-2">
        <button
          type="button"
          aria-label={`${label} azalt`}
          disabled={deger <= enAz}
          onClick={() => degisti(sinirla(deger - 1))}
          className={dugmeSinifi}
        >
          −
        </button>
        <input
          id={id}
          inputMode="numeric"
          maxLength={2}
          value={String(deger)}
          onChange={(olay) =>
            degisti(sinirla(Number(olay.target.value.replace(/\D/g, '')) || enAz))
          }
          className="h-9 w-14 rounded-lg border border-slate-300 bg-white text-center text-sm text-slate-900 shadow-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-200 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:focus:ring-blue-900"
        />
        <button
          type="button"
          aria-label={`${label} artır`}
          disabled={deger >= enCok}
          onClick={() => degisti(sinirla(deger + 1))}
          className={dugmeSinifi}
        >
          +
        </button>
      </div>
    </div>
  )
}

interface AlanAyarPaneliProps {
  katalog: KatalogAlani
  duzen: DuzenAlani
  degisti: (degisiklik: Partial<DuzenAlani>) => void
  kaldir: () => void
}

const GENISLIKLER = [1, 2, 3, 4, 6, 8, 9, 12]

/** Seçili alanın kuralları — tasarımın "ne yapsın" tarafı. */
export function AlanAyarPaneli({ katalog, duzen, degisti, kaldir }: AlanAyarPaneliProps) {
  const { t } = useTranslation()

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <div className="mb-3 flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-slate-800 dark:text-slate-100">
            {t(katalog.etiket_anahtari)}
          </p>
          <p className="mt-0.5 truncate text-xs text-slate-400 dark:text-slate-500">
            {katalog.proc_parametresi || katalog.anahtar}
          </p>
        </div>
      </div>

      <div className="space-y-3">
        <div>
          <span className="block text-sm font-semibold text-slate-700 dark:text-slate-300">
            {t('tasarim.genislik')}
          </span>
          <div className="mt-1 flex flex-wrap gap-1">
            {GENISLIKLER.map((genislik) => (
              <button
                key={genislik}
                type="button"
                aria-label={`${t('tasarim.genislik')} ${genislik}`}
                aria-pressed={duzen.genislik === genislik}
                onClick={() => degisti({ genislik })}
                className={`h-8 min-w-10 cursor-pointer rounded-lg border px-2 text-sm font-semibold transition-colors ${
                  duzen.genislik === genislik
                    ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300'
                    : 'border-slate-300 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800'
                }`}
              >
                {genislik}
              </button>
            ))}
          </div>
          <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
            {t('tasarim.genislikAciklama')}
          </p>
        </div>

        <Switch
          id={`ayar-gizli-${katalog.anahtar}`}
          label={t('tasarim.gizli')}
          checked={duzen.gizli === true}
          onChange={(acik) => degisti({ gizli: acik })}
          vurgulu={false}
        />
        {duzen.gizli ? (
          <p className="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            {t('tasarim.gizliAciklama')}
          </p>
        ) : null}

        {katalog.zorunlu_secilebilir && !duzen.gizli ? (
          <Switch
            id={`ayar-zorunlu-${katalog.anahtar}`}
            label={t('tasarim.zorunlu')}
            checked={duzen.zorunlu === true}
            onChange={(acik) => degisti({ zorunlu: acik })}
            vurgulu={false}
          />
        ) : null}

        {katalog.salt_okunur_sabit ? (
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">
            {t('tasarim.saltOkunurKilitli')}
          </p>
        ) : (
          <Switch
            id={`ayar-salt-okunur-${katalog.anahtar}`}
            label={t('tasarim.saltOkunur')}
            checked={duzen.salt_okunur === true}
            onChange={(acik) => degisti({ salt_okunur: acik })}
            vurgulu={false}
          />
        )}

        {katalog.metin_alani ? (
          <>
            <Switch
              id={`ayar-textarea-${katalog.anahtar}`}
              label={t('tasarim.textarea')}
              checked={duzen.gorunum === 'textarea'}
              onChange={(acik) => degisti({ gorunum: acik ? 'textarea' : undefined, satir: 2 })}
              vurgulu={false}
            />
            {duzen.gorunum === 'textarea' ? (
              <SayiAyari
                id={`ayar-satir-${katalog.anahtar}`}
                label={t('tasarim.satirSayisi')}
                deger={duzen.satir ?? 2}
                enAz={1}
                enCok={10}
                degisti={(satir) => degisti({ satir })}
              />
            ) : null}
          </>
        ) : null}

        {/* Varsayılan, alanın kendi giriş bileşeniyle seçilir — ERP kodu ezberlenmez */}
        <VarsayilanDegerSecici
          katalog={katalog}
          deger={duzen.varsayilan ?? ''}
          degisti={(deger) => degisti({ varsayilan: deger })}
        />

        {katalog.kaldirilamaz ? (
          <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">
            {t('tasarim.kaldirilamaz')}
          </p>
        ) : (
          <button
            type="button"
            onClick={kaldir}
            className="w-full cursor-pointer rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-600 transition-colors hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-950"
          >
            {t('tasarim.alaniKaldir')}
          </button>
        )}
      </div>
    </div>
  )
}
