import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useForm } from 'react-hook-form'
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

vi.mock('@/formAlanlari/veri/secenekApi', async (asliniAl) => ({
  ...(await asliniAl<typeof import('@/formAlanlari/veri/secenekApi')>()),
  aktiviteSecenekleriGetir: () => Promise.resolve(AKTIVITELER),
}))

function alanBul(hucre: TalepAlani['hucre']): TalepAlani {
  const alan = SATIR_ALANLARI.find((a) => a.hucre === hucre)
  if (!alan) {
    throw new Error(`${hucre} kolonu tanımlı değil`)
  }

  return alan
}

/** Aynı forma bağlı iki yüz — gerçek ızgaradaki gibi tek satırı paylaşırlar */
function IkiYuz({ projemizId = '34924' }: { projemizId?: string }) {
  const form = useForm<TalepGirdisi>({ defaultValues: BOS_TALEP })

  return (
    <>
      <SatirHucresi
        alan={alanBul('aktiviteKodu')}
        indeks={0}
        form={form}
        projemizId={projemizId}
        projeKodu="PRJ-1"
      />
      <SatirHucresi
        alan={alanBul('aktiviteAciklamasi')}
        indeks={0}
        form={form}
        projemizId={projemizId}
        projeKodu="PRJ-1"
      />
      <output data-testid="aktivite-id">{form.watch('satirlar.0.aktivite_id')}</output>
    </>
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
      expect(screen.getByTestId('aktivite-id')).toHaveTextContent('15805')
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
      expect(screen.getByTestId('aktivite-id')).toHaveTextContent('15804')
    })
    expect(
      screen.getByText('A.01.01.01', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
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
