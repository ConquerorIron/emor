import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useForm, type FieldPath } from 'react-hook-form'
import { afterEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { gunEkle, tarihGoster } from '@/utils/tarih'

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

/** Maskeli sayı alanına gerçek kullanımdaki gibi tuşlarla yazar */
function tusla(alan: HTMLElement, ...tuslar: string[]) {
  fireEvent.focus(alan)
  for (const tus of tuslar) {
    fireEvent.keyDown(alan, { key: tus })
  }
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

    // Alan sıfırla açılır ve varsayılan hassasiyette (2 basamak) gösterilir
    expect(miktar).toHaveValue('0,00')

    // "Ad" ölçü sistemi 6 basamağa izin verir — gösterim kendiliğinden düzelir
    await secenegiSec(screen.getByLabelText('Ürün kodu 1'), '01.01.01.0001')
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('6')
    })

    expect(screen.getByLabelText('Miktar 1')).toHaveValue('0,000000')
  })

  it('maskeli giriş: rakam tam kısma yazılır, ondalık haneler kaybolmaz', async () => {
    // Kullanıcı bildirimi 2026-08-07: tıklayınca imleç sıfırın soluna düşüyor,
    // "1" yazınca "10" oluyor ve virgülden sonrası siliniyordu
    render(
      <AppProviders>
        <Yuzler etiketler={['miktar']} izlenen="satirlar.0.miktar" />
      </AppProviders>,
    )

    const miktar = screen.getByLabelText('Miktar 1')

    // Tek başına duran sıfırın yerine yazılır, "10" olmaz
    tusla(miktar, '1')
    await waitFor(() => {
      expect(miktar).toHaveValue('1,00')
    })

    // Sonraki rakam tam kısma eklenir
    tusla(miktar, '2')
    await waitFor(() => {
      expect(miktar).toHaveValue('12,00')
    })

    // Virgül tuşu ondalık haneye geçirir; oradaki basamak üzerine yazılır
    tusla(miktar, ',', '5')
    await waitFor(() => {
      expect(miktar).toHaveValue('12,50')
    })

    // Ondalık hanede silmek basamağı sıfırlar, haneyi kaldırmaz
    fireEvent.keyDown(miktar, { key: 'Backspace' })
    await waitFor(() => {
      expect(miktar).toHaveValue('12,00')
    })
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
    tusla(miktar, '1')

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

  it('para birimi ilk harfe basılarak seçilir', async () => {
    // Kullanıcı isteği 2026-08-07: U → USD, E → EUR; listeyi açmaya gerek yok
    render(
      <AppProviders>
        <Yuzler etiketler={['birimFiyati']} izlenen="satirlar.0.birim_fiyati_para_id" />
      </AppProviders>,
    )

    // Satır TL ile açılır; liste gelene kadar bekle
    await screen.findByText('TL', { selector: '.erp-select__single-value' })
    expect(screen.getByTestId('secili-id')).toHaveTextContent('1')

    fireEvent.keyDown(screen.getByLabelText('Birim fiyatı 1 — para birimi'), { key: 'u' })

    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('2')
    })
  })

  it('teslim süresi ile teslim tarihi birbirini günceller', async () => {
    // Başlıktaki desenin aynısı: satırda da aynı verinin iki yüzü
    render(
      <AppProviders>
        <Yuzler etiketler={['teslimTarihi', 'teslimSuresi']} izlenen="satirlar.0.teslim_suresi" />
      </AppProviders>,
    )

    // Süreye 7 gün yazınca tarih talep tarihinden (08.07.2026) türer
    fireEvent.change(screen.getByLabelText('Teslim süresi 1'), { target: { value: '7' } })
    await waitFor(() => {
      expect(screen.getByLabelText('Teslim tarihi 1')).toHaveValue(
        tarihGoster(gunEkle('2026-07-08', 7)),
      )
    })

    // Tarihten girince süre geri hesaplanır
    fireEvent.change(screen.getByLabelText('Teslim tarihi 1'), {
      target: { value: tarihGoster(gunEkle('2026-07-08', 21)) },
    })
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('21')
    })
  })

  it('ERP nin doldurduğu alanlar giriş değil, gösterimdir', async () => {
    // Kullanıcı kararı 2026-08-07: bu dört alana kullanıcı veri girmez
    render(
      <AppProviders>
        <Yuzler
          etiketler={['butceTutari', 'gerceklesenMaliyet', 'gerceklesmeKuru', 'gerceklesmeOrani']}
          izlenen="satirlar.0.butce_tutari"
        />
      </AppProviders>,
    )

    for (const etiket of [
      'Bütçe tutarı 1',
      'Gerçekleşen maliyet 1',
      'Gerçekleşme kuru 1',
      'Gerçekleşme % 1',
    ]) {
      expect(screen.getByLabelText(etiket).tagName).toBe('OUTPUT')
    }

    // Tutar alanları paranın basamağında, kur altı basamakta gösterilir
    expect(screen.getByLabelText('Bütçe tutarı 1')).toHaveTextContent('0,00')
    expect(screen.getByLabelText('Gerçekleşme kuru 1')).toHaveTextContent('0,000000')
    expect(screen.getByLabelText('Gerçekleşme % 1')).toHaveTextContent('0,00 %')
  })

  it('kapandı alanı anahtardır', async () => {
    render(
      <AppProviders>
        <Yuzler etiketler={['kapandi']} izlenen="satirlar.0.kapandi" />
      </AppProviders>,
    )

    const anahtar = screen.getByLabelText('Kapandı 1')
    expect(anahtar).toHaveAttribute('role', 'switch')

    fireEvent.click(anahtar)
    await waitFor(() => {
      expect(screen.getByTestId('secili-id')).toHaveTextContent('1')
    })
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
