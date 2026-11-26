import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { AlarmKurallariYaniti } from '@/features/ayarlar/alarmApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { AlarmKurallariPage } from './AlarmKurallariPage'

const api = vi.hoisted(() => ({
  getir: vi.fn(),
  kaydet: vi.fn(),
}))

vi.mock('@/features/ayarlar/alarmApi', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/features/ayarlar/alarmApi')>()),
  alarmKurallariniGetir: api.getir,
  alarmKuraliKaydet: api.kaydet,
}))

const YANIT: AlarmKurallariYaniti = {
  data: [
    {
      tur: 'senkron_arizasi',
      aktif: false,
      alicilar: [],
      alicilar_cc: [],
      alicilar_bcc: [],
      parametreler: { ardisik_hata: 3, gecikme_saat: 2 },
      updated_at: '2026-09-23T10:00:00+00:00',
    },
    {
      tur: 'gunluk_ozet',
      aktif: false,
      alicilar: [],
      alicilar_cc: [],
      alicilar_bcc: [],
      parametreler: { saat: '08:00' },
      updated_at: '2026-09-23T10:00:00+00:00',
    },
    {
      tur: 'erp_okumadi',
      aktif: true,
      alicilar: ['muhasebe@ornek.test'],
      alicilar_cc: [],
      alicilar_bcc: [],
      parametreler: { gun: 2, saat: '09:00' },
      updated_at: '2026-09-23T10:00:00+00:00',
    },
    {
      tur: 'islenmemis_hatirlatma',
      aktif: false,
      alicilar: ['muhasebe@ornek.test'],
      alicilar_cc: [],
      alicilar_bcc: [],
      parametreler: {
        gun: 3,
        aralik_gun: 3,
        saat: '09:00',
        portallar: 'logo,protel',
        islendi_sayilanlar: 'emor_islendi,emor_elle_islendi,sap_islendi',
      },
      updated_at: '2026-11-25T10:00:00+00:00',
    },
  ],
  bildirimler: [
    {
      id: 5,
      kural_turu: 'senkron_arizasi',
      ortam: 'test',
      tur: 'acildi',
      durum: 'atlandi',
      deneme: 0,
      hata_kodu: 'TEST_YONLENDIRME_YOK',
      hata_mesaji: null,
      gonderildi: null,
      olusturuldu: '2026-09-23T10:00:00+00:00',
    },
  ],
}

function ciz(izinler = ['alarm_kurallari.goruntule', 'alarm_kurallari.guncelle']) {
  render(
    <AppProviders>
      <SahteOturum izinler={izinler}>
        <AlarmKurallariPage />
      </SahteOturum>
    </AppProviders>,
  )
}

function kart(ad: string): HTMLElement {
  return screen.getByRole('form', { name: ad })
}

