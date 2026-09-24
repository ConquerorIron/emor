export interface Kullanici {
  id: number
  ad: string
  kullanici_adi: string
  email: string | null
  /** 'lokal': fallback admin; 'erp': ERP MSSQL'de doğrulanan kullanıcı */
  kaynak: 'lokal' | 'erp'
  /**
   * ERP TOHOM_KULLANICI.SISTEM_YONETICISI yansıması — backend bu kullanıcıya
   * tüm izinleri verir; arayüz kararları yalnız `izinler` ile verilir.
   * Her girişte tazelenir.
   */
  sistem_yoneticisi: boolean
  /**
   * Rollerden gelen izinler (sistem yöneticisinde tüm katalog) — menü, rota
   * koruması ve salt okunur ekranlar içindir; asıl denetim backend'dedir.
   */
  izinler: string[]
}
