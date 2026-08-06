import { useTranslation } from 'react-i18next'

import { EvetHayirAlani } from '@/components/EvetHayirAlani'
import { Input } from '@/components/Input'
import { OpsiyonelTarihInput } from '@/components/OpsiyonelTarihInput'
import { SayiAlani } from '@/components/SayiAlani'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { SinirliMetinInput } from '@/components/SinirliMetinInput'
import { TarihInput } from '@/components/TarihInput'

import { AlimYeriSecimi } from './bilesenler/AlimYeriSecimi'
import { DepoSecimi } from './bilesenler/DepoSecimi'
import { FirmamizAdresiSecimi } from './bilesenler/FirmamizAdresiSecimi'
import { IlgiliSecimi } from './bilesenler/IlgiliSecimi'
import { OncelikSecimi } from './bilesenler/OncelikSecimi'
import { PersonelSecimi } from './bilesenler/PersonelSecimi'
import { TeslimatBicimiSecimi } from './bilesenler/TeslimatBicimiSecimi'
import { TeslimatSekliSecimi } from './bilesenler/TeslimatSekliSecimi'
import { TeslimatSuresiSecimi } from './bilesenler/TeslimatSuresiSecimi'
import type { GirisTanimi, OrtakGirisProps } from './ortakTipler'
import {
  ILGI_CINSLERI,
  VARSAYILAN_ILGI_CINSI,
  type IlgiCinsi,
  type TeslimatSuresiBirimi,
} from './veri/sabitler'
import { sureyiTariheCevir, tarihiSureyeCevir } from './veri/sureCevrimi'

/**
 * `giris_tipi` → bileşen eşlemesi. ERP'nin domain tipleriyle örtüşür; yeni
 * ekranlar (Sipariş, Fatura, Teklif) aynı tipleri kullandığı için buraya kayıt
 * eklemeden çalışır. Ortak kurallar burada DEĞİL, AlanGirisi'nde uygulanır —
 * her tanım yalnız kendine özgü kısmı yazar ve `{...ortak}` diye geçirir.
 */
