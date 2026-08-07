<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EkranTasarimController;
use App\Http\Controllers\Api\V1\SecenekController;
use App\Http\Controllers\Api\V1\SqlBaglantiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    Route::middleware('auth:sanctum')->group(function (): void {
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

        // Ekran tasarım motoru — okuma herkese (form çizimi), düzenleme yöneticiye
        Route::get('/ekranlar/{ekran}/tasarim', [EkranTasarimController::class, 'goster'])
            ->name('ekranlar.tasarim');
        Route::get('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslak'])
            ->name('ekranlar.taslak');
        Route::put('/ekranlar/{ekran}/taslak', [EkranTasarimController::class, 'taslagiKaydet'])
            ->name('ekranlar.taslak.kaydet');
        Route::post('/ekranlar/{ekran}/yayinla', [EkranTasarimController::class, 'yayinla'])
            ->name('ekranlar.yayinla');
        Route::get('/ekranlar/{ekran}/surumler', [EkranTasarimController::class, 'surumler'])
            ->name('ekranlar.surumler');
        Route::post('/ekranlar/{ekran}/surumler/{surum}/geri-al', [EkranTasarimController::class, 'geriAl'])
            ->whereNumber('surum')
            ->name('ekranlar.surumler.geri-al');

        // Ayarlar → SQL Bağlantıları (Test/Canlı MSSQL tanımları + aktif ortam)
        Route::get('/ayarlar/sql-baglantilari', [SqlBaglantiController::class, 'index'])
            ->name('ayarlar.sql-baglantilari');
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
});
