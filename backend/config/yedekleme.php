<?php

declare(strict_types=1);

// Yedekleme modülü (docs/yedekleme/README.md). Planlar, hedefler ve saklama
// kuralları veritabanında (ekrandan); burada yalnız sunucuya özgü olanlar.
return [
    // Modülün ana anahtarı. KAPALIYKEN modül hiçbir şey yapmaz: yedek almaz,
    // zamanlayıcıda iş başlatmaz (Backup-Jira §1.3: kod canlıya gitse de etkisiz).
    'aktif' => (bool) env('YEDEKLEME_AKTIF', false),

    // Yedeklerin, manifestlerin ve olay günlüğünün klasörü. Geçiş döneminde
    // yedeği uygulama kullanıcısı alır (README §5.2); servis hesabına geçişte
    // (BY-025) /var/lib/emor-yedek/yedekler olur (sunucu-kurulumu.md).
    'klasor' => env('YEDEKLEME_KLASORU', storage_path('app/yedekler')),

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
        'pg_dump' => env('YEDEKLEME_PG_DUMP', '/usr/bin/pg_dump'),
        'pg_restore' => env('YEDEKLEME_PG_RESTORE', '/usr/bin/pg_restore'),
        'psql' => env('YEDEKLEME_PSQL', '/usr/bin/psql'),
    ],

    // Süre sınırları (saniye). Asılı kalan dış komut kilidi tutar ve sonraki
    // yedekleri sessizce engeller (Dikkat_Edilecekler D1).
    'sure_siniri' => [
        // pg_dump ve doğrulama okumaları (bugün ~1 sn; veri büyüdükçe uzar)
        'yedek' => (int) env('YEDEKLEME_YEDEK_SURESI', 1800),
        // pg_restore (sınama ve gerçek geri yükleme)
        'geri_yukleme' => (int) env('YEDEKLEME_GERI_YUKLEME_SURESI', 3600),
        // Veritabanına bağlanma
        'baglanti' => 10,
        // Başka işlem kilidi tutuyorsa en çok bu kadar beklenir, sonra vazgeçilir
        'kilit_bekleme' => 60,
    ],
];
