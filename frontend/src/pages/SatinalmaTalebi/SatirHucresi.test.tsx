import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useForm, type FieldPath } from 'react-hook-form'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'

import { SatirHucresi } from './SatirHucresi'
import { SATIR_ALANLARI, type TalepAlani } from './talepAlanlari'
import { BOS_TALEP, type TalepGirdisi } from './talepSchema'

const AKTIVITELER = [
  { kayit_id: 15804, kod: 'A.01.01.01', aciklama: 'Mimari Proje Tasarım Giderleri', poz_no: '' },
  { kayit_id: 15805, kod: 'A.01.01.02', aciklama: 'Statik Proje Tasarım Giderleri', poz_no: '' },
]

const URUNLER = [
  {
    kayit_id: 3707,
    kod: '01.01.01.0001',
    ad: 'CURUF VOLKANİK',
    barkod: '8690000000017',
    birim: 'Ad',
    basamak: 6,
  },
  {
    kayit_id: 3710,
    kod: '01.01.01.0002',
    ad: 'ÇİMENTO CEM I 42.5',
    barkod: '8690000000024',
    // m3 ölçü sistemi: iki basamak
    birim: 'm3',
    basamak: 2,
  },
]

const EKIPMANLAR = [
  { kayit_id: 41, kod: 'EKP-01', ad: 'Forklift 3 ton' },
  { kayit_id: 42, kod: 'EKP-02', ad: 'Kule vinç' },
]

const PARALAR = [
  { kayit_id: 1, kod: 'TL', ad: 'Türk lirası', fiyat_basamak: 6, tutar_basamak: 2 },
  { kayit_id: 2, kod: 'USD', ad: 'Amerikan Doları', fiyat_basamak: 6, tutar_basamak: 2 },
]

/** ERP'deki gerçek değer: 8.07.2026 USD rapor kuru */
const USD_KURU = '46.7525'

vi.mock('@/formAlanlari/veri/secenekApi', async (asliniAl) => ({
  ...(await asliniAl<typeof import('@/formAlanlari/veri/secenekApi')>()),
  aktiviteSecenekleriGetir: () => Promise.resolve(AKTIVITELER),
  urunSecenekleriGetir: () => Promise.resolve(URUNLER),
  ekipmanSecenekleriGetir: () => Promise.resolve(EKIPMANLAR),
  paraSecenekleriGetir: () => Promise.resolve(PARALAR),
  kurGetir: (paraId: string) => Promise.resolve(paraId === '2' ? USD_KURU : '1'),
}))

function alanBul(etiketAnahtari: string): TalepAlani {
  const alan = SATIR_ALANLARI.find((a) => a.etiketAnahtari === etiketAnahtari)
  if (!alan) {
    throw new Error(`${etiketAnahtari} kolonu tanımlı değil`)
  }

  return alan
}

/** Aynı forma bağlı yüzler — gerçek ızgaradaki gibi tek satırı paylaşırlar */
function Yuzler({
  etiketler,
  izlenen,
  projemizId = '34924',
}: {
  etiketler: string[]
  izlenen: FieldPath<TalepGirdisi>
  projemizId?: string
}) {
  const form = useForm<TalepGirdisi>({ defaultValues: BOS_TALEP })

  return (
    <>
      {etiketler.map((etiket) => (
        <SatirHucresi
          key={etiket}
          alan={alanBul(etiket)}
          indeks={0}
          form={form}
          projemizId={projemizId}
          projeKodu="PRJ-1"
          tarih="2026-07-08"
        />
      ))}
      <output data-testid="secili-id">{String(form.watch(izlenen) ?? '')}</output>
    </>
  )
}

function IkiYuz({ projemizId }: { projemizId?: string }) {
  return (
    <Yuzler
      etiketler={['aktiviteKodu', 'aktiviteAciklamasi']}
      izlenen="satirlar.0.aktivite_id"
      projemizId={projemizId}
    />
  )
}

/** react-select: aşağı ok menüyü açar, seçenek tıklanır */
async function secenegiSec(combobox: HTMLElement, metin: string) {
  fireEvent.keyDown(combobox, { key: 'ArrowDown' })
  const secenek = await screen.findByText(metin, { selector: '.erp-select__option' })
  fireEvent.click(secenek)
}

/**
 * ERP ızgarasında bir kayıt iki sütunda birden durur (kod ve açıklama).
 * Kullanıcı hangisinden seçerse diğeri kendiliğinden dolmalı — ikisi de aynı
 * AKTIVITE_ID'yi işaretler.
 */
