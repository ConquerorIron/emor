import { type QueryKey, useMutation, useQueryClient } from '@tanstack/react-query'
import { type ReactNode, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey, dogrulamaMesaji } from '@/api/errors'
import { Button } from '@/components/Button'
import { Modal } from '@/components/Modal'
import { TarihInput } from '@/components/TarihInput'
import type { FaturaYonu, SenkronDurumu } from '@/features/efatura/efaturaApi'
import { bugunIso, gunEkle, gunFarki, tarihGoster, zamanGoster } from '@/utils/tarih'

import { ILK_TARAMA_TARIHI, MANUEL_AZAMI_GUN } from './bicim'

/** Elle senkronun ekrana göre değişen kısmı (İzibiz / Protel uçları) */
type SenkronBaslatici = (aralik: { baslangic: string; bitis: string }) => Promise<void>

type Ton = 'bilgi' | 'uyari' | 'hata'

const TON_SINIFLARI: Record<Ton, string> = {
  bilgi:
    'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200',
  uyari:
    'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
  hata: 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300',
}

function Mesaj({ ton, children }: { ton: Ton; children: string }) {
  return (
    <p
      role={ton === 'bilgi' ? 'status' : 'alert'}
      className={`rounded-lg border px-3 py-2 text-sm ${TON_SINIFLARI[ton]}`}
    >
      {children}
    </p>
  )
}

/** Elle senkron aralığı: ilk tarama tarihinden önce değil, bugünü aşmaz, en çok 92 gün. */
function aralikHatasi(baslangic: string, bitis: string): string | null {
  if (baslangic === '' || bitis === '') {
    return 'efatura.senkron.dogrulama.tarihZorunlu'
  }
  if (baslangic < ILK_TARAMA_TARIHI) {
    return 'efatura.senkron.dogrulama.ilkTarihOncesi'
  }
  if (bitis > bugunIso()) {
    return 'efatura.senkron.dogrulama.gelecek'
  }
  const fark = gunFarki(baslangic, bitis)
  if (fark === null || fark < 0) {
    return 'efatura.senkron.dogrulama.sira'
  }
  if (fark + 1 > MANUEL_AZAMI_GUN) {
    return 'efatura.senkron.dogrulama.cokGenis'
  }

  return null
}

function SenkronFormu({
  kapat,
  senkronBaslat,
  durumAnahtari,
}: {
  kapat: () => void
  senkronBaslat: SenkronBaslatici
  durumAnahtari: QueryKey
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const bugun = bugunIso()
  const [baslangic, setBaslangic] = useState(() => {
    const yediGunOnce = gunEkle(bugun, -6)
    return yediGunOnce < ILK_TARAMA_TARIHI ? ILK_TARAMA_TARIHI : yediGunOnce
  })
  const [bitis, setBitis] = useState(bugun)
  const [gonderildi, setGonderildi] = useState(false)

  const hataAnahtari = aralikHatasi(baslangic, bitis)

  const baslat = useMutation({
    mutationFn: () => senkronBaslat({ baslangic, bitis }),
    onSuccess: async () => {
      toast.success(t('efatura.senkron.kuyruga'))
      await queryClient.invalidateQueries({ queryKey: durumAnahtari })
      kapat()
    },
    onError: (error: unknown) => {
      toast.error(dogrulamaMesaji(error) ?? t(apiErrorKey(error)))
    },
  })

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(olay) => {
        olay.preventDefault()
        setGonderildi(true)
        if (hataAnahtari === null) {
          baslat.mutate()
        }
      }}
    >
      <p className="text-sm text-slate-600 dark:text-slate-300">
        {t('efatura.senkron.aciklama', { gun: MANUEL_AZAMI_GUN })}
      </p>
      <div className="grid gap-4 sm:grid-cols-2">
        <TarihInput
          id="senkron-baslangic"
          label={t('efatura.baslangic')}
          value={baslangic}
          onChange={setBaslangic}
        />
        <TarihInput
          id="senkron-bitis"
          label={t('efatura.bitis')}
          value={bitis}
          onChange={setBitis}
        />
      </div>
      {gonderildi && hataAnahtari !== null ? (
        <Mesaj ton="hata">
          {t(hataAnahtari, {
            gun: MANUEL_AZAMI_GUN,
            tarih: tarihGoster(ILK_TARAMA_TARIHI),
          })}
        </Mesaj>
      ) : null}
      <div className="flex justify-end gap-3">
        <Button type="button" variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button type="submit" yukleniyor={baslat.isPending}>
          {t('efatura.senkron.baslat')}
        </Button>
      </div>
    </form>
  )
}