describe('AlarmKurallariPage', () => {
  beforeEach(() => {
    api.getir.mockResolvedValue(YANIT)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
  })

  it('üç kuralı ve atlanan bildirimin nedenini gösterir', async () => {
    ciz()

    expect(await screen.findByRole('form', { name: 'Senkron arızası' })).toBeInTheDocument()
    expect(kart('Günlük yeni fatura özeti')).toBeInTheDocument()
    expect(
      within(kart('ERP okumadı uyarısı')).getByLabelText('Alıcılar (virgülle ayırın)'),
    ).toHaveValue('muhasebe@ornek.test')
    expect(screen.getByText('Atlandı')).toBeInTheDocument()
    expect(
      screen.getByText(/yönlendirme adresi olmadığı için gerçek alıcılara gönderilmedi/),
    ).toBeInTheDocument()
  })

  it('kuralı yalnız kendi parametreleriyle ve ayrıştırılmış alıcılarla kaydeder', async () => {
    api.kaydet.mockResolvedValue(YANIT.data[0])
    ciz()
    const senkron = await screen.findByRole('form', { name: 'Senkron arızası' })

    fireEvent.click(within(senkron).getByRole('switch', { name: 'Aktif' }))
    fireEvent.change(within(senkron).getByLabelText('Alıcılar (virgülle ayırın)'), {
      target: { value: 'bt@ornek.test; muhasebe@ornek.test' },
    })
    fireEvent.change(within(senkron).getByLabelText('Art arda hatalı çalışma sayısı'), {
      target: { value: '5' },
    })
    fireEvent.click(within(senkron).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith('senkron_arizasi', {
      aktif: true,
      alicilar: ['bt@ornek.test', 'muhasebe@ornek.test'],
      alicilar_cc: [],
      alicilar_bcc: [],
      parametreler: { ardisik_hata: 5, gecikme_saat: 2 },
    })
  })

  it('işlenmemiş fatura hatırlatması portalları ve işlendi sayılanları virgüllü, günü alınma etiketiyle kaydeder; boş seçimle kaydetmez', async () => {
    api.kaydet.mockResolvedValue(YANIT.data[3])
    ciz()
    const kural = await screen.findByRole('form', { name: 'İşlenmemiş fatura hatırlatması' })
    // Hatırlatmanın günü entegratöre alınma zamanından sayılır (ERP uyarısının etiketi değil)
    expect(within(kural).getByLabelText('Alınma zamanından bu yana gün')).toHaveValue(3)
    expect(within(kural).getByRole('checkbox', { name: 'Logo' })).toBeChecked()
    expect(within(kural).getByRole('checkbox', { name: 'İzibiz' })).not.toBeChecked()
    expect(within(kural).getByRole('checkbox', { name: 'eMOR: İşlendi (elle)' })).toBeChecked()
    expect(within(kural).getByRole('checkbox', { name: 'eMOR: Havuzda' })).not.toBeChecked()

    fireEvent.click(within(kural).getByRole('checkbox', { name: 'Logo' }))
    fireEvent.click(within(kural).getByRole('checkbox', { name: 'Portal Plus (Protel)' }))
    for (const durum of ['eMOR: İşlendi', 'eMOR: İşlendi (elle)', 'SAP: İşlendi (elle)']) {
      fireEvent.click(within(kural).getByRole('checkbox', { name: durum }))
    }
    fireEvent.click(within(kural).getByRole('button', { name: 'Kaydet' }))
    expect(await within(kural).findByText('En az bir portal seçin.')).toBeInTheDocument()
    expect(within(kural).getByText('İşlendi sayılacak en az bir durum seçin.')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()

    fireEvent.click(within(kural).getByRole('checkbox', { name: 'E-Arşiv / E-SMM' }))
    fireEvent.click(within(kural).getByRole('checkbox', { name: 'İzibiz' }))
    fireEvent.click(within(kural).getByRole('checkbox', { name: 'SAP: İşlendi (elle)' }))
    fireEvent.click(within(kural).getByRole('checkbox', { name: 'eMOR: Havuzda' }))
    fireEvent.change(within(kural).getByLabelText('Kaç günde bir gönderilsin'), {
      target: { value: '4' },
    })
    fireEvent.click(within(kural).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith('islenmemis_hatirlatma', {
      aktif: false,
      alicilar: ['muhasebe@ornek.test'],
      alicilar_cc: [],
      alicilar_bcc: [],
      // Seçenek sırasıyla, seçim sırasından bağımsız
      parametreler: {
        gun: 3,
        aralik_gun: 4,
        saat: '09:00',
        portallar: 'izibiz,earsiv',
        islendi_sayilanlar: 'emor_havuzda,sap_islendi',
      },
    })
  })

  it('kopya ve gizli alıcıları ayrıştırıp gönderir; geçersiz kopya adresiyle kaydetmez', async () => {
    api.kaydet.mockResolvedValue(YANIT.data[2])
    ciz()
    const erp = await screen.findByRole('form', { name: 'ERP okumadı uyarısı' })

    fireEvent.change(within(erp).getByLabelText('Bilgi alıcıları — CC (virgülle ayırın)'), {
      target: { value: 'mudur-ornek.test' },
    })
    fireEvent.click(within(erp).getByRole('button', { name: 'Kaydet' }))
    expect(
      await within(erp).findByText('Alıcılardan biri geçerli bir e-posta adresi değil.'),
    ).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()

    fireEvent.change(within(erp).getByLabelText('Bilgi alıcıları — CC (virgülle ayırın)'), {
      target: { value: 'mudur@ornek.test; finans@ornek.test' },
    })
    fireEvent.change(within(erp).getByLabelText('Gizli alıcılar — BCC (virgülle ayırın)'), {
      target: { value: 'arsiv@ornek.test' },
    })
    fireEvent.click(within(erp).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(api.kaydet).toHaveBeenCalledTimes(1))
    expect(api.kaydet).toHaveBeenCalledWith('erp_okumadi', {
      aktif: true,
      alicilar: ['muhasebe@ornek.test'],
      alicilar_cc: ['mudur@ornek.test', 'finans@ornek.test'],
      alicilar_bcc: ['arsiv@ornek.test'],
      parametreler: { gun: 2, saat: '09:00' },
    })
  })

  it('kayıt sürerken alıcı alanları salt okunur, yanıttan sonra açılır', async () => {
    let yanitla: (kural: (typeof YANIT.data)[number]) => void = () => undefined
    api.kaydet.mockReturnValue(
      new Promise((resolve) => {
        yanitla = resolve
      }),
    )
    ciz()
    const erp = await screen.findByRole('form', { name: 'ERP okumadı uyarısı' })
    const kopya = within(erp).getByLabelText('Bilgi alıcıları — CC (virgülle ayırın)')

    fireEvent.change(kopya, { target: { value: 'mudur@ornek.test' } })
    fireEvent.click(within(erp).getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => expect(kopya).toHaveAttribute('readonly'))
    yanitla({ ...YANIT.data[2], alicilar_cc: ['mudur@ornek.test'] })
    await waitFor(() => expect(kopya).not.toHaveAttribute('readonly'))
    expect(kopya).toHaveValue('mudur@ornek.test')
  })

  it('aktif kuralı alıcısız ve hatalı saatle kaydetmez', async () => {
    ciz()
    const ozet = await screen.findByRole('form', { name: 'Günlük yeni fatura özeti' })

    fireEvent.click(within(ozet).getByRole('switch', { name: 'Aktif' }))
    fireEvent.change(within(ozet).getByLabelText('Gönderim saati (SS:DD)'), {
      target: { value: '8:5' },
    })
    fireEvent.click(within(ozet).getByRole('button', { name: 'Kaydet' }))

    expect(
      await within(ozet).findByText('Aktif kural için en az bir alıcı gerekir.'),
    ).toBeInTheDocument()
    expect(within(ozet).getByText('Saat SS:DD biçiminde olmalı (ör. 08:30).')).toBeInTheDocument()
    expect(api.kaydet).not.toHaveBeenCalled()
  })
})
