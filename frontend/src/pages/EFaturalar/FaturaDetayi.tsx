import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'

import type { EFatura } from '@/features/efatura/efaturaApi'
import { tarihGoster, zamanGoster } from '@/utils/tarih'

import { tutarGoster } from './bicim'
import { istisnaKoduFarkli } from './istisna'

function Satir({ etiket, children }: { etiket: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-3 gap-3 border-b border-slate-100 py-2 last:border-b-0 dark:border-slate-800">
      <dt className="text-sm font-semibold text-slate-500 dark:text-slate-400">{etiket}</dt>
      <dd className="col-span-2 text-sm break-words text-slate-800 dark:text-slate-100">
        {children}
      </dd>
    </div>
  )
}

/**
 * Taraf unvanı yoksa (şahıs, TCKN) ad soyadı gösterilir; ikisi de varsa ad
 * soyad unvanın altında durur.
 */
function Taraf({
  unvan,
  adSoyad,
  vkn,
}: {
  unvan: string | null
  adSoyad: string | null
  vkn: string | null
}) {
  return (
    <>
      {unvan ?? adSoyad ?? '—'}
      {unvan && adSoyad ? <span className="block">{adSoyad}</span> : null}
      {vkn ? <span className="block text-xs text-slate-500 dark:text-slate-400">{vkn}</span> : null}
    </>
  )
}

/**
 * Faturanın tüm özet alanları: kaynak kimlikleri (İzibiz kimliği + ETTN),
 * ham durum kodu ile açıklaması yan yana — neyin nereden geldiği anlaşılsın.
 */
export function FaturaDetayi({ fatura }: { fatura: EFatura }) {
  const { t } = useTranslation()
  const bayrak = (deger: boolean | null) =>
    deger === null ? t('efatura.bilinmiyor') : deger ? t('efatura.evet') : t('efatura.hayir')

  return (
    <dl>
      <Satir etiket={t('efatura.alan.belgeNo')}>{fatura.belge_no}</Satir>
      <Satir etiket={t('efatura.alan.ettn')}>
        <span className="font-mono text-xs">{fatura.ettn}</span>
      </Satir>
      <Satir etiket={t('efatura.alan.belgeTarihi')}>
        {tarihGoster(fatura.belge_tarihi)}
        {fatura.belge_saati ? ` ${fatura.belge_saati}` : ''}
      </Satir>
      <Satir etiket={t('efatura.alan.olusturmaZamani')}>
        {zamanGoster(fatura.olusturma_zamani, { saniye: true })}
      </Satir>
      <Satir etiket={t('efatura.alan.tipSenaryo')}>
        {[fatura.fatura_tipi, fatura.senaryo].filter(Boolean).join(' / ') || '—'}
      </Satir>
      <Satir etiket={t('efatura.alan.gonderici')}>
        <Taraf
          unvan={fatura.gonderici_unvan}
          adSoyad={fatura.gonderici_ad_soyad}
          vkn={fatura.gonderici_vkn}
        />
      </Satir>
      <Satir etiket={t('efatura.alan.alici')}>
        <Taraf unvan={fatura.alici_unvan} adSoyad={fatura.alici_ad_soyad} vkn={fatura.alici_vkn} />
      </Satir>
      <Satir etiket={t('efatura.alan.tutar')}>
        {tutarGoster(fatura.tutar)} {fatura.para_birimi}
      </Satir>
      <Satir etiket={t('efatura.alan.vergi')}>
        {tutarGoster(fatura.vergi_tutari)} {fatura.vergi_tutari !== null ? fatura.para_birimi : ''}
      </Satir>
      <Satir etiket={t('efatura.alan.satirSayisi')}>{fatura.satir_sayisi ?? '—'}</Satir>
      <Satir etiket={t('efatura.alan.durum')}>
        {fatura.durum_aciklamasi ?? '—'}
        {fatura.durum ? (
          <span className="block font-mono text-xs text-slate-500 dark:text-slate-400">
            {fatura.durum}
          </span>
        ) : null}
      </Satir>
      <Satir etiket={t('efatura.alan.gibDurum')}>
        {fatura.gib_durum_aciklamasi ?? '—'}
        {fatura.gib_durum_kodu !== null ? (
          <span className="block font-mono text-xs text-slate-500 dark:text-slate-400">
            {fatura.gib_durum_kodu}
          </span>
        ) : null}
      </Satir>
      {fatura.yanit_aciklamasi ? (
        <Satir etiket={t('efatura.alan.yanit')}>{fatura.yanit_aciklamasi}</Satir>
      ) : null}
      {(
        [
          ['efatura.kolon.gondericiBilgisi', fatura.gonderici_etiketi],
          ['efatura.kolon.aliciBilgisi', fatura.alici_etiketi],
          ['efatura.kolon.istisnaKoduEntegrator', fatura.izibiz_istisna_kodu],
          ['efatura.kolon.istisnaKoduErp', fatura.vergi_istisna_kodu],
          ['efatura.kolon.irsaliyeNo', fatura.irsaliye_no],
          [
            'efatura.kolon.siparisNo',
            fatura.siparis_no &&
              [fatura.siparis_no, fatura.siparis_tarihi && tarihGoster(fatura.siparis_tarihi)]
                .filter(Boolean)
                .join(' / '),
          ],
          ['efatura.kolon.gtbRefNo', fatura.gtb_ref_no],
          ['efatura.kolon.gcbTescilNo', fatura.gcb_tescil_no],
          ['efatura.kolon.gcbTarihi', fatura.gcb_tarihi],
          ['efatura.kolon.portalNotu', fatura.portal_notu],
        ] as const
      ).map(([anahtar, deger]) =>
        deger ? (
          <Satir key={anahtar} etiket={t(anahtar)}>
            {deger}
          </Satir>
        ) : null,
      )}
      {istisnaKoduFarkli(fatura) ? (
        <Satir etiket={t('efatura.kolon.vergiIstisnaKodu')}>
          <span className="font-semibold text-red-700 dark:text-red-300">
            {t('efatura.istisnaFarkli')}
          </span>
        </Satir>
      ) : null}
      <Satir etiket={t('efatura.kolon.emor')}>
        {fatura.emor_durumu === null
          ? t('efatura.emor.bilinmiyor')
          : t(`efatura.emor.${fatura.emor_durumu}`)}
        {fatura.emor_durumu !== null && fatura.emor_durumu !== 'yok' ? (
          <span className="block text-xs text-slate-500 dark:text-slate-400">
            {t(`efatura.emor.aciklama.${fatura.emor_durumu}`)}
          </span>
        ) : null}
      </Satir>
      <Satir etiket={t('efatura.alan.erpOkundu')}>
        {bayrak(fatura.erp_okundu)}
        <span className="block text-xs text-slate-500 dark:text-slate-400">
          {t('efatura.erpOkunduNotu')}
        </span>
      </Satir>
      <Satir etiket={t('efatura.alan.portalOkundu')}>{bayrak(fatura.okundu)}</Satir>
      <Satir etiket={t('efatura.alan.kaynakId')}>
        <span className="font-mono text-xs">{fatura.kaynak_id}</span>
      </Satir>
      <Satir etiket={t('efatura.alan.sonGorulme')}>{zamanGoster(fatura.son_gorulme)}</Satir>
    </dl>
  )
}
