import { useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'

import { Switch } from '@/components/Switch'
import {
  satirKolonEtiketi,
  type DuzenSatirKolonu,
  type SatirKatalogAlani,
} from '@/features/ekranTasarim/types'

/**
 * Satır ızgarasının tasarımı — başlık tuvaliyle aynı his, tek farkı ölçü birimi.
 *
 * Kolonlar SOLDAN SAĞA tek sıra dizilir (ızgarada da öyle duruyorlar), bu yüzden
 * tuval yatay bir şerittir: kartı sürükleyip yerini değiştirirsiniz. Ayarlar
 * karta sığdırılmaz — 39 kolonda okunmaz hale gelir — başlıktaki gibi seçili
 * kolonun paneline açılır.
 */
const SURUKLE_TURU = 'application/x-emor-satir-kolonu'

/** Sürüklenen kolon — palete mi şeride mi ait olduğu bırakma anında gerekir. */
type SurukleVerisi = { alan: string; kaynak: 'palet' | 'serit' }

function surukleOku(olay: DragEvent): SurukleVerisi | null {
  const ham = olay.dataTransfer.getData(SURUKLE_TURU)

  try {
    return ham ? (JSON.parse(ham) as SurukleVerisi) : null
  } catch {
    return null
  }
}

/** Palete geri konan kolon %10; px'te katalogun önerdiği genişlik kullanılır */
const YUZDE_VARSAYILANI = 10

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
  const [secili, setSecili] = useState<string | null>(null)
  const tanimlar = new Map(katalog.map((alan) => [alan.anahtar, alan]))

  const tasi = (kaynak: number, hedef: number) => {
    if (kaynak === hedef || hedef < 0 || hedef >= satirlar.length) {
      return
    }
    const yeni = [...satirlar]
    const [tasinan] = yeni.splice(kaynak, 1)
    yeni.splice(hedef, 0, tasinan)
    degisti(yeni)
  }

  const guncelle = (anahtar: string, deger: Partial<DuzenSatirKolonu>) =>
    degisti(satirlar.map((kolon) => (kolon.alan === anahtar ? { ...kolon, ...deger } : kolon)))

  // Tasarımda yeri olmayan kolonlar palette bekler (başlık tuvalindeki desen)
  const kullanilmayan = katalog.filter(
    (alan) => !satirlar.some((kolon) => kolon.alan === alan.anahtar),
  )

  /** Şeride bırakma: şeritten geldiyse taşıma, paletten geldiyse ekleme */
  const birak = (olay: DragEvent, hedef: number) => {
    olay.preventDefault()
    olay.stopPropagation()
    const veri = surukleOku(olay)
    if (!veri) {
      return
    }

    if (veri.kaynak === 'serit') {
      tasi(
        satirlar.findIndex((kolon) => kolon.alan === veri.alan),
        hedef,
      )

      return
    }

    const tanim = tanimlar.get(veri.alan)
    if (!tanim) {
      return
    }

    const yeni = [...satirlar]
    yeni.splice(hedef, 0, {
      alan: tanim.anahtar,
      genislik: birim === 'px' ? tanim.varsayilan_genislik : YUZDE_VARSAYILANI,
      gizli: false,
    })
    degisti(yeni)
    setSecili(tanim.anahtar)
  }

  /** Paletten tıklayarak ekleme — sürükleyemeyenler için sona koyar */
  const birakmadanEkle = (tanim: SatirKatalogAlani) => {
    degisti([
      ...satirlar,
      {
        alan: tanim.anahtar,
        genislik: birim === 'px' ? tanim.varsayilan_genislik : YUZDE_VARSAYILANI,
        gizli: false,
      },
    ])
    setSecili(tanim.anahtar)
  }

  /** Palete bırakma: kolonu tasarımdan çıkarır — kaldırılamazlar hariç */
  const paleteBirak = (olay: DragEvent) => {
    olay.preventDefault()
    const veri = surukleOku(olay)
    if (!veri || veri.kaynak !== 'serit' || tanimlar.get(veri.alan)?.kaldirilamaz) {
      return
    }

    degisti(satirlar.filter((kolon) => kolon.alan !== veri.alan))
    setSecili((onceki) => (onceki === veri.alan ? null : onceki))
  }

  // Gizli kolon ızgarada yer kaplamaz, toplama da girmez
  const yuzdeToplami = satirlar
    .filter((kolon) => kolon.gizli !== true)
    .reduce((toplam, kolon) => toplam + Math.max(0, kolon.genislik), 0)

  const seciliKolon = satirlar.find((kolon) => kolon.alan === secili)
  const seciliTanim = secili ? tanimlar.get(secili) : undefined
  const genislikEtiketi = t(birim === 'px' ? 'tasarim.genislikPx' : 'tasarim.genislikYuzde')

  return (
    <div className="mt-4 flex flex-col gap-4 xl:flex-row">
      {/* Palet: ızgarada yeri olmayan kolonlar — başlık tasarımındaki yan
          çerçevenin aynısı. Gizlemek ile kaldırmak ayrı şeylerdir: gizli kolon
          varsayılanını kayda gönderir, buradaki hiç gönderilmez */}
      <aside
        onDragOver={(olay) => olay.preventDefault()}
        onDrop={paleteBirak}
        className="w-full shrink-0 rounded-xl border border-slate-200 bg-white p-4 xl:w-64 dark:border-slate-700 dark:bg-slate-900"
      >
        <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
          {t('tasarim.kullanilmayanAlanlar')}
        </h3>
        <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
          {t('tasarim.paletAciklama')}
        </p>
        <div className="mt-3 space-y-2">
          {kullanilmayan.length === 0 ? (
            <p className="text-xs text-slate-400 dark:text-slate-500">
              {t('tasarim.tumAlanlarKullanimda')}
            </p>
          ) : null}
          {kullanilmayan.map((alan) => (
            <button
              key={alan.anahtar}
              type="button"
              draggable
              onDragStart={(olay) =>
                olay.dataTransfer.setData(
                  SURUKLE_TURU,
                  JSON.stringify({ alan: alan.anahtar, kaynak: 'palet' } satisfies SurukleVerisi),
                )
              }
              onClick={() => birakmadanEkle(alan)}
              className="block w-full cursor-grab rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 text-left text-sm text-slate-600 transition-colors hover:border-blue-400 hover:text-blue-700 active:cursor-grabbing dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300"
            >
              {satirKolonEtiketi(alan, t)}
            </button>
          ))}
        </div>
      </aside>

      <div className="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
        <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
          {t('tasarim.satirKolonlari')}
        </h3>
        <p className="mt-1 text-xs text-slate-400 dark:text-slate-500">
          {t('tasarim.satirKolonlariAciklama')}
        </p>

        {/* Birim ızgaranın davranışını değiştirir: kolon başına değil ekran
            başına seçilir — ikisi aynı tabloda karışamaz */}
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
          {/* Toplam 100'ü aşabilir (ızgara kaydırılır) ama tasarımcı bunu
              görmeden yapmamalı */}
          {birim === 'yuzde' ? (
            <span
              className={`text-xs font-semibold ${
                yuzdeToplami > 100
                  ? 'text-amber-600 dark:text-amber-400'
                  : 'text-slate-500 dark:text-slate-400'
              }`}
            >
              {t('tasarim.yuzdeToplami', { deger: yuzdeToplami })}
            </span>
          ) : null}
          <span className="text-xs text-slate-400 dark:text-slate-500">
            {t(birim === 'px' ? 'tasarim.birimPxAciklama' : 'tasarim.birimYuzdeAciklama')}
          </span>
        </div>

        {/* Izgaradaki gibi soldan sağa; sürükleyerek sıra değiştirilir */}
        <div
          onDragOver={(olay) => olay.preventDefault()}
          onDrop={(olay) => birak(olay, satirlar.length)}
          className="mt-3 flex min-h-24 gap-2 overflow-x-auto pb-2"
        >
          {satirlar.map((kolon, indeks) => {
            const tanim = tanimlar.get(kolon.alan)
            if (!tanim) {
              return null
            }

            return (
              <button
                key={kolon.alan}
                type="button"
                draggable
                onDragStart={(olay) =>
                  olay.dataTransfer.setData(
                    SURUKLE_TURU,
                    JSON.stringify({ alan: kolon.alan, kaynak: 'serit' } satisfies SurukleVerisi),
                  )
                }
                onDragOver={(olay) => olay.preventDefault()}
                onDrop={(olay) => birak(olay, indeks)}
                onClick={() => setSecili(kolon.alan)}
                className={`w-36 shrink-0 cursor-grab rounded-lg border p-2 text-left transition-colors active:cursor-grabbing ${
                  secili === kolon.alan
                    ? 'border-blue-500 bg-blue-50 dark:bg-slate-800'
                    : 'border-slate-200 bg-slate-50 hover:border-blue-400 dark:border-slate-700 dark:bg-slate-800'
                } ${kolon.gizli ? 'opacity-50' : ''}`}
              >
                <span className="block truncate text-xs font-semibold text-slate-700 dark:text-slate-200">
                  {satirKolonEtiketi(tanim, t)}
                </span>
                <span className="mt-1 block text-[11px] text-slate-400 dark:text-slate-500">
                  {kolon.genislik}
                  {birim === 'px' ? 'px' : '%'}
                  {kolon.zorunlu ? ' • *' : ''}
                  {kolon.gizli ? ` • ${t('tasarim.gizli')}` : ''}
                </span>
              </button>
            )
          })}
        </div>
      </div>

      {/* Seçili kolonun ayarları — başlık tasarımındaki panelin karşılığı */}
      <aside className="w-full shrink-0 rounded-xl border border-slate-200 bg-white p-4 xl:w-72 dark:border-slate-700 dark:bg-slate-900">
        {seciliKolon && seciliTanim ? (
          <>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100">
              {satirKolonEtiketi(seciliTanim, t)}
            </h3>

            <label className="mt-3 block text-xs font-semibold text-slate-500 dark:text-slate-400">
              {genislikEtiketi}
              <input
                type="number"
                min={birim === 'px' ? 64 : 1}
                max={birim === 'px' ? 640 : 100}
                step={1}
                aria-label={`${satirKolonEtiketi(seciliTanim, t)} — ${genislikEtiketi}`}
                value={seciliKolon.genislik}
                onChange={(olay) =>
                  guncelle(seciliKolon.alan, { genislik: Number(olay.target.value) })
                }
                className="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 outline-none focus:border-blue-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
              />
            </label>

            <label className="mt-3 block text-xs font-semibold text-slate-500 dark:text-slate-400">
              {t('tasarim.varsayilan')}
              <input
                type="text"
                aria-label={`${satirKolonEtiketi(seciliTanim, t)} — ${t('tasarim.varsayilan')}`}
                value={seciliKolon.varsayilan ?? ''}
                onChange={(olay) => guncelle(seciliKolon.alan, { varsayilan: olay.target.value })}
                className="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm text-slate-900 outline-none focus:border-blue-600 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
              />
            </label>

            <div className="mt-4 space-y-3">
              {/* ERP'nin tanımladığı özel alanlar ve satırın kimliği gizlenemez */}
              <Switch
                id={`gorunur-${seciliKolon.alan}`}
                label={t('tasarim.gorunur')}
                vurgulu={false}
                disabled={seciliTanim.kaldirilamaz}
                checked={seciliKolon.gizli !== true}
                onChange={(acik) => guncelle(seciliKolon.alan, { gizli: !acik })}
              />
              {/* Gizli kolon doldurulamaz, zorunlu da olamaz */}
              <Switch
                id={`zorunlu-${seciliKolon.alan}`}
                label={t('tasarim.zorunlu')}
                vurgulu={false}
                disabled={seciliKolon.gizli === true || seciliTanim.zorunlu === true}
                checked={seciliKolon.zorunlu === true || seciliTanim.zorunlu === true}
                onChange={(acik) => guncelle(seciliKolon.alan, { zorunlu: acik })}
              />
              {/* ERP'nin hesapladığı kolonlar zaten daima salt okunur */}
              <Switch
                id={`saltokunur-${seciliKolon.alan}`}
                label={t('tasarim.saltOkunur')}
                vurgulu={false}
                disabled={seciliTanim.salt_okunur_sabit}
                checked={seciliKolon.salt_okunur === true || seciliTanim.salt_okunur_sabit}
                onChange={(acik) => guncelle(seciliKolon.alan, { salt_okunur: acik })}
              />
            </div>

            {seciliTanim.zorunlu === true ? (
              <p className="mt-3 text-xs text-amber-600 dark:text-amber-400">
                {t('tasarim.erpZorunlu')}
              </p>
            ) : null}
          </>
        ) : (
          <p className="text-xs text-slate-400 dark:text-slate-500">{t('tasarim.kolonSec')}</p>
        )}
      </aside>
    </div>
  )
}
