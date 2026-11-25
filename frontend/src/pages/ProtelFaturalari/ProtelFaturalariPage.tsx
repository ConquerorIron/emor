import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { queryKeys } from '@/api/queryKeys'
import type { DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'
import type { EFatura, ErpOkunduFiltresi } from '@/features/efatura/efaturaApi'
import { GelenFaturaEkrani } from '@/features/efatura/GelenFaturaEkrani'
import { EvetHayirRozeti, Metin } from '@/features/efatura/ListeParcalari'
import { protelDurumuGetir, protelSenkronBaslat } from '@/features/efatura/protelApi'
import { SenkronDurumuPaneli } from '@/features/efatura/SenkronDurumuPaneli'
import { useDurumlaTazele } from '@/features/efatura/useFaturaListesi'
import { useIzin } from '@/hooks/useIzin'

const ERP_FILTRELERI: readonly ErpOkunduFiltresi[] = ['evet', 'hayir', 'bilinmiyor']

/** Protel `erpStatus` değerleri (Swagger enum'u); bilinmeyen değer olduğu gibi gösterilir */
const ERP_DURUMLARI = ['New', 'Processing', 'Completed', 'Cancelled', 'Error', 'Booked'] as const

/**
 * Protel Gelen Faturalar (PRT-12) — otelin e-Fatura posta kutusu. Protel'de
 * hiçbir alan değiştirilmez: "İşlendi" (SAP) ve RED yalnız eMOR'a yazar.
 * Liste ortak ekrandır; burada yalnız Protel'e özgü senkron paneli, otelin
 * ERP'sinin aldığı bilgi ve portalın ERP durumu var.
 */
export function ProtelFaturalariPage() {
  const { t } = useTranslation()
  const senkronIzni = useIzin('protel_faturalari.senkron')

  const durum = useQuery({
    queryKey: queryKeys.protel.durum,
    queryFn: protelDurumuGetir,
    refetchInterval: (sorgu) => {
      const veri = sorgu.state.data
      const suruyor = veri?.manuel_istek != null || (veri?.yonler?.gelen?.calisiyor ?? false)

      return suruyor ? 5_000 : 60_000
    },
  })
  // Yeni senkron bitince / ortam değişince liste tazelenir (İzibiz ekranıyla aynı)
  useDurumlaTazele(durum.data, 'gelen', queryKeys.kaynak.listeler('protel'))

  const erpSecenekleri: SecenekOgesi[] = ERP_FILTRELERI.map((d) => ({
    value: d,
    label: t(`efatura.${d}`),
  }))

  // Otelin ERP'si (yalnız gösterilir; bu uygulama değiştirmez)
  const ekKolonlar: DataTableKolonu<EFatura>[] = [
    {
      anahtar: 'otel_erp_okundu',
      baslik: t('protel.kolon.erpAldi'),
      siralamaAnahtari: 'otel_erp_okundu',
      hizala: 'orta',
      render: (f) => <EvetHayirRozeti deger={f.otel_erp_okundu} />,
    },
    {
      anahtar: 'erp_durumu',
      baslik: t('protel.kolon.erpDurumu'),
      siralamaAnahtari: 'erp_durumu',
      render: (f) => (
        <Metin
          deger={
            f.erp_durumu === null
              ? null
              : (ERP_DURUMLARI as readonly string[]).includes(f.erp_durumu)
                ? t(`protel.erpDurumu.${f.erp_durumu}`)
                : f.erp_durumu
          }
        />
      ),
    },
    {
      anahtar: 'yazdirildi',
      baslik: t('protel.kolon.yazdirildi'),
      siralamaAnahtari: 'yazdirildi',
      hizala: 'orta',
      render: (f) => <EvetHayirRozeti deger={f.yazdirildi} />,
    },
    {
      anahtar: 'irsaliye_no',
      baslik: t('efatura.kolon.irsaliyeNo'),
      siralamaAnahtari: 'irsaliye_no',
      render: (f) => <Metin deger={f.irsaliye_no} />,
    },
  ]

  return (
    <GelenFaturaEkrani
      onEk="protel"
      izinOneki="protel_faturalari"
      baslik={t('protel.baslik')}
      aciklama={t('protel.aciklama')}
      durumAlani={
        <>
          {durum.data ? (
            <SenkronDurumuPaneli
              yon="gelen"
              durum={durum.data}
              senkronIzni={senkronIzni}
              senkronBaslat={protelSenkronBaslat}
              durumAnahtari={queryKeys.protel.durum}
              kapaliMesaji={t('protel.modulKapali')}
            />
          ) : null}
          {durum.isError ? (
            <ErrorState
              mesaj={t('efatura.durumAlinamadi')}
              tekrarDene={() => void durum.refetch()}
            />
          ) : null}
        </>
      }
      ekFiltreler={(filtre, degistir) => (
        <div className="w-full sm:w-36">
          <SelectField
            id="protel-erp"
            label={t('protel.kolon.erpAldi')}
            options={erpSecenekleri}
            value={
              filtre.otel_erp_okundu === ''
                ? null
                : (erpSecenekleri.find((s) => s.value === filtre.otel_erp_okundu) ?? null)
            }
            onChange={(secim) =>
              degistir('otel_erp_okundu', (secim?.value ?? '') as ErpOkunduFiltresi)
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
