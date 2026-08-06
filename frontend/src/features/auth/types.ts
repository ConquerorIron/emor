export interface Kullanici {
  id: number
  ad: string
  kullanici_adi: string
  email: string | null
  /** 'lokal': fallback admin; 'erp': ERP MSSQL'de doğrulanan kullanıcı */
  kaynak: 'lokal' | 'erp'
  /**
   * ERP TOHOM_KULLANICI.SISTEM_YONETICISI yansıması — yönetim ekranlarını
   * (ör. Ekran Tasarım Ayarları) açar. Her girişte tazelenir.
   */
  sistem_yoneticisi: boolean
}
