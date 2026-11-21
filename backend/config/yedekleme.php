<?php

declare(strict_types=1);
use App\Support\AcKapa;

// Yedekleme modülü (docs/yedekleme/README.md). Planlar, hedefler ve saklama
// kuralları veritabanında (ekrandan); burada yalnız sunucuya özgü olanlar.
//
// `env('X', varsayılan)` değişken .env'de BOŞ tanımlıysa ("X=") varsayılanı
// değil boş metni döndürür; bu yüzden `env('X') ?: varsayılan` kullanılır
// (BY-004-R2, Dikkat_Edilecekler G6).
return [
    // Modülün ana anahtarı. KAPALIYKEN modül hiçbir şey yapmaz: yedek almaz,
    // zamanlayıcıda iş başlatmaz (Backup-Jira §1.3: kod canlıya gitse de etkisiz).
    // (bool) değil: "off", "hayir" gibi değerler modülü açardı (G9, AcKapa)
    'aktif' => AcKapa::cevir(env('YEDEKLEME_AKTIF'), false),

    // Yedeklerin, manifestlerin ve olay günlüğünün klasörü. Geçiş döneminde
    // yedeği uygulama kullanıcısı alır (README §5.2); servis hesabına geçişte
    // (BY-025) /var/lib/emor-yedek/yedekler olur (sunucu-kurulumu.md).
    'klasor' => env('YEDEKLEME_KLASORU') ?: storage_path('app/yedekler'),

    // İşlem durumu dosyası ve kilidin klasörü (BY-005 "Dosyaların yeri") ve
    // kurulumun açıp diske eşitlediği kalıcı kök (KaliciKlasor). Sunucu
    // düzeninde ikisi de /var/lib/emor-yedek/durum (BY-003); geçiş döneminde
    // storage/app altı. Kök kurulmuş olmalı: yoksa durum "bilinmiyor" sayılır.
    'durum_klasoru' => env('YEDEKLEME_DURUM_KLASORU') ?: storage_path('app/yedek-durum'),
    'kalici_kok' => env('YEDEKLEME_KALICI_KOK') ?: storage_path('app'),

    // Manifest imza anahtarı: veritabanının DIŞINDA duran dosya (README §5.3,
    // §4.5). `yedek:imza-anahtari` üretir. Kaybolursa bütün yedekler
    // "güvenilmeyen" görünür: kurtarma paketine girer (README §9, BY-024).
    'imza_anahtari' => env('YEDEKLEME_IMZA_ANAHTARI') ?: storage_path('app/yedek-imza.key'),

    // PostgreSQL parola dosyası (PGPASSFILE, izin 0600). Servis hesabında
    // kurulumun yazdığı dosya (BY-025). Boşsa geçiş dönemi: uygulamanın
    // bağlantı tanımından her çalışmada geçici bir dosya yazılır ve silinir.
    // Parola hiçbir zaman komut satırına ya da ortam değişkenine girmez.
    'parola_dosyasi' => env('YEDEKLEME_PAROLA_DOSYASI') ?: null,

    // Yedeği alınan veritabanının Laravel bağlantı adı
    'baglanti' => env('YEDEKLEME_BAGLANTI') ?: 'pgsql',

    // Yedeklemenin bağlandığı rol (servis hesabı: emor_yedek). Boşsa
    // bağlantı tanımındaki kullanıcı (geçiş dönemi). Geri yüklenen nesnelerin
    // sahibi her zaman uygulama rolü (bağlantı tanımındaki kullanıcı) kalır.
    'rol' => env('YEDEKLEME_ROL') ?: null,

    // İstek klasörü: web süreci (ekran) yazar, çalıştırıcı okur (README §5.2,
    // BY-014, BY-025). Sunucu düzeninde /var/lib/emor-yedek/istekler.
    'istek_klasoru' => env('YEDEKLEME_ISTEK_KLASORU') ?: storage_path('app/yedek-istekler'),

    // İşleri kim yapar (README §5.2, BY-025): `servis` = ayrı servis hesabının
    // çalıştırıcısı (ekran ve yayın isteği istek klasörüne bırakıp bekler);
    // `dogrudan` = geçiş dönemi, komutu çağıran süreç (zamanlayıcı / yayın)
    // işi kendisi yapar. Tanınmayan değer `dogrudan`.
    'calistirici' => env('YEDEKLEME_CALISTIRICI') === 'servis' ? 'servis' : 'dogrudan',

    // İstek dosyasının SAHİBİ (çekirdeğin verdiği uid) ile göndereni doğrulama
    // (BY-025-R1): kullanıcılı istekler yalnız web kullanıcısının, kullanıcısız
    // yayın öncesi yedek yalnız yayın kullanıcısının dosyasıysa işlenir. Servis
    // kipinde kurulum betiği servis tanımına yazar (Environment). Web tanımsızsa
    // çalıştırıcının kendi kullanıcısı (geçiş kipi); yayın tanımsızsa yayın
    // isteği kabul edilmez. Değeri YedeklemeYapilandirmasi doğrular (G9).
    'web_uid' => env('YEDEKLEME_WEB_UID') ?: null,
    'yayin_uid' => env('YEDEKLEME_YAYIN_UID') ?: null,

    // Ayar kopyası ve denetim günlüğünün dosyası (README §5.3, §4.9): geri
    // yüklemeyle eskimeyen kayıt. Sunucu düzeninde /var/lib/emor-yedek/durum altında.
    'ayar_kopyasi' => env('YEDEKLEME_AYAR_KOPYASI') ?: storage_path('app/yedek-durum/ayarlar.json'),

    // Bir kopyanın en çok kaç kez deneneceği (her denemede yeniden bağlanılır)
    'kopya_deneme' => 3,

    // Tek temizlikte bundan çok dosya silinirse "beklenmedik temizlik"
    // bildirimi gider (README §4.8)
    'beklenmedik_silme' => 10,

    // Her yedekten sonra geçici veritabanına sınama geri yüklemesi (README
    // §4.4 2. düzey). Veritabanı açma yetkisi ister: servis hesabı kurulana
    // kadar (BY-025) kapalı; uygulamanın kullanıcısı veritabanı açamaz.
    'sinama_geri_yukleme' => AcKapa::cevir(env('YEDEKLEME_SINAMA'), false),

    // Geri yüklenen kopyaların (erp_geri_*) ve erp_onceki_* veritabanlarının
    // bekleme süresi (gün; README §4.5, §13 karar 10 — öneri 7)
    'kopya_bekleme_gun' => env('YEDEKLEME_KOPYA_BEKLEME_GUN') ?: 7,

    // Şifreleme ve uzak hedef araçları (BY-019, BY-022)
    'gpg' => env('YEDEKLEME_GPG') ?: '/usr/bin/gpg',
    // Geçici anahtarlığın ajanını kapatmak için (gpgconf --kill all)
    'gpgconf' => env('YEDEKLEME_GPGCONF') ?: '/usr/bin/gpgconf',
    // Kurtarma paketindeki manifest arşivi (BY-024; operatör `tar -xf` ile açar)
    'tar' => env('YEDEKLEME_TAR') ?: '/usr/bin/tar',
    'sftp' => env('YEDEKLEME_SFTP') ?: '/usr/bin/sftp',
    'ssh_keyscan' => env('YEDEKLEME_SSH_KEYSCAN') ?: '/usr/bin/ssh-keyscan',

    // Üstüne geri yüklemede durdurulup onayla / geri dönüşle başlatılan kuyruk
    // servisi (README §6.4 2. ve 10. adım). Servis hesabı yalnız bu servisin
    // stop / start / is-active komutlarını şifresiz çalıştırabilir (kurulum
    // betiği bölüm 7, sudoers). Boşsa servis yönetilmez: kuyruk işçisi bakım
    // modunda ve açık geri yüklemede zaten iş almaz (BY-029), ama o anda
    // çalışan iş bitmeden geri yükleme öncesi yedek alınabilir.
    'kuyruk_servisi' => env('YEDEKLEME_KUYRUK_SERVISI') ?: null,
    'sudo' => env('YEDEKLEME_SUDO') ?: '/usr/bin/sudo',
    'systemctl' => env('YEDEKLEME_SYSTEMCTL') ?: '/usr/bin/systemctl',

    // Google Drive hedefi (BY-021): Google tarafındaki uygulama kaydının
    // istemci kimliği ve gizli anahtarı. Boşsa "Google ile bağlan" kapalı.
    'google' => [
        'istemci_kimligi' => env('YEDEKLEME_GOOGLE_ISTEMCI_KIMLIGI') ?: null,
        'istemci_sirri' => env('YEDEKLEME_GOOGLE_ISTEMCI_SIRRI') ?: null,
    ],

    // Verisi yedeğe GİRMEYEN tablolar (şeması girer): oturum, önbellek ve
    // kuyruk geçicidir, geri yüklenirse bayat oturum / iş döner (README §4.1, §7.1).
    'verisi_alinmayan_tablolar' => [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ],

    // PostgreSQL araçları. Sürümleri sunucuyla aynı olmalı (README §7.7).
    'araclar' => [
        'pg_dump' => env('YEDEKLEME_PG_DUMP') ?: '/usr/bin/pg_dump',
        'pg_restore' => env('YEDEKLEME_PG_RESTORE') ?: '/usr/bin/pg_restore',
        'psql' => env('YEDEKLEME_PSQL') ?: '/usr/bin/psql',
    ],

    // Süre sınırları (saniye). Asılı kalan dış komut kilidi tutar ve sonraki
    // yedekleri sessizce engeller (Dikkat_Edilecekler D1). Burada (int)
    // YAPILMAZ: `(int) 'abc'` = 0 = sınırsız olurdu. Değeri tüketen
    // YedeklemeYapilandirmasi::sureSiniri() yalnız pozitif tam sayı kabul eder,
    // geçersizde açık hatayla durur (G9, BY-004-R5).
    'sure_siniri' => [
        // pg_dump ve doğrulama okumaları (bugün ~1 sn; veri büyüdükçe uzar)
        // Boş bırakılırsa 0 (sınırsız) DEĞİL varsayılan
        'yedek' => env('YEDEKLEME_YEDEK_SURESI') ?: 1800,
        // pg_restore (sınama ve gerçek geri yükleme)
        'geri_yukleme' => env('YEDEKLEME_GERI_YUKLEME_SURESI') ?: 3600,
        // Uzak hedefe tek kopya (yükleme + geri okuma)
        'kopya' => env('YEDEKLEME_KOPYA_SURESI') ?: 900,
        // gpg ile bir dosyanın şifrelenmesi / çözülmesi (BY-019). Anahtar
        // okuma ve içe alma kısa işlerdir; onların sınırı KopyaSifreleyici'de sabit.
        'sifreleme' => env('YEDEKLEME_SIFRELEME_SURESI') ?: 1800,
        // Veritabanına bağlanma
        'baglanti' => 10,
        // Başka işlem kilidi tutuyorsa en çok bu kadar beklenir, sonra vazgeçilir
        'kilit_bekleme' => 60,
    ],
];
