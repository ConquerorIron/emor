<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// e-Fatura senkronu (EFAT-10). Komut EFATURA_SENKRON_AKTIF=false iken ve aktif
// entegratör ortamı yokken hiçbir şey yapmadan çıkar.
// Artımlı: son 10 günde İzibiz'e ULAŞAN faturalar (geç düzenlenmiş eski
// tarihli faturalar da yakalanır). 10 gün (kullanıcı kararı 2026-11-18, önce
// 2): sunucu / senkron birkaç gün dursa da arada ulaşan faturalar kaçmaz.
// Aralık Entegratör Bağlantıları ekranından (en az 15 dk, İzibiz kuralı): komut
// dakikada bir çağrılır, aralık dolmadıysa hiçbir şey yapmadan çıkar
Schedule::command('efatura:senkron --gun=10 --tarih-turu=DELIVERY --aralik-denetimi')
    ->everyMinute()
    ->withoutOverlapping(60);

// e-Fatura alarmları (EFAT-13): senkrondan SONRA (aynı dakikada sırayla çalışır)
Schedule::command('efatura:alarmlar')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// eMOR kolonu: gelen faturalar ERP'ye (TOHOM_FATURA) işlenmiş mi — yalnız SELECT
Schedule::command('efatura:emor')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Vergi istisna kodu önce ERP havuzundaki UBL'den, yoksa İzibiz'den: toplu indirme, çalışma başına sınırlı
// istek, fatura başına bir kez (okundu bayraklarını değiştirmez)
Schedule::command('efatura:istisna-kodlari')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);

// Durum tazeleme: son 45 günün belge tarihli faturaları (kabul/red/GİB durumu)
Schedule::command('efatura:senkron --gun=45')
    ->dailyAt('03:15')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120);

// Protel — otelin posta kutusu (PRT-08). PROTEL_AKTIF kapalıyken ya da aktif
// Protel tanımı yokken komut istek göndermeden çıkar. İzibiz'le aynı düzen:
// artımlı son 10 gün (Protel'e ulaşma tarihi), tek istek; gece durum tazeleme
// (ERP durumu / yazdırıldı / kabul-red) son 45 gün, ay başına tek istek.
Schedule::command('efatura:senkron --saglayici=protel --gun=10 --tarih-turu=DELIVERY --aralik-denetimi')
    ->everyMinute()
    ->withoutOverlapping(60);

Schedule::command('efatura:senkron --saglayici=protel --gun=45')
    ->dailyAt('03:30')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120);

// Yedekleme (docs/yedekleme). YEDEKLEME_AKTIF kapalıyken komutlar hiçbir şey
// yapmadan çıkar. Asıl çakışma koruması dosya kilididir (YedekKilidi);
// zamanlayıcının kilidi veritabanındaki önbellektedir, geri yüklemede kaybolur.
// Yedek, yayın sırasında (bakım modu) da alınabilmeli (README §4.2).
Schedule::command('yedek:zamanla')
    ->everyMinute()
    ->evenInMaintenanceMode()
    ->withoutOverlapping(120);

// Temizlik her yedekten sonra da çalışır; günlük çalışma elle alınan ve
// yayın öncesi yedeklerin sürelerini işletir (README §4.3)
Schedule::command('yedek:temizle')
    ->dailyAt('04:10')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60);
