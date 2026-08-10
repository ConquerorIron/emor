import type { StylesConfig } from 'react-select'

import { ALAN_YUKSEKLIGI } from '@/components/alanStilleri'

export interface SecenekOgesi {
  value: string
  label: string
}

/**
 * ornek/dashboard react-select stilleri — tema duyarlı. Ayrı dosyada, çünkü
 * hem SelectField sarmalayıcıları hem grid hücre seçicileri
 * kullanır; component dosyasından export etmek Fast Refresh'i bozuyordu.
 */
export function selectStilleri<IsMulti extends boolean>(
  isDark: boolean,
  /** Doğrulama hatası — metin girişlerindeki kırmızı çerçevenin karşılığı */
  hataliMi = false,
): StylesConfig<SecenekOgesi, IsMulti> {
  return {
    control: (base, state) => ({
      ...base,
      minHeight: ALAN_YUKSEKLIGI,
      // Metin girişleriyle aynı punto (Tailwind text-sm) — react-select
      // varsayılanı gövdeden 16px miras alıyor ve yan yana duran alanlarda
      // seçicinin yazısı inputlardan iri görünüyordu
      fontSize: 14,
      borderRadius: 12,
      boxShadow: 'none',
      borderColor: hataliMi
        ? '#ef4444'
        : state.isFocused
          ? '#3b82f6'
          : isDark
            ? '#334155'
            : '#cbd5e1',
      // Salt okunur alan: metin girişlerindeki disabled görünümüyle aynı dil
      backgroundColor: state.isDisabled
        ? isDark
          ? '#0f172a'
          : '#f8fafc'
        : isDark
          ? '#020617'
          : '#ffffff',
      opacity: state.isDisabled ? 0.6 : 1,
      cursor: state.isDisabled ? 'not-allowed' : 'default',
      ':hover': {
        borderColor: hataliMi
          ? '#ef4444'
          : state.isFocused
            ? '#3b82f6'
            : isDark
              ? '#475569'
              : '#94a3b8',
      },
    }),
    menu: (base) => ({
      ...base,
      fontSize: 14,
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
    /**
     * Dar kolonlarda metin ALT SATIRA KAYMAZ, kırpılır. Sarmak satır
     * yüksekliğini değiştirip ızgarayı bozuyordu (kullanıcı bildirimi
     * 2026-08-10: 75px'lik Ürün kodu hücresinde placeholder aşağı kayıyordu).
     */
    valueContainer: (base) => ({ ...base, flexWrap: 'nowrap', overflow: 'hidden' }),
    singleValue: (base) => ({
      ...base,
      color: isDark ? '#e2e8f0' : '#0f172a',
      whiteSpace: 'nowrap',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
    }),
    multiValue: (base) => ({
      ...base,
      borderRadius: 8,
      backgroundColor: isDark ? '#1e293b' : '#e2e8f0',
    }),
    multiValueLabel: (base) => ({ ...base, color: isDark ? '#e2e8f0' : '#0f172a' }),
    placeholder: (base) => ({
      ...base,
      color: isDark ? '#94a3b8' : '#64748b',
      whiteSpace: 'nowrap',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
    }),
    input: (base) => ({ ...base, color: isDark ? '#e2e8f0' : '#0f172a' }),
  }
}
