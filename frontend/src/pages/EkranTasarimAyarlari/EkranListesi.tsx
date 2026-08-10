import { useTranslation } from 'react-i18next'

import { Button } from '@/components/Button'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
import { TASARLANABILIR_EKRANLAR } from '@/features/ekranTasarim/ekranlar'

type Ekran = (typeof TASARLANABILIR_EKRANLAR)[number]

/**
 * Tasarlanabilir ekranların listesi.
 *
 * Tasarım tek ekrana ait değil: her ekranın kendi düzeni, onay rolü ve sürüm
 * geçmişi var. Bu yüzden editöre doğrudan girilmez — önce hangi ekranın
 * tasarlandığı seçilir, böylece ekranda "neyi düzenliyorum" belirsiz kalmaz.
 */
export function EkranListesi({ sec }: { sec: (ekran: string) => void }) {
  const { t } = useTranslation()

  const kolonlar: DataTableKolonu<Ekran>[] = [
    {
      anahtar: 'ekran',
      baslik: t('tasarim.ekran'),
      render: (satir) => (
        <span className="font-semibold text-slate-800 dark:text-slate-100">
          {t(satir.etiketAnahtari)}
        </span>
      ),
    },
    {
      anahtar: 'anahtar',
      baslik: t('tasarim.ekranAnahtari'),
      render: (satir) => (
        <span className="text-xs text-slate-400 dark:text-slate-500">{satir.anahtar}</span>
      ),
    },
    {
      anahtar: 'islem',
      baslik: '',
      hizala: 'sag',
      render: (satir) => (
        <Button type="button" variant="mor" onClick={() => sec(satir.anahtar)}>
          {t('tasarim.tasarla')}
        </Button>
      ),
    },
  ]

  return (
    <div className="mt-4">
      <DataTable
        kolonlar={kolonlar}
        satirlar={[...TASARLANABILIR_EKRANLAR]}
        satirAnahtari={(satir) => satir.anahtar}
      />
    </div>
  )
}
