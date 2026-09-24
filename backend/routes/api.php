<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlarmKuraliController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EFaturaController;
use App\Http\Controllers\Api\V1\EFaturaSenkronController;
use App\Http\Controllers\Api\V1\EkranTasarimController;
use App\Http\Controllers\Api\V1\EntegratorBaglantiController;
use App\Http\Controllers\Api\V1\KullaniciController;
use App\Http\Controllers\Api\V1\MailAyarController;
use App\Http\Controllers\Api\V1\RolController;
use App\Http\Controllers\Api\V1\SatinalmaTalebiController;
use App\Http\Controllers\Api\V1\SecenekController;
use App\Http\Controllers\Api\V1\SqlBaglantiController;
use App\Models\AlarmKurali;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    Route::middleware(['auth:sanctum', 'aktif'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

        // ERP view'larından ortak seçim listeleri (program genelinde kullanılır)
        Route::get('/secenekler/personeller', [SecenekController::class, 'personeller'])
            ->name('secenekler.personeller');
        Route::get('/secenekler/tablo-maddesi/{tur}', [SecenekController::class, 'tabloMaddesi'])
            ->whereNumber('tur')
            ->name('secenekler.tablo-maddesi');
        Route::get('/secenekler/ilgili/{cins}', [SecenekController::class, 'ilgili'])
            ->whereNumber('cins')
            ->name('secenekler.ilgili');
        Route::get('/secenekler/depolar', [SecenekController::class, 'depolar'])
            ->name('secenekler.depolar');
        Route::get('/secenekler/firmamiz-adresleri', [SecenekController::class, 'firmamizAdresleri'])
            ->name('secenekler.firmamiz-adresleri');
        // Talep satırı listeleri — seçili projeye göre süzülür
        Route::get('/secenekler/aktiviteler/{projemizId}', [SecenekController::class, 'aktiviteler'])
            ->whereNumber('projemizId')
            ->name('secenekler.aktiviteler');
        Route::get('/secenekler/masraf-merkezleri/{projemizId}', [SecenekController::class, 'masrafMerkezleri'])
            ->whereNumber('projemizId')
            ->name('secenekler.masraf-merkezleri');
        // Ürün listesi büyük: arama sunucuda (?ara=), ilk 50 kayıt döner
        Route::get('/secenekler/urunler', [SecenekController::class, 'urunler'])
            ->name('secenekler.urunler');
        Route::get('/secenekler/ekipmanlar', [SecenekController::class, 'ekipmanlar'])
            ->name('secenekler.ekipmanlar');
        Route::get('/secenekler/butce-kalemleri/{projemizId}', [SecenekController::class, 'butceKalemleri'])
            ->whereNumber('projemizId')
            ->name('secenekler.butce-kalemleri');
        Route::get('/secenekler/duran-varliklar', [SecenekController::class, 'duranVarliklar'])
            ->name('secenekler.duran-varliklar');
        Route::get('/secenekler/ambalajlar', [SecenekController::class, 'ambalajlar'])
            ->name('secenekler.ambalajlar');
        Route::get('/secenekler/paralar', [SecenekController::class, 'paralar'])
            ->name('secenekler.paralar');
        Route::get('/secenekler/onay-rolleri', [SecenekController::class, 'onayRolleri'])
            ->name('secenekler.onay-rolleri');
        // Kur, belge tarihine göre okunur (ISO tarih)
        Route::get('/secenekler/kur/{paraId}/{tarih}', [SecenekController::class, 'kur'])
            ->whereNumber('paraId')
            ->where('tarih', '\d{4}-\d{2}-\d{2}')
            ->name('secenekler.kur');
        // Satırdaki personel: parti yaması ağacından (başlıktaki İK kartı değil)
        Route::get('/secenekler/parti-yamasi-personelleri', [SecenekController::class, 'partiYamasiPersonelleri'])
            ->name('secenekler.parti-yamasi-personelleri');

        // Satınalma Talebi — kayıt doğrudan ERP'ye (SOHOM_SIPARIS_KAYDET)
        Route::post('/satinalma/talepler', [SatinalmaTalebiController::class, 'kaydet'])
            ->middleware('can:satinalma_talebi.guncelle')
            ->name('satinalma.talepler.kaydet');
        Route::get('/satinalma/ozellikler', [SatinalmaTalebiController::class, 'ozellikler'])
            ->middleware('can:satinalma_talebi.goruntule')
            ->name('satinalma.ozellikler');

        // Ekran tasarım motoru — yayındaki tasarım herkese (form çizimi),
        // taslak/sürümler ve düzenleme Ekran Tasarımı izinleriyle
        Route::get('/ekranlar/{ekran}/tasarim', [EkranTasarimController::class, 'goster'])
            ->name('ekranlar.tasarim');
        Route::middleware('can:ekran_tasarimi.goruntule')->group(function (): void {
            Route::get('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslak'])
                ->name('ekranlar.taslak');
            Route::get('/ekranlar/{ekran}/surumler', [EkranTasarimController::class, 'surumler'])
                ->name('ekranlar.surumler');
        });
        Route::middleware('can:ekran_tasarimi.guncelle')->group(function (): void {
            Route::put('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslagiKaydet'])
                ->name('ekranlar.taslak.kaydet');
            Route::post('/ekranlar/{ekran}/yayinla', [EkranTasarimController::class, 'yayinla'])
                ->name('ekranlar.yayinla');
            Route::post('/ekranlar/{ekran}/surumler/{surum}/geri-al', [EkranTasarimController::class, 'geriAl'])
                ->whereNumber('surum')
                ->name('ekranlar.surumler.geri-al');
        });

        // e-Fatura ekranları (EFAT-11) — kapsam aktif entegratör hesabı; izinler EFAT-18.
        // Her uç önce görüntüleme iznini ister: PDF/Excel/senkron izni tek başına
        // listeyi görmeyen birine verinin tamamını açmaz.
        Route::middleware('can:efatura.goruntule')->group(function (): void {
            Route::get('/efatura/durum', [EFaturaSenkronController::class, 'durum'])
                ->name('efatura.durum');
            Route::get('/efatura/{yon}/faturalar', [EFaturaController::class, 'index'])
                ->whereIn('yon', ['gelen', 'giden'])
                ->name('efatura.faturalar');
            Route::get('/efatura/{yon}/faturalar/excel', [EFaturaController::class, 'excel'])
                ->whereIn('yon', ['gelen', 'giden'])
                ->middleware(['can:efatura.disari_aktar', 'throttle:efatura-excel'])
                ->name('efatura.faturalar.excel');
            Route::get('/efatura/faturalar/{fatura}/pdf', [EFaturaController::class, 'pdf'])
                ->whereNumber('fatura')
                ->middleware(['can:efatura.pdf', 'throttle:efatura-pdf'])
                ->name('efatura.faturalar.pdf');
            Route::get('/efatura/faturalar/{fatura}/xml', [EFaturaController::class, 'xml'])
                ->whereNumber('fatura')
                ->middleware(['can:efatura.pdf', 'throttle:efatura-pdf'])
                ->name('efatura.faturalar.xml');
            Route::put('/efatura/faturalar/{fatura}/gizli', [EFaturaController::class, 'gizle'])
                ->whereNumber('fatura')
                ->middleware('can:efatura.gizle')
                ->name('efatura.faturalar.gizle');
            Route::post('/efatura/senkron', [EFaturaSenkronController::class, 'baslat'])
                ->middleware('can:efatura.senkron')
                ->name('efatura.senkron');
            // "ERP Senkronla": eMOR (ERP'ye işlendi mi) hemen tazelenir
            Route::post('/efatura/erp-senkron', [EFaturaSenkronController::class, 'erpEslestir'])
                ->middleware(['can:efatura.senkron', 'throttle:efatura-erp'])
                ->name('efatura.erp-senkron');
        });

        // Header'daki Test/Canlı rozeti — her kullanıcıya yalnız ortam adı döner
        Route::get('/aktif-ortam', [SqlBaglantiController::class, 'aktifOrtam'])
            ->name('aktif-ortam');

        // Ayarlar ekranları: her ekran kendi görüntüle/güncelle izniyle
        // (App\Yetki\Izin::ekranlar). Sınama ve test maili de güncelleme sayılır.

        // Ayarlar → SQL Bağlantıları (Test/Canlı MSSQL tanımları + aktif ortam)
        Route::get('/ayarlar/sql-baglantilari', [SqlBaglantiController::class, 'index'])
            ->middleware('can:sql_baglantilari.goruntule')
            ->name('ayarlar.sql-baglantilari');
        Route::middleware('can:sql_baglantilari.guncelle')->group(function (): void {
            Route::post('/ayarlar/sql-baglantilari/aktif', [SqlBaglantiController::class, 'aktifYap'])
                ->name('ayarlar.sql-baglantilari.aktif');
            Route::put('/ayarlar/sql-baglantilari/{ortam}', [SqlBaglantiController::class, 'guncelle'])
                ->whereIn('ortam', ['test', 'canli'])
                ->name('ayarlar.sql-baglantilari.guncelle');
            Route::post('/ayarlar/sql-baglantilari/{ortam}/sina', [SqlBaglantiController::class, 'sina'])
                ->whereIn('ortam', ['test', 'canli'])
                ->middleware('throttle:sql-sina')
                ->name('ayarlar.sql-baglantilari.sina');
        });

        // Ayarlar → Entegratör Bağlantıları (Test/Canlı İzibiz tanımları);
        // aktif ortam SQL'den bağımsız seçilir (EFAT-15, S5)
        Route::get('/ayarlar/entegrator-baglantilari', [EntegratorBaglantiController::class, 'index'])
            ->middleware('can:entegrator_baglantilari.goruntule')
            ->name('ayarlar.entegrator-baglantilari');
        Route::middleware('can:entegrator_baglantilari.guncelle')->group(function (): void {
            Route::post('/ayarlar/entegrator-baglantilari/aktif', [EntegratorBaglantiController::class, 'aktifYap'])
                ->name('ayarlar.entegrator-baglantilari.aktif');
            Route::put('/ayarlar/entegrator-baglantilari/{ortam}', [EntegratorBaglantiController::class, 'guncelle'])
                ->whereIn('ortam', ['test', 'canli'])
                ->name('ayarlar.entegrator-baglantilari.guncelle');
            Route::post('/ayarlar/entegrator-baglantilari/{ortam}/sina', [EntegratorBaglantiController::class, 'sina'])
                ->whereIn('ortam', ['test', 'canli'])
                ->middleware('throttle:entegrator-sina')
                ->name('ayarlar.entegrator-baglantilari.sina');
        });

        // Ayarlar → Roller (EFAT-18). Rol listesi Kullanıcılar ekranında da
        // (rol ataması) gerekir: `rol-listesi` Gate'i ikisinden birini ister
        Route::get('/ayarlar/izinler', [RolController::class, 'izinler'])
            ->middleware('can:roller.goruntule')
            ->name('ayarlar.izinler');
        Route::get('/ayarlar/roller', [RolController::class, 'index'])
            ->middleware('can:rol-listesi')
            ->name('ayarlar.roller');
        Route::middleware('can:roller.guncelle')->group(function (): void {
            Route::post('/ayarlar/roller', [RolController::class, 'store'])
                ->name('ayarlar.roller.store');
            Route::put('/ayarlar/roller/{rol}', [RolController::class, 'update'])
                ->whereNumber('rol')
                ->name('ayarlar.roller.update');
            Route::delete('/ayarlar/roller/{rol}', [RolController::class, 'destroy'])
                ->whereNumber('rol')
                ->name('ayarlar.roller.destroy');
        });

        // Ayarlar → Kullanıcılar (EFAT-18)
        Route::get('/ayarlar/kullanicilar', [KullaniciController::class, 'index'])
            ->middleware('can:kullanicilar.goruntule')
            ->name('ayarlar.kullanicilar');
        Route::middleware('can:kullanicilar.guncelle')->group(function (): void {
            Route::post('/ayarlar/kullanicilar', [KullaniciController::class, 'store'])
                ->name('ayarlar.kullanicilar.store');
            Route::put('/ayarlar/kullanicilar/{kullanici}', [KullaniciController::class, 'update'])
                ->whereNumber('kullanici')
                ->name('ayarlar.kullanicilar.update');
        });

        // Ayarlar → Alarm Kuralları (EFAT-13)
        Route::get('/ayarlar/alarm-kurallari', [AlarmKuraliController::class, 'index'])
            ->middleware('can:alarm_kurallari.goruntule')
            ->name('ayarlar.alarm-kurallari');
        Route::put('/ayarlar/alarm-kurallari/{tur}', [AlarmKuraliController::class, 'update'])
            ->whereIn('tur', AlarmKurali::TURLER)
            ->middleware('can:alarm_kurallari.guncelle')
            ->name('ayarlar.alarm-kurallari.update');

        // Ayarlar → Mail (SMTP): uygulamanın giden mail tanımı
        Route::get('/ayarlar/mail', [MailAyarController::class, 'goster'])
            ->middleware('can:mail_ayarlari.goruntule')
            ->name('ayarlar.mail');
        Route::middleware('can:mail_ayarlari.guncelle')->group(function (): void {
            Route::put('/ayarlar/mail', [MailAyarController::class, 'guncelle'])
                ->name('ayarlar.mail.guncelle');
            Route::post('/ayarlar/mail/test', [MailAyarController::class, 'testGonder'])
                ->middleware('throttle:mail-test')
                ->name('ayarlar.mail.test');
        });
    });
});
