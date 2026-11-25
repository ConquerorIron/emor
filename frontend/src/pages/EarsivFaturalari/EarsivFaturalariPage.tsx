import { useTranslation } from 'react-i18next'

import type { DataTableKolonu } from '@/components/DataTable'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import { tutarGoster } from '@/features/efatura/bicim'
import type { EFatura } from '@/features/efatura/efaturaApi'
import { GelenFaturaEkrani } from '@/features/efatura/GelenFaturaEkrani'
import { IceAktarimDugmesi } from '@/features/efatura/IceAktarimPenceresi'
import type { KaynakFiltresi } from '@/features/efatura/kaynakApi'
import { YuklemelerDugmesi } from '@/features/efatura/YuklemelerPenceresi'
import { useIzin } from '@/hooks/useIzin'

function Tutar({ deger }: { deger: string | null }) {
  return deger === null ? (
    <span className="text-slate-400">—</span>
  ) : (
    <span className="whitespace-nowrap tabular-nums">{tutarGoster(deger)}</span>
  )
}

/**
 * e-Arşiv / e-SMM Gelen Faturalar / Makbuzlar (EK-08): GİB'den alınan, adımıza
 * düzenlenmiş e-Arşiv faturaları ve e-Serbest Meslek Makbuzları. Muhasebe her
 * ay listeyi paylaşır; aynı liste yeniden yüklenebilir (var olan güncellenir).
 * Belge türü dosyanın başlıklarından tanınır.
 */
export function EarsivFaturalariPage() {
  const { t } = useTranslation()
  const iceAktarIzni = useIzin('earsiv_faturalari.ice_aktar')

  const turSecenekleri: SecenekOgesi[] = (['fatura', 'makbuz'] as const).map((tur) => ({
    value: tur,
    label: t(`efatura.belgeTuru.${tur}`),
  }))

  const ekKolonlar: DataTableKolonu<EFatura>[] = [
    {
      anahtar: 'belge_turu',
      baslik: t('efatura.kolon.belgeTuru'),
      siralamaAnahtari: 'belge_turu',
      render: (f) => (f.belge_turu === null ? '—' : t(`efatura.belgeTuru.${f.belge_turu}`)),
    },
    {
      anahtar: 'brut_tutar',
      baslik: t('efatura.kolon.brutTutar'),
      siralamaAnahtari: 'brut_tutar',
      hizala: 'sag',
      render: (f) => <Tutar deger={f.brut_tutar} />,
    },
    {
      anahtar: 'stopaj_tutari',
      baslik: t('efatura.kolon.stopajTutari'),
      siralamaAnahtari: 'stopaj_tutari',
      hizala: 'sag',
      render: (f) => <Tutar deger={f.stopaj_tutari} />,
    },
  ]

  return (
    <GelenFaturaEkrani
      onEk="earsiv"
      izinOneki="earsiv_faturalari"
      baslik={t('earsiv.baslik')}
      aciklama={t('earsiv.aciklama')}
      baslikEylemleri={
        <>
          <YuklemelerDugmesi onEk="earsiv" geriAlabilir={iceAktarIzni} />
          {iceAktarIzni ? <IceAktarimDugmesi onEk="earsiv" /> : null}
        </>
      }
      ekFiltreler={(filtre, degistir) => (
        <div className="w-full sm:w-36">
          <SelectField
            id="earsiv-belge-turu"
            label={t('efatura.kolon.belgeTuru')}
            options={turSecenekleri}
            value={
              filtre.belge_turu === ''
                ? null
                : (turSecenekleri.find((s) => s.value === filtre.belge_turu) ?? null)
            }
            onChange={(secim) =>
              degistir('belge_turu', (secim?.value ?? '') as KaynakFiltresi['belge_turu'])
            }
            placeholder={t('efatura.hepsi')}
            isClearable
            isSearchable={false}
          />
        </div>
      )}
      ekKolonlar={ekKolonlar}
    />
  )
}
