import { cleanup, fireEvent, render as rtlRender, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import '../i18n/i18n'
import { AppProviders } from '../providers/AppProviders'
import { bugunIso, gunEkle, tarihGoster } from '../utils/tarih'
import { SatinalmaTalebiPage } from './SatinalmaTalebiPage'

// Sayfa artık tasarımı API'den okuyor; testte varsayılan düzeni taklit ediyoruz
vi.mock('@/features/ekranTasarim/api', () => ({
  ekranTasariminiGetir: () => Promise.resolve(tasarimYaniti),
}))

// Testler tasarımı değiştirebilsin diye (salt okunur senaryosu)
let tasarimYaniti: typeof SAHTE_TASARIM

const KATALOG = [
  {
    anahtar: 'personel_adi',
    etiket: 'satinalma.alan.personelAdi',
    tip: 'personel',
    veri: ['personel_id', 'personel_adi'],
  },
  { anahtar: 'no', etiket: 'satinalma.alan.no', tip: 'metin', veri: ['no'] },
  { anahtar: 'tarih', etiket: 'satinalma.alan.tarih', tip: 'tarih', veri: ['tarih'] },
  { anahtar: 'termin', etiket: 'satinalma.alan.termin', tip: 'opsiyonelTarih', veri: ['termin'] },
  { anahtar: 'oncelik', etiket: 'satinalma.alan.oncelik', tip: 'oncelik', veri: ['oncelik_id'] },
  {
    anahtar: 'aciklama',
    etiket: 'satinalma.alan.aciklama',
    tip: 'sinirliMetin',
    veri: ['aciklama'],
  },
  {
    anahtar: 'ilgi_konusu',
    etiket: 'satinalma.alan.ilgiKonusu',
    tip: 'ilgiCinsi',
    veri: ['ilgi_cinsi'],
  },
  { anahtar: 'ilgili', etiket: 'satinalma.alan.ilgili', tip: 'ilgili', veri: ['ilgili_id'] },
  { anahtar: 'depo_adi', etiket: 'satinalma.alan.depoAdi', tip: 'depo', veri: ['depomuz_id'] },
  {
    anahtar: 'teslimat_adresi',
    etiket: 'satinalma.alan.teslimatAdresi',
    tip: 'teslimatAdresi',
    veri: ['teslimat_adresi_id', 'teslimat_adresi'],
  },
  {
    anahtar: 'teslimat_bicimi',
    etiket: 'satinalma.alan.teslimatBicimi',
    tip: 'teslimatBicimi',
    veri: ['teslimat_bicimi'],
  },
  {
    anahtar: 'teslimat_sekli',
    etiket: 'satinalma.alan.teslimatSekli',
    tip: 'teslimatSekli',
    veri: ['teslimat_sekli_id'],
  },
  {
    anahtar: 'teslimat_suresi_tarih',
    etiket: 'satinalma.alan.teslimatSuresiTarih',
    tip: 'teslimatSuresiTarih',
    veri: ['teslimat_suresi'],
  },
  {
    anahtar: 'teslimat_suresi_sure',
    etiket: 'satinalma.alan.teslimatSuresiSure',
    tip: 'teslimatSuresi',
    veri: ['teslimat_suresi'],
  },
  { anahtar: 'alim_yeri', etiket: 'satinalma.alan.alimYeri', tip: 'alimYeri', veri: ['alim_yeri'] },
].map((a) => ({
  anahtar: a.anahtar,
  etiket_anahtari: a.etiket,
  giris_tipi: a.tip,
  veri_anahtarlari: a.veri,
  proc_parametresi: '',
  varsayilan_genislik: 6,
  kaldirilamaz: false,
  salt_okunur_sabit: a.anahtar === 'no',
  zorunlu_secilebilir: true,
  metin_alani: a.anahtar === 'aciklama',
  metin_limiti: a.anahtar === 'aciklama' ? 200 : 0,
}))

const SAHTE_TASARIM = {
  ekran: 'satinalma.talep',
  baslik_anahtari: 'satinalma.baslik',
  bolumler: [
    { anahtar: 'talep', baslik_anahtari: 'satinalma.talepBilgileri' },
    { anahtar: 'teslimat', baslik_anahtari: 'satinalma.teslimatBilgileri' },
  ],
  katalog: KATALOG,
  duzen: {
    bolumler: [
      {
        anahtar: 'talep',
        genislik: 6,
        alanlar: [
          { alan: 'personel_adi', genislik: 6 },
          { alan: 'no', genislik: 6, salt_okunur: true },
          { alan: 'tarih', genislik: 6 },
          { alan: 'termin', genislik: 6 },
          { alan: 'oncelik', genislik: 6 },
          { alan: 'aciklama', genislik: 12 },
          { alan: 'ilgi_konusu', genislik: 6 },
          { alan: 'ilgili', genislik: 6 },
        ],
      },
      {
        anahtar: 'teslimat',
        genislik: 6,
        alanlar: [
          { alan: 'depo_adi', genislik: 6 },
          { alan: 'teslimat_adresi', genislik: 12 },
          { alan: 'teslimat_bicimi', genislik: 6 },
          { alan: 'teslimat_sekli', genislik: 6 },
          { alan: 'teslimat_suresi_tarih', genislik: 6 },
          { alan: 'teslimat_suresi_sure', genislik: 6 },
          { alan: 'alim_yeri', genislik: 6 },
        ],
      },
    ],
  },
}

function render(ui: React.ReactElement) {
  return rtlRender(<AppProviders>{ui}</AppProviders>)
}

/** Tasarım API'den geldiği için form asenkron kurulur. */
async function formuAc() {
  render(<SatinalmaTalebiPage />)
  await screen.findByText('Talep Bilgileri')
}

describe('SatinalmaTalebiPage', () => {
  afterEach(cleanup)
  beforeEach(() => {
    localStorage.clear()
    tasarimYaniti = SAHTE_TASARIM
  })

  it('tasarımdaki bölümler ve alanlar çizilir', async () => {
    await formuAc()

    expect(screen.getByRole('heading', { name: 'Satınalma Talebi' })).toBeInTheDocument()
    expect(screen.getByText('Teslimat Bilgileri')).toBeInTheDocument()

    // Tarih bugünle dolu gelir (ERP davranışı) — GG.AA.YYYY maskeli gösterim
    expect(screen.getByLabelText('Tarih')).toHaveValue(tarihGoster(bugunIso()))

    // Talep No kullanıcı tarafından yazılamaz (katalog: salt_okunur_sabit)
    expect(screen.getByLabelText('No')).toBeDisabled()

    // Grid tek boş satırla başlar; tek satır silinemez
    expect(screen.getByLabelText('Ürün kodu 1')).toBeInTheDocument()
    expect(screen.getByLabelText('Satırı sil 1')).toBeDisabled()
  })

  it('ilgi konusu varsayılan Projemiz gelir ve bağlı alan etiketi Proje olur', async () => {
    await formuAc()

    expect(
      screen.getByText('Projemiz', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText('Proje')).toBeInTheDocument()
  })

  it('seçim alanları react-select olarak basılır', async () => {
    // Regresyon: teslimat bloğu bir ara özel bileşenleri almıyordu ve
    // alanlar sessizce düz input'a düşüyordu (react-select girişi combobox'tır)
    await formuAc()

    expect(screen.getByLabelText('Personel adı')).toHaveAttribute('role', 'combobox')
    expect(screen.getByLabelText('Depo adı')).toHaveAttribute('role', 'combobox')
    expect(screen.getByLabelText('Teslimat adresi')).toHaveAttribute('role', 'combobox')
    expect(screen.getByLabelText('Teslimat biçimi')).toHaveAttribute('role', 'combobox')
    expect(screen.getByLabelText('Teslimat şekli')).toHaveAttribute('role', 'combobox')
  })

  it('salt okunur işaretli alanlar HER giriş tipinde kilitlenir', async () => {
    // Regresyon: salt_okunur yalnız düz metin alanlarında bağlanmıştı; seçim
    // alanları tıklanıp değiştirilebiliyordu. Tasarımdaki her alanı salt okunur
    // yapıp hepsinin gerçekten kilitlendiğini doğruluyoruz.
    const hepsiSaltOkunur = {
      ...SAHTE_TASARIM,
      duzen: {
        bolumler: SAHTE_TASARIM.duzen.bolumler.map((bolum) => ({
          ...bolum,
          alanlar: bolum.alanlar.map((alan) => ({ ...alan, salt_okunur: true })),
        })),
      },
    }
    tasarimYaniti = hepsiSaltOkunur

    await formuAc()

    for (const etiket of [
      'Personel adı',
      'Depo adı',
      'Teslimat adresi',
      'Teslimat biçimi',
      'Teslimat şekli',
      'Öncelik',
      'İlgi konusu',
      'Alım yeri',
      'Tarih',
      'Açıklama',
    ]) {
      const alan = screen.getByLabelText(etiket)
      expect(alan, `${etiket} salt okunur olmalı`).toBeDisabled()
    }

    tasarimYaniti = SAHTE_TASARIM
  })

  it('zorunlu işaretli alanlar yıldızla gösterilir ve kaydı engeller', async () => {
    // Regresyon: etiketini kendi üreten alanlarda (ilgili kayıt) yıldız
    // kayboluyordu — cinse göre "Proje"/"İş Paketi" olarak değiştiği için
    tasarimYaniti = {
      ...SAHTE_TASARIM,
      duzen: {
        bolumler: SAHTE_TASARIM.duzen.bolumler.map((bolum) => ({
          ...bolum,
          alanlar: bolum.alanlar.map((alan) =>
            alan.alan === 'ilgili' || alan.alan === 'depo_adi' ? { ...alan, zorunlu: true } : alan,
          ),
        })),
      },
    }

    await formuAc()

    // Etiketi katalogdan gelen alan
    expect(screen.getByLabelText('Depo adı *')).toBeInTheDocument()
    // Etiketi ilgi cinsinden üretilen alan (varsayılan cins 7 → "Proje")
    expect(screen.getByLabelText('Proje *')).toBeInTheDocument()

    // Boşken kaydet: doğrulama da gerçekten çalışıyor
    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))
    await waitFor(() => {
      expect(screen.getAllByText('Bu alan zorunludur.').length).toBeGreaterThan(0)
    })
  })

  it('teslimat biçimi varsayılan Tam (0), alım yeri Merkez (0) gelir', async () => {
    await formuAc()

    expect(screen.getByText('Tam', { selector: '.erp-select__single-value' })).toBeInTheDocument()
    expect(
      screen.getByText('Merkez', { selector: '.erp-select__single-value' }),
    ).toBeInTheDocument()
  })

  it('teslimat süresi girilince tarih alanı senkron dolar', async () => {
    await formuAc()

    const tarihAlani = screen.getByLabelText('Teslimat süresi (Tarih)')
    expect(tarihAlani).toHaveValue('')

    fireEvent.change(screen.getByLabelText('Teslimat süresi (Süre)'), { target: { value: '21' } })

    await waitFor(() => {
      expect(tarihAlani).toHaveValue(tarihGoster(gunEkle(bugunIso(), 21)))
    })
  })

  it('açıklama 200 karakterden fazlasını almaz', async () => {
    await formuAc()

    const aciklama = screen.getByLabelText('Açıklama')
    fireEvent.change(aciklama, { target: { value: 'a'.repeat(250) } })

    expect(aciklama).toHaveValue('a'.repeat(200))
    expect(screen.getByText('200/200')).toBeInTheDocument()
  })

  it('termin anahtarı açılmadan tarih girilemez', async () => {
    await formuAc()

    const terminGirisi = screen.getByLabelText('Termin', { selector: 'input[type="text"]' })
    expect(terminGirisi).toBeDisabled()

    fireEvent.click(screen.getByRole('switch', { name: 'Termin' }))
    await waitFor(() => {
      expect(terminGirisi).toBeEnabled()
    })
  })

  it('satır eklenip silinebilir', async () => {
    await formuAc()

    fireEvent.click(screen.getByRole('button', { name: 'Satır Ekle' }))
    expect(await screen.findByLabelText('Ürün kodu 2')).toBeInTheDocument()

    fireEvent.click(screen.getByLabelText('Satırı sil 2'))
    await waitFor(() => {
      expect(screen.queryByLabelText('Ürün kodu 2')).not.toBeInTheDocument()
    })
  })

  it('ürün kodu boşken kaydet hücre hatası üretir', async () => {
    await formuAc()

    fireEvent.click(screen.getByRole('button', { name: 'Kaydet' }))

    await waitFor(() => {
      expect(screen.getByLabelText('Ürün kodu 1')).toHaveAttribute('aria-invalid', 'true')
    })
  })
})
