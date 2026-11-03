import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import { bugunIso } from '@/utils/tarih'

import { TaseronHakedisleriPage } from './TaseronHakedisleriPage'
import type { HakedisSatiri } from './taseronHakedisleriApi'

const api = vi.hoisted(() => ({
  rapor: vi.fn(),
  secenekler: vi.fn(),
  excel: vi.fn(),
}))

vi.mock('./taseronHakedisleriApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./taseronHakedisleriApi')>()),
  taseronHakedisleriApi: { getir: api.rapor, secenekler: api.secenekler, excel: api.excel },
}))

const tutarlar = (imalat: number) => ({
  imalat,
  tutanak: 0,
  hakedis: 0,
  imalat_kesintisi: 0,
  kdv: 0,
  kdvli_hakedis: 0,
  kdv_tevkifati: 0,
  cari_kesinti: 0,
  yansitilacak_gider: 0,
  taseron_adina_yapilan_is: 0,
  net_odenecek: 0,
  odeme_talebi_tutari: 0,
  odenen_tutar: 0,
})

const hakedis = (
  hakedis_id: number,
  proje_adi: string,
  imalat: number,
  firma_adi = 'ATİLLA GIDA',
): HakedisSatiri => ({
  hakedis_id,
  firma_adi,
  proje_adi,
  sozlesme_no: 'HAB-26-1078.03.Z01',
  sozlesme_konusu: 'YEMEK TEMİNİ HİZMETLERİ',
  hakedis_no: String(hakedis_id),
  hakedis_tarihi: '2026-05-16',
  donem_baslangici: '2026-04-16',
  donem_sonu: '2026-05-15',
  para_kodu: 'TL',
  onay_durumu: 'Onaylandı',
  onaylayan: 'ozalp.doganalp',
  onay_tarihi: null,
  onay_saati: null,
  fatura_no: '',
  hakedis_durumu: '',
  ...tutarlar(imalat),
})

const RAPOR: HakedisSatiri[] = [
  { ...hakedis(3, 'ZONE 7', 240663.52), onay_tarihi: '2026-07-06', onay_saati: '13:53:14' },
  hakedis(4, 'ZONE 10', 1000, 'BETA YAPI'),
]

const VARSAYILAN = { ilk_tarih: '2000-01-01', son_tarih: bugunIso(), proje_kodu: '', firma_adi: '' }

