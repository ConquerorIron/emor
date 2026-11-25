import { useTranslation } from 'react-i18next'

import { GelenFaturaEkrani } from '@/features/efatura/GelenFaturaEkrani'
import { IceAktarimDugmesi } from '@/features/efatura/IceAktarimPenceresi'
import { YuklemelerDugmesi } from '@/features/efatura/YuklemelerPenceresi'
import { useIzin } from '@/hooks/useIzin'

/**
 * Logo Gelen Faturalar (EK-07): kapanan Logo portalının 2026 gelen faturaları.
 * Portal kapalı olduğu için yeni fatura gelmez; muhasebenin Excel listesi bir
 * kez (gerekirse düzeltilerek yeniden) içe aktarılır. RED olanlar listede
 * "Red"dir; eMOR ve SAP'de işlenip işlenmediği diğer ekranlardaki gibi izlenir.
 */
export function LogoFaturalariPage() {
  const { t } = useTranslation()
  const iceAktarIzni = useIzin('logo_faturalari.ice_aktar')

  return (
    <GelenFaturaEkrani
      onEk="logo"
      izinOneki="logo_faturalari"
      baslik={t('logo.baslik')}
      aciklama={t('logo.aciklama')}
      baslikEylemleri={
        <>
          <YuklemelerDugmesi onEk="logo" geriAlabilir={iceAktarIzni} />
          {iceAktarIzni ? <IceAktarimDugmesi onEk="logo" /> : null}
        </>
      }
    />
  )
}
