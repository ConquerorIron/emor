import { Switch as HeadlessSwitch } from '@headlessui/react'

interface SwitchProps {
  id?: string
  label: string
  checked: boolean
  onChange: (deger: boolean) => void
  /** Salt-okur bağlamlarda görsel olarak da pasifleşir (Codex #12). */
  disabled?: boolean
  /**
   * Vurgu kutusu (kullanıcı isteği 2026-08-04): switch'i 135° lacivert degrade +
   * ince çerçeve + yumuşak gölgeli bir kutuya alır (`.switch-vurgu`). TÜM switch'ler
   * için standart görünüm — varsayılan AÇIK; sade istenen özel bir yerde `false`.
   */
  vurgulu?: boolean
}

/** Boolean girişlerin standart bileşeni — checkbox kullanılmaz (rules.md §2). */
export function Switch({
  id,
  label,
  checked,
  onChange,
  disabled = false,
  vurgulu = true,
}: SwitchProps) {
  const icerik = (
    <div className="flex items-center justify-between gap-3">
      <label htmlFor={id} className="text-sm font-semibold text-slate-700 dark:text-slate-300">
        {label}
      </label>
      <HeadlessSwitch
        id={id}
        checked={checked}
        onChange={onChange}
        disabled={disabled}
        className="group inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full bg-slate-300 transition-colors data-checked:bg-blue-600 data-disabled:cursor-not-allowed data-disabled:opacity-50 dark:bg-slate-600 dark:data-checked:bg-blue-500"
      >
        <span className="size-4 translate-x-1 rounded-full bg-white transition-transform group-data-checked:translate-x-6" />
      </HeadlessSwitch>
    </div>
  )

  return vurgulu ? <div className="switch-vurgu rounded-xl px-3 py-2">{icerik}</div> : icerik
}