describe('TaseronHakedisleriPage', () => {
  beforeEach(() => {
    // Ortak sayfa boyutu tercihi (utils/sayfaBoyutu)
    localStorage.setItem('erp.sayfaBoyutu', '25')
    api.secenekler.mockResolvedValue({
      projeler: [{ kod: 'ZONE 10', ad: 'ZONE 10' }],
      firmalar: ['ATİLLA GIDA'],
    })
    api.rapor.mockResolvedValue(RAPOR)
    api.excel.mockResolvedValue(undefined)
    render(
      <AppProviders>
        <TaseronHakedisleriPage />
      </AppProviders>,
    )
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('rapor yalnız Raporu Çalıştır ile çalışır; hakediş başına satır, kolonlar kullanıcının sırasında, para koduna göre toplamlı', async () => {
    expect(screen.getByText("Parametreleri seçip Raporu Çalıştır'a basın.")).toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeDisabled()
    expect(api.rapor).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))

    expect(await screen.findByText('ZONE 7')).toBeInTheDocument()
    expect(api.rapor.mock.calls[0][0]).toEqual(VARSAYILAN)
    expect(screen.getAllByRole('columnheader').map((baslik) => baslik.textContent)).toEqual([
      'Sıra No',
      'Firma Adı',
      'Proje Adı',
      'Sözleşme No',
      'Sözleşme Konusu',
      'Hakediş No',
      'Hakediş Tarihi',
      'Dönem Başlangıcı',
      'Dönem Sonu',
      'Para Kodu',
      'İmalat',
      'Tutanak',
      'Hakediş',
      'İmalat Kesintisi',
      'KDV',
      "KDV'li Hakediş",
      'KDV Tevkifatı',
      'Cari Kesinti',
      'Yansıtılacak Gider',
      'Taşeron Adına Yapılan İş',
      'Net Ödenecek',
      'Onay Durumu',
      'Onaylayan',
      'Onay Tarihi',
      'Onay Saati',
      'Fatura No',
      'Hakediş Durumu',
      'Ödeme Talebi Tutarı',
      'Ödenen Tutar',
    ])
    // Hakediş başına satır: tarihler gün.ay.yıl, tutarlar Türkçe biçimli
    const ilkSatir = within(screen.getAllByRole('row')[2])
    expect(ilkSatir.getByText('ZONE 7')).toBeInTheDocument()
    expect(ilkSatir.getByText('16.05.2026')).toBeInTheDocument()
    expect(ilkSatir.getByText('06.07.2026')).toBeInTheDocument()
    expect(ilkSatir.getByText('13:53:14')).toBeInTheDocument()
    expect(ilkSatir.getByText('240.663,52')).toBeInTheDocument()
    // Toplam satırı tablonun üstünde ve altında
    expect(screen.getAllByText('241.663,52')).toHaveLength(2)

    // Excel aynı parametreler ve ekrandaki hakedişlerle (ekrandaki sırayla)
    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toEqual({
      parametreler: VARSAYILAN,
      anahtarlar: ['3', '4'],
    })
  })

  it('gelen veride proje ve firmaya göre anında süzülür; proc yeniden çalışmaz', async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('2 kayıt')).toBeInTheDocument()

    const proje = screen.getByLabelText('Projeye göre filtrele')
    fireEvent.keyDown(proje, { key: 'ArrowDown' })
    fireEvent.click(await screen.findByRole('option', { name: 'ZONE 10' }))

    expect(await screen.findByText('2 kayıttan 1 kayıt')).toBeInTheDocument()
    expect(screen.queryByText('240.663,52')).not.toBeInTheDocument()
    // Toplamlar süzülen satırlardan: satırın kendisi + üst ve alt toplam
    expect(screen.getAllByText('1.000,00')).toHaveLength(3)

    // Firma süzgeci projeyle birlikte uygulanır
    const firma = screen.getByLabelText('Firmaya göre filtrele')
    fireEvent.keyDown(firma, { key: 'ArrowDown' })
    fireEvent.click(await screen.findByRole('option', { name: 'ATİLLA GIDA' }))
    expect(await screen.findByText('2 kayıttan 0 kayıt')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Excel'e Aktar" })).toBeDisabled()

    fireEvent.keyDown(firma, { key: 'Backspace' })
    expect(await screen.findByText('2 kayıttan 1 kayıt')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(api.excel.mock.calls[0][0]).toEqual({ parametreler: VARSAYILAN, anahtarlar: ['4'] })
    expect(api.rapor).toHaveBeenCalledTimes(1)
  })

  it('özet sekmeleri süzülen satırları gruplar: Taşeron (firma + proje + para), Tarih (yıl + ay)', async () => {
    api.rapor.mockResolvedValue([
      hakedis(1, 'ZONE 7', 100),
      hakedis(2, 'ZONE 7', 50.25),
      hakedis(3, 'ZONE 10', 10, 'BETA YAPI'),
    ])
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('3 kayıt')).toBeInTheDocument()

    const sekmeler = within(screen.getByRole('tablist', { name: 'Görünümler' }))
    expect(sekmeler.getAllByRole('tab').map((sekme) => sekme.textContent)).toEqual([
      'Data',
      'Taşeron',
      'Proje',
      'Tarih',
    ])

    fireEvent.click(sekmeler.getByRole('tab', { name: 'Taşeron' }))
    const basliklar = screen
      .getAllByRole('columnheader')
      .map((baslik) => baslik.textContent.replace(/[▲▼↕]/g, ''))
    expect(basliklar.slice(0, 5)).toEqual([
      'Firma Adı',
      'Proje Adı',
      'Para Kodu',
      'Kayıt Sayısı',
      'İmalat',
    ])
    // Başlık + üst toplam + 2 grup + alt toplam; aynı firma + projedeki iki hakediş toplanır
    const satirlar = screen.getAllByRole('row')
    expect(satirlar).toHaveLength(5)
    const atilla = within(satirlar[2])
      .getAllByRole('cell')
      .map((hucre) => hucre.textContent)
    expect(atilla.slice(0, 5)).toEqual(['ATİLLA GIDA', 'ZONE 7', 'TL', '2', '150,25'])

    // Süzgeç özet sekmelerine de uygulanır
    fireEvent.change(screen.getByLabelText('Genel Arama'), { target: { value: 'beta' } })
    await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(4))

    fireEvent.click(sekmeler.getByRole('tab', { name: 'Tarih' }))
    expect(screen.getByRole('columnheader', { name: /Yıl/ })).toBeInTheDocument()
    expect(screen.getByText('Mayıs')).toBeInTheDocument()
    expect(api.rapor).toHaveBeenCalledTimes(1)
  })

  it('sonuç gelene kadar süzgeç, sekme ve tablo yerine yükleniyor kutusu görünür', async () => {
    let coz: (satirlar: HakedisSatiri[]) => void = () => undefined
    api.rapor.mockReturnValue(
      new Promise<HakedisSatiri[]>((resolve) => {
        coz = resolve
      }),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))

    expect(await screen.findByRole('status')).toHaveTextContent("Rapor ERP'den okunuyor…")
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Genel Arama')).not.toBeInTheDocument()

    coz(RAPOR)
    expect(await screen.findByText('ZONE 7')).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('Kolonlar menüsünden gizlenen kolon tablodan kalkar; tercih rapor başına saklanır', async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('ZONE 7')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /^Kolonlar \(28\/28\)/ }))
    fireEvent.click(await screen.findByRole('switch', { name: 'Sözleşme Konusu' }))

    await waitFor(() =>
      expect(
        screen.queryByRole('columnheader', { name: 'Sözleşme Konusu' }),
      ).not.toBeInTheDocument(),
    )
    expect(screen.getByRole('columnheader', { name: 'Sıra No' })).toBeInTheDocument()
    expect(
      JSON.parse(localStorage.getItem('erp.kolonlar.rapor.taseronHakedisleri') ?? '[]'),
    ).toEqual(['sozlesme_konusu'])

    // Excel gizli kolonları da içerir: gönderilen yalnız parametreler ve satırlar
    fireEvent.click(screen.getByRole('button', { name: "Excel'e Aktar" }))
    await waitFor(() => expect(api.excel).toHaveBeenCalledTimes(1))
    expect(Object.keys(api.excel.mock.calls[0][0] as object)).toEqual([
      'parametreler',
      'anahtarlar',
    ])
  })

  it('genel arama metin kolonlarında, Türkçe büyük/küçük harf duyarsız', async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('2 kayıt')).toBeInTheDocument()

    const ara = screen.getByLabelText('Genel Arama')
    fireEvent.change(ara, { target: { value: 'atilla' } })
    expect(await screen.findByText('2 kayıttan 1 kayıt')).toBeInTheDocument()
    expect(screen.queryByText('BETA YAPI')).not.toBeInTheDocument()

    // Tutarlar aranmaz (yalnız tanımdaki metin kolonları)
    fireEvent.change(ara, { target: { value: '240' } })
    expect(await screen.findByText('2 kayıttan 0 kayıt')).toBeInTheDocument()
  })

  it('sayfalama tarayıcıda; Sıra No sayfalar arasında devam eder', async () => {
    api.rapor.mockResolvedValue(
      Array.from({ length: 30 }, (_, i) => hakedis(i + 1, `PROJE ${i + 1}`, 10)),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('30 kayıt')).toBeInTheDocument()

    // Başlık + üst toplam + 25 satır + alt toplam
    expect(screen.getAllByRole('row')).toHaveLength(28)
    expect(screen.getByText('PROJE 25')).toBeInTheDocument()
    expect(screen.queryByText('PROJE 26')).not.toBeInTheDocument()
    // Toplam bütün satırların: 30 × 10
    expect(screen.getAllByText('300,00')).toHaveLength(2)

    fireEvent.click(screen.getAllByRole('button', { name: 'Sonraki' })[0])
    expect(await screen.findByText('PROJE 26')).toBeInTheDocument()
    expect(screen.getAllByRole('row')).toHaveLength(8)
    // İlk hücre Sıra No
    expect(within(screen.getAllByRole('row')[2]).getAllByRole('cell')[0]).toHaveTextContent(/^26$/)
  })

  it('tarihler zorunlu; son tarih ilk tarihten önce olamaz', async () => {
    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('Tarih zorunludur.')).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('İlk Tarih'), { target: { value: '01.06.2026' } })
    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '01.01.2026' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    expect(await screen.findByText('Son tarih ilk tarihten önce olamaz.')).toBeInTheDocument()
    expect(api.rapor).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Son Tarih'), { target: { value: '30.06.2026' } })
    fireEvent.click(screen.getByRole('button', { name: 'Raporu Çalıştır' }))
    await waitFor(() => expect(api.rapor).toHaveBeenCalledTimes(1))
    expect(api.rapor.mock.calls[0][0]).toEqual({
      ...VARSAYILAN,
      ilk_tarih: '2026-06-01',
      son_tarih: '2026-06-30',
    })
  })
})
