<?php

declare(strict_types=1);

// e-Fatura ekranları (EFAT-11) ve alarmları (EFAT-13). İzibiz uç adresleri
// ve senkron sınırları config/entegrator.php'de.
return [
    // Liste/Excel'de seçilebilecek en geniş tarih aralığı (gün)
    'liste_azami_gun' => 366,

    // Elle senkronda seçilebilecek en geniş aralık (gün) — bizim sınırımız,
    // İzibiz'in değil. Kuyruk işi 300 sn ile sınırlı; ölçüm (2026-09-24, canlı
    // hesap): 01.01–24.09 iki yön 18 okuma 23 sn. Bir yıl rahatça sığar.
    'manuel_azami_gun' => 366,

    // Elle senkron, zamanlanmış senkron kilidi tutuyorsa en çok bu kadar bekler
    // (sn); yine alamazsa atlanır ve log yazılır
    'manuel_kilit_bekleme' => 60,

    // Excel çıktısının azami satırı; aşılırsa kullanıcıdan aralığı daraltması
    // istenir (çıktı bellekte üretilir). Ölçüm (2026-09-23, gerçek test verisi):
    // 11.411 satır 164 MB / 5 sn, 20.000 satır 226 MB / 8 sn. php-fpm'in
    // varsayılan 128 MB sınırı yetmediği için yalnız bu istekte yükseltilir.
    'excel_azami_satir' => 20000,
    'excel_bellek_siniri' => '512M',

    // Bugünü kapsayan son TAM senkron bundan eskiyse ekran "güncel değil"
    // uyarısı gösterir (zamanlanmış senkron 15 dakikada bir).
    'guncellik_dakika' => 60,

    // `calisiyor` durumunda bu kadar dakikadır kalan çalışma yarıda kalmış sayılır
    'yarim_kalma_dakika' => 120,

    // ERP okumadı alarmı yalnız son bu kadar saatte yeniden okunmuş faturanın
    // bayrağına güvenir (eşiği geçmiş faturaları gece 03:15 DOCUMENT senkronu
    // tazeler; 24 saat + pay)
    'erp_okundu_tazelik_saat' => 26,

    // Vergi istisna kodu İzibiz UBL'inden (efatura:istisna-kodlari, 15 dk'da bir):
    // istek başına fatura (İzibiz sınırı 100) ve çalışma başına azami istek.
    // Birikmiş faturalar bu hızla (saatte ~800 fatura) yavaşça okunur.
    'istisna_parti_boyutu' => 50,
    'istisna_azami_istek' => 4,
];