/**
 * Verinin nereden (Test/Canlı) ve ne zamana kadar geldiğini gösterir; eski
 * veri "güncel" gibi sunulmaz. Senkron hataları iş verisinden ayrı gösterilir.
 * Ekrana özgü düğmeler (İzibiz: "ERP Senkronla") `children` olarak verilir.
 */
export function SenkronDurumuPaneli({
  yon,
  durum,
  senkronIzni,
  senkronBaslat,
  durumAnahtari,
  kapaliMesaji,
  children,
}: {
  yon: FaturaYonu
  durum: SenkronDurumu
  /** Elle senkron düğmeleri bu izinle görünür */
  senkronIzni: boolean
  senkronBaslat: SenkronBaslatici
  /** Senkron kuyruğa alınınca tazelenecek durum sorgusu */
  durumAnahtari: QueryKey
  /** `senkron_aktif` false iken gösterilen uyarı */
  kapaliMesaji: string
  children?: ReactNode
}) {
  const { t } = useTranslation()
  const [formAcik, setFormAcik] = useState(false)

  const yonDurumu = durum.yonler?.[yon] ?? null
  const sonCalisma = yonDurumu?.son_calisma ?? null

  return (
    <section aria-label={t('efatura.durumBaslik')} className="space-y-2">
      <div className="flex flex-wrap items-center gap-3 text-sm">
        {durum.ortam !== null ? (
          <span
            className={`rounded-full px-2.5 py-0.5 text-xs font-bold ${
              durum.ortam === 'canli'
                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
            }`}
          >
            {t(`efatura.ortam.${durum.ortam}`)}
          </span>
        ) : null}
        <span className="text-slate-600 dark:text-slate-300">
          {yonDurumu?.veri_zamani
            ? t('efatura.veriZamani', { zaman: zamanGoster(yonDurumu.veri_zamani) })
            : t('efatura.veriZamaniYok')}
        </span>
        {senkronIzni ? (
          <div className="ml-auto flex flex-wrap gap-2">
            <Button
              variant="secondary"
              onClick={() => setFormAcik(true)}
              disabled={durum.ortam === null || durum.manuel_istek !== null}
            >
              {t('efatura.senkron.ac')}
            </Button>
            {children}
          </div>
        ) : null}
      </div>

      {!durum.senkron_aktif ? <Mesaj ton="uyari">{kapaliMesaji}</Mesaj> : null}

      {yonDurumu !== null && !yonDurumu.guncel ? (
        <Mesaj ton="uyari">{t('efatura.guncelDegil')}</Mesaj>
      ) : null}

      {durum.manuel_istek !== null ? (
        <Mesaj ton="bilgi">
          {t('efatura.senkron.sirada', {
            baslangic: tarihGoster(durum.manuel_istek.baslangic),
            bitis: tarihGoster(durum.manuel_istek.bitis),
          })}
        </Mesaj>
      ) : null}

      {yonDurumu?.calisiyor ? <Mesaj ton="bilgi">{t('efatura.senkron.calisiyor')}</Mesaj> : null}

      {yonDurumu !== null && yonDurumu.ardisik_hata > 0 && sonCalisma !== null ? (
        <Mesaj ton="hata">
          {t('efatura.ardisikHata', {
            sayi: yonDurumu.ardisik_hata,
            // Bilinen hata kodları çevrilir (hata.*); bilinmeyen kod olduğu gibi
            neden: sonCalisma.hata_kodu
              ? t(`hata.${sonCalisma.hata_kodu}`, { defaultValue: sonCalisma.hata_kodu })
              : (sonCalisma.eksik_nedeni ?? sonCalisma.durum),
            zaman: zamanGoster(sonCalisma.basladi),
          })}
        </Mesaj>
      ) : null}

      <Modal acik={formAcik} kapat={() => setFormAcik(false)} baslik={t('efatura.senkron.baslik')}>
        {formAcik ? (
          <SenkronFormu
            kapat={() => setFormAcik(false)}
            senkronBaslat={senkronBaslat}
            durumAnahtari={durumAnahtari}
          />
        ) : null}
      </Modal>
    </section>
  )
}
