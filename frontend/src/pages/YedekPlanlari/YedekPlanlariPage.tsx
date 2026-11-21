import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { Modal } from '@/components/Modal'
import { SaltOkunurUyarisi } from '@/components/SaltOkunurUyarisi'
import { hataGovdesi, istanbulZamani } from '@/features/yedekleme/bicim'
import { hedefleriGetir } from '@/features/yedekleme/hedefApi'
import { IstekSeridi } from '@/features/yedekleme/IstekSeridi'
import { Rozet } from '@/features/yedekleme/Rozet'
import { useIstekTakibi } from '@/features/yedekleme/useIstekTakibi'
import { useIzin } from '@/hooks/useIzin'

import { planCalistir, planlariGetir, planSil, type Plan } from './planApi'
import { PlanFormu } from './PlanFormu'
import { saklamaOzeti, siklikOzeti } from './planBicimi'

export function YedekPlanlariPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const guncelleyebilir = useIzin('yedek_planlari.guncelle')
  const { calisanlar, calisiyor, baslat } = useIstekTakibi('planlar')
  // null: kapalı; 'yeni': yeni plan; Plan: düzenleme
  const [formdaki, setFormdaki] = useState<Plan | 'yeni' | null>(null)
  const [silinecek, setSilinecek] = useState<Plan | null>(null)

  const planlar = useQuery({ queryKey: queryKeys.yedekleme.planlar, queryFn: planlariGetir })
  const hedefler = useQuery({ queryKey: queryKeys.yedekleme.hedefler, queryFn: hedefleriGetir })

  const calistir = useMutation({
    mutationFn: (plan: Plan) => planCalistir(plan.id),
    onSuccess: (kabul) => baslat(kabul, 'plan_calistir'),
    onError: (error: unknown) => toast.error(t(apiErrorKey(error))),
  })

  const sil = useMutation({
    mutationFn: (plan: Plan) => planSil(plan.id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.yedekleme.planlar })
      toast.success(t('yedekleme.plan.silindi'))
    },
    onError: (error: unknown) => {
      // Kopyası olan plan silinmez: geçmişi durur; pasife alınması önerilir
      const govde = hataGovdesi<{ kopya_sayisi?: number }>(error)
      toast.error(t(apiErrorKey(error), { kopya: govde?.kopya_sayisi ?? 0 }))
    },
    onSettled: () => setSilinecek(null),
  })

  const tumKolonlar: DataTableKolonu<Plan>[] = [
    {
      anahtar: 'ad',
      baslik: t('yedekleme.plan.ad'),
      render: (plan) => (
        <div className="flex flex-wrap items-center gap-2">
          <strong>{plan.ad}</strong>
          {plan.aktif ? (
            <Rozet renk="yesil">{t('yedekleme.plan.etkin')}</Rozet>
          ) : (
            <Rozet renk="gri">{t('yedekleme.plan.pasif')}</Rozet>
          )}
        </div>
      ),
    },
    {
      anahtar: 'siklik',
      baslik: t('yedekleme.plan.siklik'),
      render: (plan) => (
        <div className="space-y-1">
          <p>{siklikOzeti(plan, t)}</p>
          {plan.ay_sonu_uyarisi ? (
            <Rozet renk="sari" title={t('yedekleme.plan.aySonuUyarisi')}>
              {t('yedekleme.plan.aySonuRozet')}
            </Rozet>
          ) : null}
        </div>
      ),
    },
    {
      anahtar: 'hedefler',
      baslik: t('yedekleme.plan.hedeflerVeSaklama'),
      render: (plan) => (
        <ul className="space-y-1">
          {plan.hedefler.map((hedef) => (
            <li key={hedef.hedef_id}>
              <span className="font-semibold">{hedef.ad}</span>
              <span className="text-xs text-slate-500 dark:text-slate-400">
                {' — '}
                {saklamaOzeti(hedef, t)}
              </span>
            </li>
          ))}
        </ul>
      ),
    },
    {
      anahtar: 'son_calisma',
      baslik: t('yedekleme.plan.sonCalisma'),
      render: (plan) => (
        <span className="whitespace-nowrap tabular-nums">{istanbulZamani(plan.son_calisma)}</span>
      ),
    },
    {
      anahtar: 'sonraki_calisma',
      baslik: t('yedekleme.plan.sonrakiCalisma'),
      render: (plan) =>
        plan.aktif ? (
          <span className="whitespace-nowrap tabular-nums">
            {istanbulZamani(plan.sonraki_calisma)}
          </span>
        ) : (
          <span className="text-slate-400">{t('yedekleme.plan.pasif')}</span>
        ),
    },
    {
      anahtar: 'islemler',
      baslik: '',
      hizala: 'sag',
      render: (plan) => (
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            variant="secondary"
            disabled={calisiyor}
            yukleniyor={calistir.isPending && calistir.variables.id === plan.id}
            onClick={() => calistir.mutate(plan)}
          >
            {t('yedekleme.plan.simdiCalistir')}
          </Button>
          <Button variant="turuncu" onClick={() => setFormdaki(plan)}>
            {t('ortak.duzenle')}
          </Button>
          <Button variant="danger" onClick={() => setSilinecek(plan)}>
            {t('ortak.sil')}
          </Button>
        </div>
      ),
    },
  ]
  // Yalnız görüntüleme izninde işlem kolonu çizilmez
  const kolonlar = guncelleyebilir
    ? tumKolonlar
    : tumKolonlar.filter((kolon) => kolon.anahtar !== 'islemler')

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-2xl font-bold">{t('yedekleme.plan.baslik')}</h2>
        {guncelleyebilir ? (
          <Button variant="mor" disabled={!hedefler.data} onClick={() => setFormdaki('yeni')}>
            {t('yedekleme.plan.yeni')}
          </Button>
        ) : null}
      </div>
      <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
        {t('yedekleme.plan.aciklamaMetni')}
      </p>

      {guncelleyebilir ? null : <SaltOkunurUyarisi />}
      <IstekSeridi calisanlar={calisanlar} />

      <div className="mt-4">
        {planlar.isError ? (
          <ErrorState
            mesaj={t(apiErrorKey(planlar.error))}
            tekrarDene={() => void planlar.refetch()}
          />
        ) : (
          <DataTable
            kolonlar={kolonlar}
            satirlar={planlar.data ?? []}
            satirAnahtari={(plan) => plan.id}
            yukleniyor={planlar.isPending}
          />
        )}
      </div>

      <Modal
        acik={formdaki !== null}
        kapat={() => setFormdaki(null)}
        boyut="genis"
        baslik={t(formdaki === 'yeni' ? 'yedekleme.plan.yeni' : 'yedekleme.plan.duzenle')}
      >
        {formdaki !== null && hedefler.data ? (
          <PlanFormu
            key={formdaki === 'yeni' ? 'yeni' : formdaki.id}
            plan={formdaki === 'yeni' ? null : formdaki}
            hedefler={hedefler.data}
            kapat={() => setFormdaki(null)}
          />
        ) : null}
      </Modal>

      <ConfirmDialog
        acik={silinecek !== null}
        kapat={() => setSilinecek(null)}
        baslik={t('yedekleme.plan.silOnayBaslik')}
        mesaj={t('yedekleme.plan.silOnayMesaj', { ad: silinecek?.ad ?? '' })}
        onayEtiketi={t('ortak.sil')}
        yukleniyor={sil.isPending}
        onayla={() => {
          if (silinecek !== null) {
            sil.mutate(silinecek)
          }
        }}
      />
    </>
  )
}
