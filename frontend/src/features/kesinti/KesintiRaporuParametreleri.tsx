import { useTranslation } from 'react-i18next'

import { MultiSelectField, SelectField, type SecenekOgesi } from '@/components/SelectField'
import { TarihInput } from '@/components/TarihInput'

import { adSecenekleri, kodSecenekleri } from './filtreSecenekleri'
import type { KesintiRaporuDurumu } from './kesintiRaporu'

/**
 * Kesinti raporlarının parametre alanları: tarih ("itibarıyla"), çoklu proje,
 * çoklu firma, (istenirse) çoklu kesinti türü, para birimi. Değişince rapor
 * anında yenilenir (kalıcı değil).
 */
export function KesintiRaporuParametreleri({
  durum,
  idOneki,
  kesintiTuruSuzgeci = false,
}: {
  durum: KesintiRaporuDurumu
  /** Element kimlikleri (ör. "kesinti-ozeti") */
  idOneki: string
  /** Kesinti türü süzgeci (Kesinti Özeti; kullanıcı isteği 2026-11-16) */
  kesintiTuruSuzgeci?: boolean
}) {
  const { t } = useTranslation()
  const { secenekler } = durum
  // Proje adıyla, firma ve kesinti türü koduyla (kullanıcı isteği 2026-11-16)
  const projeSecenekleri = adSecenekleri(secenekler.data?.projeler)
  const firmaSecenekleri = kodSecenekleri(secenekler.data?.firmalar)
  const turSecenekleri = kodSecenekleri(secenekler.data?.kesinti_turleri)
  const paraSecenekleri: SecenekOgesi[] = (secenekler.data?.para_birimleri ?? []).map((para) => ({
    value: String(para.id),
    label: para.kod,
  }))

  return (
    <div
      className={`mt-4 grid gap-3 sm:grid-cols-2 ${
        kesintiTuruSuzgeci
          ? 'lg:grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_10rem]'
          : 'lg:grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)_10rem]'
      }`}
    >
      <TarihInput
        id={`${idOneki}-tarih`}
        label={t('kesinti.raporParametreleri.tarih')}
        value={durum.tarih}
        onChange={durum.setTarih}
        hata={durum.tarih === '' ? t('kesinti.raporParametreleri.tarihZorunlu') : undefined}
      />
      <MultiSelectField
        id={`${idOneki}-projeler`}
        label={t('kesinti.raporParametreleri.projeler')}
        options={projeSecenekleri}
        value={projeSecenekleri.filter((s) => durum.projeIdleri.includes(Number(s.value)))}
        onChange={(secilenler) => durum.setProjeIdleri(secilenler.map((s) => Number(s.value)))}
        placeholder={t('ortak.hepsi')}
      />
      <MultiSelectField
        id={`${idOneki}-firmalar`}
        label={t('kesinti.raporParametreleri.firmalar')}
        options={firmaSecenekleri}
        value={firmaSecenekleri.filter((s) => durum.firmaIdleri.includes(Number(s.value)))}
        onChange={(secilenler) => durum.setFirmaIdleri(secilenler.map((s) => Number(s.value)))}
        placeholder={t('ortak.hepsi')}
      />
      {kesintiTuruSuzgeci ? (
        <MultiSelectField
          id={`${idOneki}-kesinti-turleri`}
          label={t('kesinti.raporParametreleri.kesintiTurleri')}
          options={turSecenekleri}
          value={turSecenekleri.filter((s) => durum.turIdleri.includes(Number(s.value)))}
          onChange={(secilenler) => durum.setTurIdleri(secilenler.map((s) => Number(s.value)))}
          placeholder={t('ortak.hepsi')}
        />
      ) : null}
      <SelectField
        id={`${idOneki}-para-birimi`}
        label={t('kesinti.alan.para_birimi')}
        options={paraSecenekleri}
        value={paraSecenekleri.find((s) => Number(s.value) === durum.seciliPara) ?? null}
        onChange={(secenek) => durum.setParaBirimiId(secenek ? Number(secenek.value) : null)}
        yukleniyor={secenekler.isPending}
      />
    </div>
  )
}
