<?php

declare(strict_types=1);

// API hata sözleşmesi mesajları (bootstrap/app.php render callback'leri +
// servis katmanı doğrulama mesajları)
return [
    'yetkisiz' => 'Oturum açmanız gerekiyor.',
    'erisim_engelli' => 'Bu işlem için yetkiniz yok.',
    'bulunamadi' => 'Kayıt bulunamadı.',
    'cok_fazla_istek' => 'Çok fazla istek gönderildi; lütfen biraz bekleyin.',
    'bilinmeyen' => 'Beklenmeyen bir hata oluştu.',

    // SQL bağlantı ayarları
    'sql_sifre_zorunlu' => 'İlk kayıtta SQL şifresi zorunludur.',
    'sql_sifre_hedef_degisti' => 'Sunucu, port veya kullanıcı adı değiştiğinde SQL şifresi yeniden girilmelidir.',
    'sql_dsn_karakter' => 'Sunucu ve veritabanı adında noktalı virgül, süslü parantez veya kontrol karakteri kullanılamaz.',
    'sql_baglanti_tanimsiz' => 'Bu ortam için bağlantı tanımı yapılmamış.',
    'sql_aktif_ortam_yok' => 'Aktif ortam seçilmemiş. Ayarlar → SQL Bağlantıları ekranından Test veya Canlı ortamı aktif edin.',
    'sql_baglanti_basarisiz' => 'Bağlantı kurulamadı: :detay',
    'sql_baglanti_eksik' => 'Sunucu, veritabanı ve kullanıcı adı alanları zorunludur.',

    // Entegratör (İzibiz) bağlantı ayarları
    'entegrator_sifre_zorunlu' => 'İlk kayıtta entegratör şifresi zorunludur.',
    'entegrator_sifre_kullanici_degisti' => 'Kullanıcı adı değiştiğinde entegratör şifresi yeniden girilmelidir.',
    'entegrator_sifre_adres_degisti' => 'API adresi değiştiğinde entegratör şifresi yeniden girilmelidir.',
    'entegrator_api_adresi_gecersiz' => 'API adresi "https://alan-adı" biçiminde olmalı (yol, sorgu veya kullanıcı bilgisi içeremez).',
    'entegrator_api_adresi_izinsiz' => 'API adresinin alan adı izinli değil. İzinli alan adları: :alanlar',
    'entegrator_kullanici_zorunlu' => 'Entegratör kullanıcı adı zorunludur.',
    'entegrator_baglanti_tanimsiz' => 'Bu ortam için entegratör bağlantısı tanımlanmamış.',
    'entegrator_eszamanli_guncelleme' => 'Tanım aynı anda başka bir oturumdan kaydedildi; sayfayı yenileyip tekrar deneyin.',
    'entegrator_vkn_gecersiz' => 'VKN 10, TCKN 11 haneli olmalı ve yalnız rakam içermelidir.',
    'entegrator_urn_gecersiz' => 'Etiket "urn:mail:" ile başlamalıdır.',
    'entegrator_aktif_yok' => 'Aktif entegratör ortamı seçilmemiş. Ayarlar → Entegratör Bağlantıları ekranından Test veya Canlı ortamı aktif edin.',
    'protel_vkn_gecmisli' => 'Bu Protel tanımının faturaları ve senkron geçmişi var; VKN değiştirilemez. Başka bir firmanın kutusu için ayrı tanım gerekir.',
    'entegrator_ortam_degisti' => 'Aktif entegratör ortamı değişti; ekrandaki liste eski ortama ait. Liste yenilendikten sonra Excel dosyasını tekrar alın.',
    'protel_kapali' => 'Protel bağlantısı kapalı (sunucu ayarı PROTEL_AKTIF). Protel\'e istek gönderilmedi.',
    // e-Fatura elle işlemleri (docs/efatura/Ek-Isler-Jira.md)
    'fatura_reddedildi' => 'Bu fatura RED; İşlendi işaretlenemez.',
    'fatura_kaynakta_reddedilmis' => 'Bu fatura kaynağında zaten reddedilmiş (entegratör ya da liste); ayrıca RED\'e çekilmez.',
    'fatura_islendi_isaretli' => 'Bu fatura SAP\'de elle işlendi; RED\'e çekmeden önce "SAP (Manuel İşle)" işaretini kaldırın.',
    'red_nedeni_zorunlu' => 'RED\'e çekme nedeni yazılmalı.',
    'red_nedeni_uzun' => 'RED nedeni en fazla :azami karakter olabilir.',
    'fatura_belgesi_yok' => 'Bu faturanın aslı eMOR arşivinde yok; PDF gösterilemiyor.',
    'yukleme_elle_islenmis' => 'Bu yüklemenin eklediği :adet faturada elle işlem (RED, İşlendi ya da gizleme) var; yükleme geri alınamaz.',
    'ice_aktarim_hatali' => 'Dosyada hatalı satırlar var; hiçbir satır kaydedilmedi.',
    'ice_aktarim_suruyor' => 'Bu kaynak için başka bir içe aktarım ya da geri alma sürüyor. Biraz sonra tekrar deneyin.',
    'fatura_emor_islenmis' => 'Bu fatura eMOR\'da işlenmiş; RED\'e çekilemez.',
    'entegrator_erisilemedi' => 'Entegratöre ulaşılamadı. Ağ bağlantısını kontrol edip tekrar deneyin.',
    'entegrator_kimlik_hatali' => 'Entegratör kullanıcı adı veya şifresi hatalı.',
    'entegrator_giris_bekletiliyor' => 'Entegratör girişi reddedildiği için otomatik giriş :saat saat bekletiliyor (hesap kilitlenmesin diye). Şifreyi Ayarlar → Entegratör Bağlantıları\'nda güncelleyin ya da "Bağlantıyı Sına" ile yeniden deneyin.',
    'entegrator_yanit_gecersiz' => 'Entegratörden beklenmeyen bir yanıt geldi.',
    'entegrator_hata' => 'Entegratör isteği reddetti (kod: :kod).',

    // e-Fatura ekranları (EFAT-11)
    'efatura_aralik_cok_genis' => 'Tarih aralığı en fazla :gun gün olabilir.',
    'efatura_ilk_tarih_oncesi' => 'e-Faturalar :tarih tarihinden itibaren izlenir; başlangıç daha erken olamaz.',
    'efatura_bitis_gelecekte' => 'Bitiş tarihi bugünden sonra olamaz.',
    'efatura_excel_cok_buyuk' => 'Seçilen filtrede :adet fatura var; Excel çıktısı en fazla :sinir satır olabilir. Tarih aralığını daraltın.',
    'efatura_senkron_suruyor' => 'Bu hesap için elle başlatılan bir senkron zaten sırada ya da çalışıyor.',
    'efatura_senkron_kapali' => 'e-Fatura senkronu sunucuda kapatılmış (EFATURA_SENKRON_AKTIF).',

    // Alarm kuralları (EFAT-13)
    'alarm_saat_bicimi' => 'Saat SS:DD biçiminde olmalı (ör. 08:30).',
    'alarm_portal_zorunlu' => 'En az bir portal seçilmeli.',
    'alarm_alici_gecersiz' => 'Geçerli bir e-posta adresi girin.',
    'alarm_alici_zorunlu' => 'Aktif kural için en az bir alıcı gerekir.',
    'alarm_islendi_zorunlu' => 'İşlendi sayılacak en az bir durum seçilmeli.',

    // Kullanıcı ve rol yönetimi (EFAT-18)
    'erp_kullanici_denetlenemedi' => 'ERP\'ye ulaşılamadığı için kullanıcı ERP\'den okunamadı; tanımlama yapılmadı.',
    'erp_kullanicisi_yok' => 'Bu kullanıcı ERP\'de bulunamadı.',
    'kullanici_zaten_tanimli' => 'Bu ERP kullanıcısı uygulamada zaten tanımlı.',
    'kendini_pasif_yapamaz' => 'Kendi giriş izninizi kaldıramazsınız.',
    'yedek_admin_pasif_yapilamaz' => 'Yedek (lokal) yönetici hesabının giriş izni kaldırılamaz: ERP erişilemezken tek giriş yoludur.',

    // Mail (SMTP) ayarları
    'mail_ayari_yok' => 'Mail (SMTP) ayarı tanımlanmamış. Ayarlar → Mail (SMTP) ekranından tanımlayın.',
    'mail_sifre_eksik' => 'SMTP şifresi girilmemiş. Ayarlar → Mail (SMTP) ekranından şifreyi girin.',
    'mail_sifre_hedef_degisti' => 'Sunucu, port, kullanıcı adı veya şifreleme değiştiğinde SMTP şifresi yeniden girilmelidir.',
    'mail_gonderilemedi' => 'Mail gönderilemedi: :detay',

    // ERP Senkronla (eMOR)
    'erp_okunamadi' => 'ERP okunamadı; eMOR bilgisi değiştirilmedi. Biraz sonra tekrar deneyin.',

    // Yetki devri (YetkiSiniri)
    'izin_verme_siniri' => 'Yalnız kendinizde olan izinleri verebilir ya da değiştirebilirsiniz.',

    // Satınalma
    'ilgili_cins_tanimsiz' => 'Bu ilgi konusu için arama kaynağı henüz tanımlanmadı.',

    // Kesinti Yönetimi
    'kesinti_turu_kod_tekrar' => 'Bu kod başka bir kesinti türünde kullanılıyor.',
    'kesinti_excel_cok_buyuk' => 'Seçilen filtrede :adet satır var; Excel çıktısı en fazla :sinir satır olabilir. Filtreyi daraltın.',
    'kesinti_turu_baska_ekranin' => 'Bu kesinti türü bu ekrana bağlı değil (Kesinti Türleri → Ekran).',
    'proje_kod_tekrar' => 'Bu kod başka bir projede kullanılıyor.',
    'para_birimi_kod_tekrar' => 'Bu kod başka bir para biriminde kullanılıyor.',
    'kayit_kullaniliyor' => 'Bu kayıt başka kayıtlarda kullanıldığı için silinemez.',
    'ice_aktarim_hatali' => 'Hatalı satırlar var; hiçbir satır kaydedilmedi. Hataları düzeltip tekrar kontrol edin.',

    // ERP okuma (App\Services\ErpOkumaHatasi)
    'erp_canli_tanimsiz' => 'Canlı ERP bağlantısı tanımlanmamış. Ayarlar → SQL Bağlantıları ekranından Canlı ortamı tanımlayın.',
    'erp_okuma_yetkisi_yok' => 'ERP veritabanı kullanıcısının bu veriyi okuma yetkisi yok. Veritabanı yöneticisinden okuma (SELECT) yetkisi isteyin.',
    'erp_okuma_hatasi' => 'ERP okunamadı. Biraz sonra tekrar deneyin.',

    // Ekran tasarım motoru
    'ekran_tanimsiz' => 'Böyle bir tasarlanabilir ekran yok.',
    'tasarim_taslak_yok' => 'Yayınlanacak taslak bulunamadı.',
    'tasarim_surum_yok' => 'Bu sürüm bulunamadı.',
    'tasarim_bolum_yok' => 'Tasarımda en az bir bölüm olmalı.',
    'tasarim_bolum_gecersiz' => 'Tanımsız bölüm.',
    'tasarim_alan_gecersiz' => 'Tanımsız alan: :alan',
    'tasarim_alan_tekrar' => 'Aynı alan birden fazla kez yerleştirilemez: :alan',
    'tasarim_genislik_gecersiz' => 'Genişlik 1–12 arasında olmalı: :alan',
    'tasarim_alan_zorunlu' => 'Bu alan tasarımdan çıkarılamaz: :alan',

    // Yedekleme (docs/yedekleme; BY-014…018)
    'yedekleme_kapali' => 'Yedekleme modülü kapalı (sunucu ayarı YEDEKLEME_AKTIF).',
    'yedek_istegi_yok' => 'Yedekleme isteği bulunamadı (süresi geçmiş olabilir).',
    'yedek_dogrulanamaz' => 'Bu yedek doğrulanamaz: yalnız bu sistemin imzaladığı, tamamlanmış yedekler doğrulanır.',
    'yedek_dogrulama_kaynagi_yok' => 'Bu yedeğin ekrandan doğrulanabilecek bir dosyası yok (yalnız şifreli sunucu dışı kopyası kaldıysa özel anahtar gerekir — docs/yedekleme/kurtarma.md).',
    'yedek_silinmis' => 'Bu yedek silinmiş.',
    'yedek_sabit' => 'Sabitlenmiş yedek silinemez. Önce sabitlemeyi kaldırın.',
    'yedek_kullanimda' => 'Bu yedek şu an kullanımda (kopyalanıyor ya da geri yükleniyor); bitince tekrar deneyin.',
    'yedek_hedefi_kullaniliyor' => 'Bu hedef silinemez: :plan planda kullanılıyor, :kopya kopya kaydı var. Önce planlardan çıkarın; kopyalar saklama kuralıyla temizlenince silinebilir.',
    'yedek_hedefi_tur_degismez' => 'Hedefin türü değiştirilemez; yeni bir hedef ekleyin.',
    'yedek_hedefi_bilinmeyen_alan' => 'Bu hedef türünde olmayan alan: :alanlar',
    'yedek_hedefi_sir_yeniden' => 'Bağlantı bilgisi değiştiği için parolayı / anahtarı yeniden girin (kayıtlı parola yeni adrese gönderilmez).',
    'yedek_hedefi_klasor_yasak' => 'Bu klasör hedef olamaz: uygulamanın kendi klasörlerinde (web\'den sunulabilir) ya da bir sistem klasöründe (/proc, /sys, /dev, /run). Yerel kopya şifresiz yazılır; uygulamanın dışında bir klasör seçin.',
    'yedek_hedefi_sir_celiski' => 'Aynı alan için hem yeni değer girildi hem silme istendi: :alanlar',
    'yedek_hedefi_kimlik_kalmali' => 'Hedefte en az bir kimlik bilgisi (SFTP: özel anahtar ya da parola) kalmalı; ikisi birden silinemez.',
    'yedek_imza_anahtari_yok' => 'Yedekleme imza anahtarı bulunamadı ya da okunamıyor; ayarlar kaydedilmedi. Sunucu kurulumunu denetleyin (docs/yedekleme/sunucu-kurulumu.md).',
    'istek_anahtari_gecersiz' => 'İsteğin yeniden deneme anahtarı (Idempotency-Key) geçerli bir UUID değil.',
    'yedek_hedefi_konumu_degismez' => 'Bu hedefte kopyalar var; kopyaların durduğu yeri belirleyen alanlar (:alanlar) değiştirilemez. Başka bir yer için yeni bir hedef ekleyin. Parola / anahtar yenilenebilir.',
    'yedek_hedefi_ayni_depo' => 'Bu hedef, şu hedefle aynı fiziksel depoya yazıyor: :hedefler (yazımı farklı da olsa aynı klasör; FTP, FTPS ve SFTP\'de aynı sunucu, kullanıcı ve klasör; S3\'te aynı uç nokta, kova ve önek; klasörü boş Yerel disk hedefi yedek klasörünün kendisidir). Aynı yer iki hedef olarak tanımlanamaz — bir hedef birden çok planda kullanılabilir.',
    'yedek_hedefi_google_degil' => 'Bu işlem yalnız Google Drive hedeflerinde yapılır.',
    'yedek_google_yapilandirilmadi' => 'Google bağlantısı için sunucuda istemci kimliği ve sırrı tanımlı değil (YEDEKLEME_GOOGLE_*).',
    'yedek_google_durum_gecersiz' => 'Google bağlantısı doğrulanamadı (süresi geçmiş ya da başka bir oturumdan). "Google ile bağlan"ı yeniden deneyin.',
    'yedek_google_baglanamadi' => 'Google bağlantısı kurulamadı. Yeniden deneyin.',
    'yedek_google_secici_yapilandirilmadi' => 'Google klasör seçme penceresi için sunucuda API anahtarı ve proje numarası tanımlı değil (YEDEKLEME_GOOGLE_API_ANAHTARI, YEDEKLEME_GOOGLE_PROJE_NUMARASI).',
    'yedek_google_hesap_degisti' => 'Klasör denenirken hedef başka bir Google hesabına bağlandı; klasör kaydedilmedi. Klasörü yeniden seçin.',
    'yedek_google_hesap_okunamadi' => 'Bağlanan Google hesabının kimliği (e-posta) okunamadı; bağlantı kaydedilmedi. "Google ile bağlan"ı yeniden deneyin.',
    'yedek_google_hesap_dogrulanamadi' => 'Bu hedefte kopyalar var ve bağlanan Google hesabının kayıtlı hesapla aynı olduğu doğrulanamadı (hesap, yedek klasörünün ve kayıtlı kopyanın sahibi olmalı); eski bağlantı korundu. Klasörün sahibi olan hesapla yeniden deneyin; olmuyorsa yeni bir hedef ekleyin ve bu hedefin kopyalarını elle uzlaştırın.',
    'yedek_plani_kopyali' => 'Bu plana bağlı :adet kopya var; plan silinemez. Pasife alın — geçmişi durur.',
    'yedek_plan_hedefi_kopyali' => 'Kopyası olan hedef plandan çıkarılamaz: :hedefler. Bu kopyaların saklama kuralı kaybolur; önce saklama kuralıyla temizlenmelerini bekleyin.',
    'yedek_geri_yuklenemez' => 'Bu yedek geri yüklenemez: yalnız bu sistemin imzaladığı, tamamlanmış yedekler geri yüklenir.',
    'geri_yukleme_kopya_yok' => 'Silinecek bekleyen kopya yok (zaten silinmiş ya da bu sistemin açtığı bir kopya değil).',
    'yedek_plani_calismiyor' => 'Bu sıklık tanımıyla plan hiç çalışmıyor (saat aralığı ve günleri kontrol edin).',

    // Üstüne geri yükleme (BY-028…030)
    'geri_yukleme_onay_metni' => 'Onaylamak için canlı veritabanının adını (:ad) aynen yazın.',
    'geri_yukleme_sema_bilinmiyor' => 'Bu yedek içe alınmış eski bir yedek: migration listesi olmadığı için şema uyumu denetlenemez, üstüne geri yüklenemez (farklı adla geri yüklenebilir).',
    'geri_yukleme_servis_gerekli' => 'Üstüne geri yükleme, servis hesabının çalıştırıcısı kurulmadan yapılamaz (YEDEKLEME_CALISTIRICI=servis).',
    'geri_yukleme_acik' => 'Açık bir üstüne geri yükleme var (sürüyor ya da onay bekliyor).',
    'geri_yukleme_islem_durumu_okunamadi' => 'İşlem durumu okunamadı; işlem yapılmadı.',
    'geri_yukleme_erisim_anahtari_yok' => 'Erişim anahtarı gönderilmedi.',
    'geri_yukleme_erisim_anahtari_gecersiz' => 'Erişim anahtarı geçersiz ya da bu işlemin değil.',
    'geri_yukleme_erisim_anahtari_suresi_doldu' => 'Erişim anahtarının süresi doldu. Onay ve geri dönüş sunucudan yapılabilir: php artisan yedek:acil durum | onayla | geri-don',
    'geri_yukleme_islem_durumu_uygun_degil' => 'İşlem bu adımda onaylanamaz ya da geri alınamaz.',
    'geri_yukleme_ayar_secimi_eksik' => 'Farkı olan her ayar grubu için "güncel" ya da "geri yüklenen" seçilmeli.',
];
