/** Sidebar menüsü — rol formundaki izin grupları da buradan türer (Roller/izinKurallari) */
export interface NavOgesi {
  to: string
  /** i18n anahtarı (nav.*) — ikon adı olarak da kullanılır */
  ad: string
  end?: boolean
  /** Yalnız bu izne (ekranın görüntüleme izni) sahip kullanıcılara görünür */
  izin?: string
}

export interface NavGrubu {
  baslikAnahtari: string | null
  ogeler: NavOgesi[]
}

export const NAV_GRUPLARI: NavGrubu[] = [
  {
    baslikAnahtari: null,
    ogeler: [{ to: '/', ad: 'anasayfa', end: true }],
  },
  {
    baslikAnahtari: 'nav.satinalma',
    ogeler: [
      { to: '/satinalma/talep', ad: 'satinalmaTalepleri', izin: 'satinalma_talebi.goruntule' },
    ],
  },
  {
    baslikAnahtari: 'nav.efatura',
    ogeler: [
      { to: '/efatura/gelen', ad: 'gelenFaturalar', izin: 'efatura.goruntule' },
      { to: '/efatura/giden', ad: 'gidenFaturalar', izin: 'efatura.goruntule' },
    ],
  },
  {
    baslikAnahtari: 'nav.kesinti',
    ogeler: [
      // Önce raporlar (kullanıcı kararı 2026-11-13)
      { to: '/kesinti/ozet', ad: 'kesintiOzeti', izin: 'kesinti_ozeti.goruntule' },
      { to: '/kesinti/firma-ozeti', ad: 'firmaOzeti', izin: 'firma_ozeti.goruntule' },
      { to: '/kesinti/tumu', ad: 'tumKesintiler', izin: 'tum_kesintiler.goruntule' },
      { to: '/kesinti/isgucu', ad: 'isgucuKesintileri', izin: 'isgucu_kesintileri.goruntule' },
      { to: '/kesinti/kamp', ad: 'kampKesintileri', izin: 'kamp_kesintileri.goruntule' },
      {
        to: '/kesinti/kamp-malzeme',
        ad: 'kampMalzemeKesintileri',
        izin: 'kamp_malzeme_kesintileri.goruntule',
      },
      {
        to: '/kesinti/makina-ekipman',
        ad: 'makinaEkipmanKesintileri',
        izin: 'makina_ekipman_kesintileri.goruntule',
      },
      { to: '/kesinti/tutanak', ad: 'tutanakKesintileri', izin: 'tutanak_kesintileri.goruntule' },
      { to: '/kesinti/yemek', ad: 'yemekKesintileri', izin: 'yemek_kesintileri.goruntule' },
      { to: '/kesinti/osgb', ad: 'osgbKesintileri', izin: 'osgb_kesintileri.goruntule' },
      { to: '/kesinti/tetkik', ad: 'tetkikKesintileri', izin: 'tetkik_kesintileri.goruntule' },
      {
        to: '/kesinti/isg-ceza',
        ad: 'isgCezaKesintileri',
        izin: 'isg_ceza_kesintileri.goruntule',
      },
      { to: '/kesinti/malzeme', ad: 'malzemeKesintileri', izin: 'malzeme_kesintileri.goruntule' },
      {
        to: '/kesinti/kart-basim',
        ad: 'kartBasimKesintileri',
        izin: 'kart_basim_kesintileri.goruntule',
      },
    ],
  },
  {
    // ERP rapor proc'larından okunan raporlar (veri girilmez)
    baslikAnahtari: 'nav.raporlar',
    ogeler: [
      {
        to: '/raporlar/erp-kesinti-kontrolu',
        ad: 'erpKesintiKontrolu',
        izin: 'erp_kesinti_kontrolu.goruntule',
      },
      {
        to: '/raporlar/taseron-hakedisleri',
        ad: 'taseronHakedisleri',
        izin: 'taseron_hakedisleri.goruntule',
      },
      {
        to: '/raporlar/gider-yansitma-faturalari',
        ad: 'giderYansitmaFaturalari',
        izin: 'gider_yansitma_faturalari.goruntule',
      },
    ],
  },
  {
    // Ortak tanımlar (kullanıcı kararı 2026-10-30: Ayarlar'ın üstünde ayrı grup);
    // adresler değişmedi, yalnız menüdeki yerleri
    baslikAnahtari: 'nav.tanimlar',
    ogeler: [
      { to: '/kesinti/projeler', ad: 'projeler', izin: 'projeler.goruntule' },
      { to: '/kesinti/firmalar', ad: 'firmalar', izin: 'firmalar.goruntule' },
      { to: '/kesinti/para-birimleri', ad: 'paraBirimleri', izin: 'para_birimleri.goruntule' },
      { to: '/kesinti/turler', ad: 'kesintiTurleri', izin: 'kesinti_turleri.goruntule' },
    ],
  },
  {
    baslikAnahtari: 'nav.ayarlar',
    ogeler: [
      {
        to: '/ayarlar/sql-baglantilari',
        ad: 'sqlBaglantilari',
        izin: 'sql_baglantilari.goruntule',
      },
      {
        to: '/ayarlar/entegrator-baglantilari',
        ad: 'entegratorBaglantilari',
        izin: 'entegrator_baglantilari.goruntule',
      },
      { to: '/ayarlar/mail', ad: 'mailAyarlari', izin: 'mail_ayarlari.goruntule' },
      { to: '/ayarlar/alarm-kurallari', ad: 'alarmKurallari', izin: 'alarm_kurallari.goruntule' },
      { to: '/ayarlar/kullanicilar', ad: 'kullanicilar', izin: 'kullanicilar.goruntule' },
      { to: '/ayarlar/roller', ad: 'roller', izin: 'roller.goruntule' },
      { to: '/ayarlar/ekran-tasarimi', ad: 'ekranTasarimi', izin: 'ekran_tasarimi.goruntule' },
    ],
  },
]
