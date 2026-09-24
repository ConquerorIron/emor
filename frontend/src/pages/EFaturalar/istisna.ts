import type { EFatura } from '@/features/efatura/efaturaApi'

const kume = (kodlar: string) =>
  [
    ...new Set(
      kodlar
        .split(',')
        .map((kod) => kod.trim())
        .filter(Boolean),
    ),
  ]
    .sort()
    .join(',')

/**
 * Entegratördeki (UBL) ve ERP'deki vergi istisna kodu ikisi de biliniyor ve
 * farklıysa true — ERP'deki kod bazen hatalı olabiliyor (kullanıcı notu 2026-09-24).
 */
export function istisnaKoduFarkli(
  fatura: Pick<EFatura, 'izibiz_istisna_kodu' | 'vergi_istisna_kodu'>,
): boolean {
  const { izibiz_istisna_kodu: entegrator, vergi_istisna_kodu: erp } = fatura

  return Boolean(entegrator) && Boolean(erp) && kume(entegrator ?? '') !== kume(erp ?? '')
}