describe('SatirHucresi — çift yüzlü seçim', () => {
  afterEach(cleanup)

  it('koddan seçince açıklama da dolar', async () => {
    render(
      <AppProviders>
        <IkiYuz />
      </AppProviders>,
    )

    await secenegiSec(screen.getByLabelText('Aktivite kodu 1'), 'A.01.01.02')

    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('15805')
    })
    expect(
      screen.getByText('Statik Proje Tasarım Giderleri', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
  })

  it('açıklamadan seçince kod da dolar', async () => {
    render(
      <AppProviders>
        <IkiYuz />
      </AppProviders>,
    )

    await secenegiSec(
      screen.getByLabelText('Aktivite açıklaması 1'),
      'Mimari Proje Tasarım Giderleri',
    )

    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('15804')
    })
    expect(
      screen.getByText('A.01.01.01', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
  })

  it('ürün barkoddan seçilince kod ve ad da dolar (üç yüz)', async () => {
    render(
      <AppProviders>
        <Yuzler etiketler={['urunKodu', 'barkod', 'urunAdi']} izlenen="satirlar.0.urun_yamasi_id" />
      </AppProviders>,
    )

    await secenegiSec(screen.getByLabelText('Barkod 1'), '8690000000024')

    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('3710')
    })
    expect(
      screen.getByText('01.01.01.0002', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText('ÇİMENTO CEM I 42.5', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
  })

  it('tek yüzlü seçimde ad görünür, kimlik saklanır', async () => {
    // Ekipman gibi tek yüzlü kayıtlarda yüz adı ERP kaydındaki alanla
    // eşleşmezse hücre sessizce boş kalırdı — bu test onu yakalar
    render(
      <AppProviders>
        <Yuzler etiketler={['ekipmanAdi']} izlenen="satirlar.0.ekipman_id" />
      </AppProviders>,
    )

    await secenegiSec(screen.getByLabelText('Ekipman adı 1'), 'Kule vinç')

    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('42')
    })
  })

  it('miktarın ondalık hassasiyeti seçilen ürünün ölçü sisteminden gelir', async () => {
    render(
      <AppProviders>
        <Yuzler etiketler={['urunKodu', 'miktar']} izlenen="satirlar.0.urun_basamak_sayisi" />
      </AppProviders>,
    )

    const miktar = screen.getByLabelText('Miktar 1')

    // Ürün seçilmeden varsayılan hassasiyet (2 basamak) uygulanır
    fireEvent.change(miktar, { target: { value: '1,23456' } })
    expect(miktar).toHaveValue('1,23')

    // m3 ürünü de 2 basamak; "Ad" ürünü 6 basamağa izin verir
    await secenegiSec(screen.getByLabelText('Ürün kodu 1'), '01.01.01.0001')
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('6')
    })

    fireEvent.change(miktar, { target: { value: '1,23456' } })
    expect(miktar).toHaveValue('1,23456')
  })

  it('miktar alandan çıkınca tam basamağa tamamlanır ve birim gösterilir', async () => {
    // ERP ızgarasındaki görünüm: "1,000000 Ad"
    render(
      <AppProviders>
        <Yuzler etiketler={['urunKodu', 'miktar']} izlenen="satirlar.0.urun_birimi" />
      </AppProviders>,
    )

    await secenegiSec(screen.getByLabelText('Ürün kodu 1'), '01.01.01.0001')
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('Ad')
    })

    const miktar = screen.getByLabelText('Miktar 1')
    fireEvent.change(miktar, { target: { value: '1' } })
    fireEvent.blur(miktar)

    await waitFor(() => {
      expect(miktar).toHaveValue('1,000000')
    })
    // Birim, değerin sağında ayrı bir etiket olarak durur (değere karışmaz)
    expect(miktar.parentElement?.querySelector('span')).toHaveTextContent('Ad')
  })

  it('para seçilince kur gelir, tutar hesaplanır ve yazılamaz', async () => {
    // ERP ekranındaki satırın aynısı: 1 × 10 USD × 46,7525 = 467,53
    render(
      <AppProviders>
        <Yuzler
          etiketler={['miktar', 'birimFiyati', 'birimFiyatiKuru', 'tutar', 'tutarYp']}
          izlenen="satirlar.0.birim_fiyati_kuru"
        />
      </AppProviders>,
    )

    fireEvent.change(screen.getByLabelText('Miktar 1'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Birim fiyatı 1'), { target: { value: '10' } })
    await secenegiSec(screen.getByLabelText('Birim fiyatı 1 — para birimi'), 'USD')

    // Kur belge tarihine göre kendiliğinden dolar
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent(USD_KURU)
    })

    expect(screen.getByLabelText('Tutar 1')).toHaveTextContent('467,53')
    expect(screen.getByLabelText('Tutar (YP) 1')).toHaveTextContent('10,00 USD')
    // Tutar hesaplanan bir değerdir; giriş öğesi değildir
    expect(screen.getByLabelText('Tutar 1').tagName).toBe('OUTPUT')
  })

  it('proje seçilmeden aktivite seçilemez', async () => {
    render(
      <AppProviders>
        <IkiYuz projemizId="" />
      </AppProviders>,
    )

    expect(screen.getByLabelText('Aktivite kodu 1')).toBeDisabled()
  })
})
