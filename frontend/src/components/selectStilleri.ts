import type { StylesConfig } from 'react-select'

export interface SecenekOgesi {
  value: string
  label: string
}

/**
 * ornek/dashboard react-select stilleri — tema duyarlı. Ayrı dosyada, çünkü
 * hem SelectField sarmalayıcıları hem grid hücre seçicileri (PUANTAJ-7)
 * kullanır; component dosyasından export etmek Fast Refresh'i bozuyordu.
 */
export function selectStilleri<IsMulti extends boolean>(
  isDark: boolean,
): StylesConfig<SecenekOgesi, IsMulti> {
  return {
    control: (base, state) => ({
      ...base,
      minHeight: 42,
      borderRadius: 12,
      boxShadow: 'none',
      borderColor: state.isFocused ? '#3b82f6' : isDark ? '#334155' : '#cbd5e1',
      backgroundColor: isDark ? '#020617' : '#ffffff',
      ':hover': {
        borderColor: state.isFocused ? '#3b82f6' : isDark ? '#475569' : '#94a3b8',
      },
    }),
    menu: (base) => ({
      ...base,
      borderRadius: 12,
      overflow: 'hidden',
      backgroundColor: isDark ? '#0f172a' : '#ffffff',
      border: `1px solid ${isDark ? '#334155' : '#e2e8f0'}`,
      zIndex: 60,
    }),
    menuPortal: (base) => ({ ...base, zIndex: 70 }),
    option: (base, state) => ({
      ...base,
      backgroundColor: state.isFocused
        ? isDark
          ? '#1e293b'
          : '#f1f5f9'
        : isDark
          ? '#0f172a'
          : '#ffffff',
      color: isDark ? '#e2e8f0' : '#0f172a',
      cursor: 'pointer',
    }),
    singleValue: (base) => ({ ...base, color: isDark ? '#e2e8f0' : '#0f172a' }),
    multiValue: (base) => ({
      ...base,
      borderRadius: 8,
      backgroundColor: isDark ? '#1e293b' : '#e2e8f0',
    }),
    multiValueLabel: (base) => ({ ...base, color: isDark ? '#e2e8f0' : '#0f172a' }),
    placeholder: (base) => ({ ...base, color: isDark ? '#94a3b8' : '#64748b' }),
    input: (base) => ({ ...base, color: isDark ? '#e2e8f0' : '#0f172a' }),
  }
}
