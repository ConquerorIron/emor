/**
 * Hızlı filtre düğmesi: basılıyken dolu mavi, tekrar basınca kapanır.
 * e-Fatura (İzibiz / Tüm Faturalar) ve Protel ekranları ortak kullanır.
 */
export function HizliFiltre({
  aktif,
  onClick,
  children,
}: {
  aktif: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      aria-pressed={aktif}
      onClick={onClick}
      className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold transition-colors ${
        aktif
          ? 'border-blue-600 bg-blue-600 text-white hover:bg-blue-700 dark:border-blue-500 dark:bg-blue-600'
          : 'border-slate-300 text-slate-700 hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-blue-950 dark:hover:text-blue-300'
      }`}
    >
      {children}
    </button>
  )
}
