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

vi.mock('@/features/ayarlar/alarmApi', () => ({
  alarmKurallariniGetir: api.getir,
  alarmKuraliKaydet: api.kaydet,
}))

const YANIT: AlarmKurallariYaniti = {
  data: [
    {
      tur: 'senkron_arizasi',
      aktif: false,
      alicilar: [],
      parametreler: { ardisik_hata: 3, gecikme_saat: 2 },
      updated_at: '2026-09-23T10:00:00+00:00',
    },
    {
      tur: 'gunluk_ozet',
      aktif: false,
      alicilar: [],
      parametreler: { saat: '08:00' },
      updated_at: '2026-09-23T10:00:00+00:00',
    },
    {
      tur: 'erp_okumadi',
      aktif: true,
      alicilar: ['muhasebe@ornek.test'],
      parametreler: { gun: 2, saat: '09:00' },
      updated_at: '2026-09-23T10:00:00+00:00',
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
      parametreler: { ardisik_hata: 5, gecikme_saat: 2 },
    })
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
