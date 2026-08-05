export interface Kullanici {
  id: number
  ad: string
  kullanici_adi: string
  email: string | null
  /** 'lokal': fallback admin; 'erp': ERP MSSQL'de doğrulanan kullanıcı */
  kaynak: 'lokal' | 'erp'
}
