import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '@/i18n/i18n'
import type { IslemGecmisiListesi } from '@/features/efatura/islemGecmisiApi'
import { AppProviders } from '@/providers/AppProviders'
import { SahteOturum } from '@/test/SahteOturum'

import { EFaturaIslemGecmisiPage } from './EFaturaIslemGecmisiPage'

const api = vi.hoisted(() => ({ gecmis: vi.fn() }))

vi.mock('@/features/efatura/islemGecmisiApi', () => ({ islemGecmisiGetir: api.gecmis }))

const YANIT: IslemGecmisiListesi = {
  data: [
    {
      id: 3,
      fatura_id: 21,
      yukleme_id: null,
      saglayici: 'protel',
      belge_no: 'OTL2026000000001',
      islem: 'red_geri_alindi',
      onceki: 'Noter kanalıyla iptal',
      yeni: null,
      neden: null,
      ayrinti: null,
      kullanici_adi: 'Ayşe Muhasebe',
      zaman: '2026-11-25T07:00:00+00:00',
    },
    {
      id: 2,
      fatura_id: null,
      yukleme_id: null,
      saglayici: 'logo',
      belge_no: null,
      islem: 'ice_aktarildi',
      onceki: null,
      yeni: null,
      neden: null,
      ayrinti: { dosya_adi: 'logo.xlsx', yeni: 581, guncellenen: 0, degismeyen: 0 },
      kullanici_adi: 'Ali Veli',
      zaman: '2026-11-24T07:00:00+00:00',
    },
  ],
  meta: { current_page: 1, last_page: 1, total: 2, from: 1 },
  secenekler: { kullanicilar: [{ id: 5, ad: 'Ali Veli' }] },
}

describe('EFaturaIslemGecmisiPage', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-11-25T09:00:00'))
    api.gecmis.mockResolvedValue(YANIT)
  })

  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('son 30 günün işlemlerini ister; işlem, kaynak ve ayrıntıyı okunur gösterir', async () => {
    render(
      <AppProviders>
        <SahteOturum izinler={['efatura_islem_gecmisi.goruntule']}>
          <EFaturaIslemGecmisiPage />
        </SahteOturum>
      </AppProviders>,
    )

    await waitFor(() =>
      expect(api.gecmis).toHaveBeenCalledWith(
        {
          baslangic: '2026-10-27',
          bitis: '2026-11-25',
          kullanici_id: '',
          islem: '',
          saglayici: '',
          ara: '',
        },
        1,
      ),
    )
    expect(await screen.findByText("RED'i geri alındı")).toBeInTheDocument()
    expect(screen.getByText('RED nedeni: Noter kanalıyla iptal')).toBeInTheDocument()
    expect(
      screen.getByText('logo.xlsx — 581 yeni, 0 güncellenen, 0 değişmeyen'),
    ).toBeInTheDocument()
    expect(screen.getByText('Ayşe Muhasebe')).toBeInTheDocument()
    expect(screen.getByText('Protel')).toBeInTheDocument()
  })
})