export const GIRIS_TANIMLARI: Record<string, GirisTanimi> = {
  // ── Serbest metin ────────────────────────────────────────────────────────
  metin: {
    ciz: ({ ortak, duzen, deger, degistir }) =>
      duzen.gorunum === 'textarea' ? (
        <div>
          <label
            htmlFor={ortak.id}
            className="block text-sm font-semibold text-slate-700 dark:text-slate-300"
          >
            {ortak.label}
          </label>
          <textarea
            id={ortak.id}
            rows={duzen.satir ?? 2}
            disabled={ortak.disabled}
            value={deger}
            onChange={(olay) => degistir(olay.target.value)}
            className="mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm transition-colors outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:disabled:bg-slate-900 dark:focus:ring-blue-900"
          />
        </div>
      ) : (
        <Input
          {...ortak}
          autoComplete="off"
          value={deger}
          onChange={(olay) => degistir(olay.target.value)}
        />
      ),
  },

  /** ERP ACIKLAMA200 / 512 / 1024 / 3072 — sınır katalogdan gelir */
  sinirliMetin: {
    ciz: ({ ortak, katalog, duzen, deger, degistir }) => (
      <SinirliMetinInput
        {...ortak}
        value={deger}
        onChange={degistir}
        limit={katalog.metin_limiti || 200}
        rows={duzen.satir ?? 2}
      />
    ),
  },

  // ── Sayısal ──────────────────────────────────────────────────────────────
  /** ERP MIKTAR */
  sayi: {
    ciz: ({ ortak, deger, degistir }) => <SayiAlani {...ortak} value={deger} onChange={degistir} />,
  },

  /** ERP ORAN */
  oran: {
    ciz: ({ ortak, deger, degistir }) => (
      <SayiAlani {...ortak} value={deger} onChange={degistir} yuzde ondalik={2} />
    ),
  },

  /** ERP BOOL */
  evetHayir: {
    ciz: ({ ortak, deger, degistir }) => (
      <EvetHayirAlani {...ortak} value={deger} onChange={degistir} />
    ),
  },

  // ── Tarih / süre ─────────────────────────────────────────────────────────
  /** ERP TARIH */
  tarih: {
    ciz: ({ ortak, deger, degistir }) => (
      <TarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  /** Anahtarla açılan tarih (ERP Opsiyon tarihi deseni) */
  opsiyonelTarih: {
    ciz: ({ ortak, deger, degistir }) => (
      <OpsiyonelTarihInput {...ortak} value={deger} onChange={degistir} />
    ),
  },

  /** ERP SURE — miktar + birim */
  teslimatSuresi: {
    ciz: ({ ortak, deger, degistir, yanDegistir, form, katalog }) => (
      <TeslimatSuresiSecimi
        {...ortak}
        sure={deger}
        birim={(form.watch(katalog.veri_anahtarlari[1]) ?? '3') as TeslimatSuresiBirimi}
        degisti={(sure, birim) => {
          degistir(sure)
          yanDegistir(1, birim)
        }}
      />
    ),
  },

  /** Süre alanının tarih yüzü — aynı veriyi temel tarihten türetir */
  teslimatSuresiTarih: {
    ciz: ({ ortak, deger, degistir, yanDegistir, form, katalog, bagliOku }) => {
      const temel = bagliOku()
      const birim = (form.watch(katalog.veri_anahtarlari[1]) ?? '3') as TeslimatSuresiBirimi

      return (
        <TarihInput
          {...ortak}
          value={sureyiTariheCevir(temel, deger, birim)}
          onChange={(iso) => {
            const cevrim = tarihiSureyeCevir(temel, iso, birim)
            degistir(cevrim.sure)
            yanDegistir(1, cevrim.birim)
          }}
        />
      )
    },
  },

  // ── ERP KAYIT_ID — kayıt seçiciler ───────────────────────────────────────
  personel: {
    ciz: ({ ortak, deger, degistir, yanDegistir }) => (
      <PersonelSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          // Görünen ad ikinci veri anahtarına yazılır
          yanDegistir(1, secim?.unvan ?? '')
        }}
      />
    ),
  },

  depo: {
    ciz: ({ ortak, deger, degistir }) => (
      <DepoSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  teslimatAdresi: {
    ciz: ({ ortak, deger, degistir, yanDegistir }) => (
      <FirmamizAdresiSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => {
          degistir(secim?.kayitId ?? '')
          // Adres metni ikinci veri anahtarına yazılır (@TESLIMAT_ADRESI)
          yanDegistir(1, secim?.adres ?? '')
        }}
        tekSeceneginiSec
      />
    ),
  },

  /** Etiketi ve arama kaynağı bağlı alandaki cinse göre değişir */
  ilgili: {
    etiket: ({ bagliOku, t }) =>
      t(`satinalma.ilgiliEtiket.${(bagliOku() || VARSAYILAN_ILGI_CINSI) as IlgiCinsi}`),
    ciz: ({ ortak, deger, degistir, bagliOku }) => (
      <IlgiliSecimi
        {...ortak}
        cins={(bagliOku() || VARSAYILAN_ILGI_CINSI) as IlgiCinsi}
        deger={deger}
        degisti={degistir}
      />
    ),
  },

  // ── ERP MADDE — tablo maddesi türevleri ──────────────────────────────────
  oncelik: {
    ciz: ({ ortak, deger, degistir }) => (
      <OncelikSecimi {...ortak} deger={deger} degisti={(secim) => degistir(secim?.kayitId ?? '')} />
    ),
  },

  teslimatSekli: {
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatSekliSecimi
        {...ortak}
        deger={deger}
        degisti={(secim) => degistir(secim?.kayitId ?? '')}
      />
    ),
  },

  // ── ERP TUR — sabit listeler ─────────────────────────────────────────────
  teslimatBicimi: {
    ciz: ({ ortak, deger, degistir }) => (
      <TeslimatBicimiSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
  },

  alimYeri: {
    ciz: ({ ortak, deger, degistir }) => (
      <AlimYeriSecimi {...ortak} deger={deger} degisti={degistir} />
    ),
  },

  ilgiCinsi: {
    ciz: ({ ortak, deger, degistir, bagliYaz }) => (
      <IlgiCinsiSecimi
        ortak={ortak}
        deger={deger}
        degisti={(yeni) => {
          degistir(yeni)
          // Cins değişince bağlı alan (katalogda tanımlı) sıfırlanır
          bagliYaz('')
        }}
      />
    ),
  },
}

/** Seçenekleri çeviriden üretildiği için ayrı bileşen. */
function IlgiCinsiSecimi({
  ortak,
  deger,
  degisti,
}: {
  ortak: OrtakGirisProps
  deger: string
  degisti: (deger: string) => void
}) {
  const { t } = useTranslation()
  const secenekler: SecenekOgesi[] = ILGI_CINSLERI.map((cins) => ({
    value: cins,
    label: t(`satinalma.ilgiCinsi.${cins}`),
  }))

  return (
    <SelectField
      {...ortak}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => degisti(secim?.value ?? VARSAYILAN_ILGI_CINSI)}
      isClearable={false}
    />
  )
}
