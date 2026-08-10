import { useTranslation } from 'react-i18next'

import { Switch } from '@/components/Switch'
import type { DuzenSatirKolonu, SatirKatalogAlani } from '@/features/ekranTasarim/types'

/**
 * Satır ızgarasının tasarımı.
 *
 * Başlıktaki sürükle-bırak tuvali burada işe yaramaz: 39 kolon var ve hepsi
 * tek sırada duruyor. Sıralı liste + yukarı/aşağı taşıma çok daha kullanışlı.
 *
 * Genişlik PİKSEL: ızgara yatay kaydırılır, kolon içeriği kadar yer kaplamalı
 * (kur alanı "46,752500" yazabilmeli).
 */
export function SatirTasarimi({
  katalog,
  satirlar,
  birim,
  degisti,
  birimDegisti,
}: {
  katalog: SatirKatalogAlani[]
  satirlar: DuzenSatirKolonu[]
  birim: 'px' | 'yuzde'
  degisti: (yeni: DuzenSatirKolonu[]) => void
  birimDegisti: (birim: 'px' | 'yuzde') => void
}) {
  const { t } = useTranslation()
  const tanimlar = new Map(katalog.map((alan) => [alan.anahtar, alan]))

  const tasi = (indeks: number, yon: -1 | 1) => {
    const hedef = indeks + yon
    if (hedef < 0 || hedef >= satirlar.length) {
      return
    }
    const yeni = [...satirlar]
    ;[yeni[indeks], yeni[hedef]] = [yeni[hedef], yeni[indeks]]
    degisti(yeni)
  }

  const guncelle = (indeks: number, deger: Partial<DuzenSatirKolonu>) =>
    degisti(satirlar.map((kolon, i) => (i === indeks ? { ...kolon, ...deger } : kolon)))

  return (
    <div className="mt-4 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
        {t('tasarim.satirKolonlari')}
      </h3>
      <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
        {t('tasarim.satirKolonlariAciklama')}
      </p>

      {/* Birim ızgaranın davranışını değiştirir, o yüzden kolon başına değil
          ekran başına seçilir — ikisi aynı tabloda karışamaz */}
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <span className="text-xs font-semibold text-slate-500 dark:text-slate-400">
          {t('tasarim.genislikBirimi')}
        </span>
        {(['px', 'yuzde'] as const).map((secenek) => (
          <button
            key={secenek}
            type="button"
            aria-pressed={birim === secenek}
            onClick={() => birimDegisti(secenek)}
            className={`cursor-pointer rounded-lg px-3 py-1 text-xs font-semibold transition-colors ${
              birim === secenek
                ? 'bg-blue-600 text-white'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300'
            }`}
          >
            {t(`tasarim.birim.${secenek}`)}
          </button>
        ))}
        <span className="text-xs text-slate-400 dark:text-slate-500">
          {t(birim === 'px' ? 'tasarim.birimPxAciklama' : 'tasarim.birimYuzdeAciklama')}
        </span>
      </div>

      <ul className="mt-3 space-y-1">
        {satirlar.map((kolon, indeks) => {
          const tanim = tanimlar.get(kolon.alan)
          if (!tanim) {
            return null
          }

          return (
            <li
              key={kolon.alan}
              className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-700"
            >
              <span className="w-6 text-xs text-slate-400 tabular-nums">{indeks + 1}</span>

              <div className="flex shrink-0 flex-col">
                <button
                  type="button"
                  aria-label={`${t(tanim.etiket_anahtari)} — ${t('tasarim.yukariTasi')}`}
                  disabled={indeks === 0}
                  onClick={() => tasi(indeks, -1)}
                  className="cursor-pointer px-1 text-xs text-slate-500 hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-30"
                >
                  ▲
                </button>
                <button
                  type="button"
                  aria-label={`${t(tanim.etiket_anahtari)} — ${t('tasarim.asagiTasi')}`}
                  disabled={indeks === satirlar.length - 1}
                  onClick={() => tasi(indeks, 1)}
                  className="cursor-pointer px-1 text-xs text-slate-500 hover:text-blue-600 disabled:cursor-not-allowed disabled:opacity-30"
                >
                  ▼
                </button>
              </div>

              <span className="min-w-40 flex-1 text-sm font-semibold text-slate-700 dark:text-slate-200">
                {t(tanim.etiket_anahtari)}
              </span>

              <label className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                {t(birim === 'px' ? 'tasarim.genislikPx' : 'tasarim.genislikYuzde')}
                <input
                  type="number"
                  min={birim === 'px' ? 64 : 1}
                  max={birim === 'px' ? 640 : 100}
                  step={1}
                  aria-label={`${t(tanim.etiket_anahtari)} — ${t(birim === 'px' ? 'tasarim.genislikPx' : 'tasarim.genislikYuzde')}`}
                  value={kolon.genislik}
                  onChange={(olay) => guncelle(indeks, { genislik: Number(olay.target.value) })}
                  className="h-9 w-20 rounded-lg border border-slate-300 bg-white px-2 text-right text-sm text-slate-900 outline-none focus:border-blue-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                />
              </label>

              {/* Gizli kolon da ERP'ye değer gönderebilmeli */}
              <label className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                {t('tasarim.varsayilan')}
                <input
                  type="text"
                  aria-label={`${t(tanim.etiket_anahtari)} — ${t('tasarim.varsayilan')}`}
                  value={kolon.varsayilan ?? ''}
                  onChange={(olay) => guncelle(indeks, { varsayilan: olay.target.value })}
                  className="h-9 w-28 rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 outline-none focus:border-blue-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                />
              </label>

              {/* Ürün kodu satırın kimliği: gizlenemez */}
              <div className="w-32">
                <Switch
                  id={`gorunur-${kolon.alan}`}
                  label={t('tasarim.gorunur')}
                  vurgulu={false}
                  disabled={tanim.kaldirilamaz}
                  checked={kolon.gizli !== true}
                  onChange={(acik) => guncelle(indeks, { gizli: !acik })}
                />
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
