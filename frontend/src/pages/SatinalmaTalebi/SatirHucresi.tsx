import { Controller, type Path, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'
import {
  AktiviteSecimi,
  ButceBolumuSecimi,
  ButceKalemiSecimi,
  DuranVarlikSecimi,
  EkipmanSecimi,
  MasrafMerkeziSecimi,
  PartiYamasiPersonelSecimi,
  UrunSecimi,
  type KayitSecimProps,
  type KayitYuzleri,
} from '@/formAlanlari'

import { SATIR_KAYITLARI, type SatirKaydi, type SatirKaynagi } from './satirKayitlari'
import type { TalepAlani } from './talepAlanlari'
import type { TalepGirdisi, TalepSatiri } from './talepSchema'

/**
 * Kaynak → bileşen kayıt defteri. Hepsi aynı sözleşmeyi (KayitSecimProps)
 * taşıdığı için hücre çizimi kaynağa göre DALLANMAZ; yeni bir seçim kaynağı
 * eklemek buraya bir satır yazmaktır.
 */
const SECIM_BILESENLERI: Record<SatirKaynagi, (props: KayitSecimProps) => React.ReactElement> = {
  aktivite: AktiviteSecimi,
  masrafMerkezi: MasrafMerkeziSecimi,
  urun: UrunSecimi,
  ekipman: EkipmanSecimi,
  butceKalemi: ButceKalemiSecimi,
  butceBolumu: ButceBolumuSecimi,
  duranVarlik: DuranVarlikSecimi,
  personel: PartiYamasiPersonelSecimi,
}

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
}: {
  alan: TalepAlani
  indeks: number
  form: UseFormReturn<TalepGirdisi>
  /** Başlıkta seçili proje — satır listelerinin kaynağı; '' = proje seçilmedi */
  projemizId: string
  /** Aynı projenin kodu — yansıma kolonunda gösterilir */
  projeKodu: string
}) {
  const { t } = useTranslation()

  const etiket = t(`satinalma.alan.${alan.etiketAnahtari}`)
  const erisimEtiketi = `${etiket} ${indeks + 1}`
  const id = `satir-${indeks}-${alan.etiketAnahtari}`
  const anahtar = (son: string) => `satirlar.${indeks}.${son}` as Path<TalepGirdisi>
  const hataMetni = (formAnahtari: keyof TalepSatiri): string | undefined =>
    form.formState.errors.satirlar?.[indeks]?.[formAnahtari]?.message

  // Başlıktan türeyen, satırda saklanmayan değer
  if (alan.hucre.tip === 'yansima') {
    return (
      <span
        title={projeKodu}
        className="block truncate px-2 py-2 text-sm text-slate-600 dark:text-slate-300"
      >
        {projeKodu === '' ? '—' : projeKodu}
      </span>
    )
  }

  if (alan.hucre.tip === 'metin') {
    const hata = hataMetni(alan.hucre.ad)

    return (
      <input
        aria-label={erisimEtiketi}
        aria-invalid={hata ? true : undefined}
        title={hata ? t(hata) : undefined}
        autoComplete="off"
        className={`block w-full min-w-28 px-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)}`}
        {...form.register(`satirlar.${indeks}.${alan.hucre.ad}`)}
      />
    )
  }

  // Çok yüzlü seçim: sütun kaydın bir yüzüdür, seçim TÜM yüzleri doldurur
  const { kaynak, goster } = alan.hucre
  const kayit: SatirKaydi = SATIR_KAYITLARI[kaynak]
  const idAnahtari = anahtar(kayit.idAnahtari)
  // Hata kaydın kimliğinde tutulur; kaydın her yüzü aynı hatayı gösterir
  const hata = hataMetni(kayit.idAnahtari)

  const yaz = (secilen: KayitYuzleri | null) => {
    form.setValue(idAnahtari, secilen ? String(secilen.kayit_id) : '')
    for (const [yuz, formAnahtari] of Object.entries(kayit.yuzler)) {
      form.setValue(anahtar(formAnahtari), secilen ? String(secilen[yuz] ?? '') : '')
    }
  }

  const Secim = SECIM_BILESENLERI[kaynak]
  const seciliYuz = kayit.yuzler[goster]

  return (
    <div className="min-w-48" title={hata ? t(hata) : undefined}>
      <Controller
        control={form.control}
        name={idAnahtari}
        render={({ field }) => (
          <Secim
            id={id}
            label={erisimEtiketi}
            etiketGizli
            deger={typeof field.value === 'string' ? field.value : ''}
            degisti={yaz}
            goster={goster}
            projemizId={projemizId}
            // Listesi sunucuda süzülen kaynaklarda (ürün) seçili kayıt o anki
            // sonuçta olmayabilir; gösterimi satırdan okuyoruz
            seciliEtiket={seciliYuz ? String(form.watch(anahtar(seciliYuz)) ?? '') : ''}
            hata={hata ? t(hata) : undefined}
          />
        )}
      />
    </div>
  )
}
