import { Controller, type Path, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'
import { AktiviteSecimi, MasrafMerkeziSecimi, type KodAdSecim } from '@/formAlanlari'

import type { TalepAlani } from './talepAlanlari'
import type { TalepGirdisi } from './talepSchema'

/**
 * Bir satır hücresini çizer. Kolon tipini `talepAlanlari` belirler; ERP seçim
 * listeleri formAlanlari modülünden gelir — ızgara ile başlık formu aynı
 * bileşenleri kullanır, yalnız etiket thead'e taşındığı için gizlenir.
 */
export function SatirHucresi({
  alan,
  indeks,
  form,
  projemizId,
  projeKodu,
  hata,
}: {
  alan: TalepAlani
  indeks: number
  form: UseFormReturn<TalepGirdisi>
  /** Başlıkta seçili proje — satır listelerinin kaynağı; '' = proje seçilmedi */
  projemizId: string
  /** Aynı projenin kodu — yansıma kolonunda gösterilir */
  projeKodu: string
  hata?: string
}) {
  const { t } = useTranslation()

  const etiket = t(`satinalma.alan.${alan.etiketAnahtari}`)
  const erisimEtiketi = `${etiket} ${indeks + 1}`
  const id = `satir-${indeks}-${alan.etiketAnahtari}`

  // Başlıktan türeyen, satırda saklanmayan değer
  if (alan.hucre === 'yansima') {
    return (
      <span
        title={projeKodu}
        className="block truncate px-2 py-2 text-sm text-slate-600 dark:text-slate-300"
      >
        {projeKodu === '' ? '—' : projeKodu}
      </span>
    )
  }

  if (alan.hucre === 'metin' && alan.ad) {
    return (
      <input
        aria-label={erisimEtiketi}
        aria-invalid={hata ? true : undefined}
        title={hata ? t(hata) : undefined}
        autoComplete="off"
        className={`block w-full min-w-28 px-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)}`}
        {...form.register(`satirlar.${indeks}.${alan.ad}`)}
      />
    )
  }

  // Çift yüzlü seçimler: kod ve ad sütunları TEK kaydı işaret eder, hangisinden
  // seçilirse seçilsin üç anahtar birlikte yazılır (id + kod + ad)
  const aktiviteMi = alan.hucre === 'aktiviteKodu' || alan.hucre === 'aktiviteAciklamasi'
  const goster = alan.hucre === 'aktiviteKodu' || alan.hucre === 'masrafMerkeziKodu' ? 'kod' : 'ad'

  const anahtar = (son: string) => `satirlar.${indeks}.${son}` as Path<TalepGirdisi>
  const idAnahtari = anahtar(aktiviteMi ? 'aktivite_id' : 'masraf_merkezi_id')

  const yaz = (secim: KodAdSecim | null) => {
    form.setValue(idAnahtari, secim?.kayitId ?? '')
    form.setValue(anahtar(aktiviteMi ? 'aktivite_kodu' : 'masraf_merkezi_kodu'), secim?.kod ?? '')
    form.setValue(
      anahtar(aktiviteMi ? 'aktivite_aciklamasi' : 'masraf_merkezi_adi'),
      secim?.ad ?? '',
    )
  }

  return (
    <div className="min-w-48">
      <Controller
        control={form.control}
        name={idAnahtari}
        render={({ field }) => {
          const ortak = {
            id,
            label: erisimEtiketi,
            etiketGizli: true,
            projemizId,
            deger: typeof field.value === 'string' ? field.value : '',
            degisti: yaz,
            goster,
            hata,
          } as const

          return aktiviteMi ? <AktiviteSecimi {...ortak} /> : <MasrafMerkeziSecimi {...ortak} />
        }}
      />
    </div>
  )
}
