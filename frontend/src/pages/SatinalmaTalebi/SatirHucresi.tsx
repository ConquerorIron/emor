import { Controller, type Path, type UseFormReturn } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { ALAN_GORUNUMU, ALAN_KUTUSU, alanCercevesi } from '@/components/alanStilleri'
import { SayiAlani } from '@/components/SayiAlani'
import { TarihInput } from '@/components/TarihInput'
import {
  AktiviteSecimi,
  AmbalajSecimi,
  ButceBolumuSecimi,
  ButceKalemiSecimi,
  DuranVarlikSecimi,
  EkipmanSecimi,
  MasrafMerkeziSecimi,
  PartiYamasiPersonelSecimi,
  sureyiTariheCevir,
  tarihiSureyeCevir,
  TeslimatSuresiSecimi,
  UrunSecimi,
  VARSAYILAN_BIRIM,
  type KayitSecimProps,
  type KayitYuzleri,
  type TeslimatSuresiBirimi,
} from '@/formAlanlari'

import { FIYAT_GRUPLARI } from './fiyatGruplari'
import { FiyatHucresi } from './FiyatHucresi'
import { SATIR_KAYITLARI, type SatirKaydi, type SatirKaynagi } from './satirKayitlari'
import { TutarHucresi } from './TutarHucresi'
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
  ambalaj: AmbalajSecimi,
}

/** Kayıt seçilmemişken (ölçü sistemi bilinmiyorken) sayı alanının hassasiyeti */
const VARSAYILAN_BASAMAK = 2

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
  tarih,
}: {
  alan: TalepAlani
  indeks: number
  form: UseFormReturn<TalepGirdisi>
  /** Başlıkta seçili proje — satır listelerinin kaynağı; '' = proje seçilmedi */
  projemizId: string
  /** Aynı projenin kodu — yansıma kolonunda gösterilir */
  projeKodu: string
  /** Başlıktaki talep tarihi (ISO) — kur bu tarihe göre okunur */
  tarih: string
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
        // Giriş hücreleriyle aynı yükseklik (satır hizası bozulmasın)
        className={`flex items-center truncate px-2 text-sm text-slate-600 ${ALAN_KUTUSU} dark:text-slate-300`}
      >
        {projeKodu === '' ? '—' : projeKodu}
      </span>
    )
  }

  // Ondalık hassasiyet ERP'de sabit değil: seçilen kaydın ölçü sisteminden
  // gelip satıra yazılmıştı, hücre onu okuyor
  // Teslim süresi ve teslim tarihi aynı verinin iki yüzü: hangisi girilirse
  // diğeri türer (başlıktaki desenin aynısı, temel tarih = talep tarihi)
  if (alan.hucre.tip === 'sure' || alan.hucre.tip === 'sureTarih') {
    const { ad, birimAnahtari } = alan.hucre
    const birim = (String(form.watch(anahtar(birimAnahtari)) ?? '') ||
      VARSAYILAN_BIRIM) as TeslimatSuresiBirimi
    const tarihYuzu = alan.hucre.tip === 'sureTarih'

    return (
      <Controller
        control={form.control}
        name={anahtar(ad)}
        render={({ field }) => {
          const sure = typeof field.value === 'string' ? field.value : ''
          const yaz = (yeniSure: string, yeniBirim: string) => {
            field.onChange(yeniSure)
            form.setValue(anahtar(birimAnahtari), yeniBirim)
          }

          return tarihYuzu ? (
            <TarihInput
              id={id}
              label={erisimEtiketi}
              etiketGizli
              value={sureyiTariheCevir(tarih, sure, birim)}
              onChange={(iso) => {
                const cevrim = tarihiSureyeCevir(tarih, iso, birim)
                yaz(cevrim.sure, cevrim.birim)
              }}
              hata={hataMetni(ad)}
            />
          ) : (
            <TeslimatSuresiSecimi
              id={id}
              label={erisimEtiketi}
              etiketGizli
              sure={sure}
              birim={birim}
              degisti={yaz}
              hata={hataMetni(ad)}
            />
          )
        }}
      />
    )
  }

  if (alan.hucre.tip === 'fiyat') {
    return (
      <FiyatHucresi
        grup={FIYAT_GRUPLARI[alan.hucre.grup]}
        indeks={indeks}
        form={form}
        id={id}
        etiket={erisimEtiketi}
        tarih={tarih}
        hata={hataMetni(FIYAT_GRUPLARI[alan.hucre.grup].fiyat)}
      />
    )
  }

  if (alan.hucre.tip === 'tutar') {
    return (
      <TutarHucresi
        grup={FIYAT_GRUPLARI[alan.hucre.grup]}
        indeks={indeks}
        form={form}
        etiket={etiket}
        kurUygula={alan.hucre.kurUygula}
      />
    )
  }

  if (alan.hucre.tip === 'sayi') {
    const { ad, basamakAnahtari, sabitBasamak, sonEkAnahtari } = alan.hucre
    const basamak = basamakAnahtari
      ? Number(form.watch(anahtar(basamakAnahtari)))
      : (sabitBasamak ?? VARSAYILAN_BASAMAK)
    const sonEk = sonEkAnahtari ? String(form.watch(anahtar(sonEkAnahtari)) ?? '') : ''

    return (
      <Controller
        control={form.control}
        name={anahtar(ad)}
        render={({ field }) => (
          <SayiAlani
            id={id}
            label={erisimEtiketi}
            etiketGizli
            value={typeof field.value === 'string' ? field.value : ''}
            onChange={field.onChange}
            ondalik={Number.isFinite(basamak) && basamak > 0 ? basamak : VARSAYILAN_BASAMAK}
            sonEk={sonEk}
            hata={hataMetni(ad)}
          />
        )}
      />
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
        className={`block w-full px-2 ${ALAN_GORUNUMU} ${ALAN_KUTUSU} ${alanCercevesi(hata)}`}
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
    <div title={hata ? t(hata) : undefined}>
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
