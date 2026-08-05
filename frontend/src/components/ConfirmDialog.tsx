import { useTranslation } from 'react-i18next'

import { Button } from './Button'
import { Modal } from './Modal'

interface ConfirmDialogProps {
  acik: boolean
  kapat: () => void
  baslik: string
  mesaj: string
  onayEtiketi: string
  yukleniyor?: boolean
  onayla: () => void
}

export function ConfirmDialog({
  acik,
  kapat,
  baslik,
  mesaj,
  onayEtiketi,
  yukleniyor = false,
  onayla,
}: ConfirmDialogProps) {
  const { t } = useTranslation()

  return (
    <Modal acik={acik} kapat={kapat} baslik={baslik}>
      <p className="text-sm text-slate-600 dark:text-slate-400">{mesaj}</p>
      <div className="mt-6 flex justify-end gap-3">
        <Button variant="secondary" onClick={kapat}>
          {t('ortak.iptal')}
        </Button>
        <Button variant="danger" yukleniyor={yukleniyor} onClick={onayla}>
          {onayEtiketi}
        </Button>
      </div>
    </Modal>
  )
}
