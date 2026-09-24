<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// e-Fatura senkronu (EFAT-10). Komut EFATURA_SENKRON_AKTIF=false iken ve aktif
// entegratör ortamı yokken hiçbir şey yapmadan çıkar.
// Artımlı: son 2 günde İzibiz'e ULAŞAN faturalar (geç düzenlenmiş eski
// tarihli faturalar da yakalanır)
Schedule::command('efatura:senkron --gun=2 --tarih-turu=DELIVERY')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60);

// e-Fatura alarmları (EFAT-13): senkrondan SONRA (aynı dakikada sırayla çalışır)
Schedule::command('efatura:alarmlar')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// eMOR kolonu: gelen faturalar ERP'ye (TOHOM_FATURA) işlenmiş mi — yalnız SELECT
Schedule::command('efatura:emor')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Durum tazeleme: son 45 günün belge tarihli faturaları (kabul/red/GİB durumu)
Schedule::command('efatura:senkron --gun=45')
    ->dailyAt('03:15')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120);
