import { cleanup, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { afterEach, describe, expect, it } from 'vitest'

import '@/i18n/i18n'
import { AppProviders } from '@/providers/AppProviders'
import type { KatalogAlani } from '@/features/ekranTasarim/types'
import { AlanGirisi, girisTipiTanimliMi } from './girisKaydi'
import { BOS_TALEP, type TalepGirdisi } from './talepSchema'

/**
 * Motorun sözleşmesi: tasarım kuralları (etiket + yıldız, hata çevirisi,
 * salt okunur) TEK yerde uygulanır. Bu test her giriş tipini tek tek gezip
 * kuralların gerçekten bağlandığını doğrular — yeni bir tip eklendiğinde
 * ortak kuralları bağlamak unutulursa burada yakalanır.
 */

const TIPLER: { tip: string; anahtar: string; veri: string[]; limit?: number }[] = [
  { tip: 'metin', anahtar: 'no', veri: ['no'] },
  { tip: 'sinirliMetin', anahtar: 'aciklama', veri: ['aciklama'], limit: 200 },
  { tip: 'personel', anahtar: 'personel_adi', veri: ['personel_id', 'personel_adi'] },
  { tip: 'tarih', anahtar: 'tarih', veri: ['tarih'] },
  { tip: 'opsiyonelTarih', anahtar: 'termin', veri: ['termin'] },
  { tip: 'oncelik', anahtar: 'oncelik', veri: ['oncelik_id'] },
  { tip: 'ilgiCinsi', anahtar: 'ilgi_konusu', veri: ['ilgi_cinsi'] },
  { tip: 'ilgili', anahtar: 'ilgili', veri: ['ilgili_id'] },
  { tip: 'depo', anahtar: 'depo_adi', veri: ['depomuz_id'] },
  { tip: 'teslimatAdresi', anahtar: 'teslimat_adresi', veri: ['teslimat_adresi_id'] },
  { tip: 'teslimatBicimi', anahtar: 'teslimat_bicimi', veri: ['teslimat_bicimi'] },
  { tip: 'teslimatSekli', anahtar: 'teslimat_sekli', veri: ['teslimat_sekli_id'] },
  { tip: 'teslimatSuresi', anahtar: 'teslimat_suresi_sure', veri: ['teslimat_suresi'] },
  { tip: 'alimYeri', anahtar: 'alim_yeri', veri: ['alim_yeri'] },
]

function katalogYap(tip: string, anahtar: string, veri: string[], limit = 0): KatalogAlani {
  return {
    anahtar,
    etiket_anahtari: `satinalma.alan.${anahtar === 'no' ? 'no' : 'aciklama'}`,
    giris_tipi: tip,
    veri_anahtarlari: veri,
    proc_parametresi: '',
    varsayilan_genislik: 6,
    kaldirilamaz: false,
    salt_okunur_sabit: false,
    zorunlu_secilebilir: true,
    metin_alani: limit > 0,
    metin_limiti: limit,
  }
}

function Sarici({ katalog, zorunlu }: { katalog: KatalogAlani; zorunlu: boolean }) {
  const form = useForm<TalepGirdisi>({ defaultValues: BOS_TALEP })

  return (
    <AlanGirisi
      katalog={katalog}
      duzen={{ alan: katalog.anahtar, genislik: 6, salt_okunur: true, zorunlu }}
      form={form}
    />
  )
}

describe('AlanGirisi — ortak kural sözleşmesi', () => {
  // Otomatik temizlik kapalı; her testten sonra DOM boşaltılır
  afterEach(cleanup)

  it.each(TIPLER)(
    '$tip: salt okunur kilidi ve zorunluluk yıldızı uygulanır',
    ({ tip, anahtar, veri, limit }) => {
      const katalog = katalogYap(tip, anahtar, veri, limit)

      render(
        <AppProviders>
          <Sarici katalog={katalog} zorunlu />
        </AppProviders>,
      )

      // Etiketin sonunda yıldız var (etiketini kendi üreten tipler dahil)
      const etiketler = screen.getAllByText(/\*$/)
      expect(etiketler.length, `${tip} için zorunluluk yıldızı basılmalı`).toBeGreaterThan(0)

      // Ana giriş öğesi kilitli
      const girisler = document.querySelectorAll('input:not([type="hidden"]), textarea')
      expect(girisler.length, `${tip} bir giriş öğesi çizmeli`).toBeGreaterThan(0)
      expect(girisler[0], `${tip} salt okunur olmalı`).toBeDisabled()
    },
  )

  it('tanımsız giriş tipi çizilmez (eski tasarım ekranı kırmaz)', () => {
    expect(girisTipiTanimliMi('olmayan_tip')).toBe(false)

    render(
      <AppProviders>
        <Sarici katalog={katalogYap('olmayan_tip', 'no', ['no'])} zorunlu={false} />
      </AppProviders>,
    )

    // Hiç giriş öğesi çizilmez (sağlayıcıların kendi DOM'u sayılmaz)
    expect(document.querySelectorAll('input, textarea, select')).toHaveLength(0)
  })
})
