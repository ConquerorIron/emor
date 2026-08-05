<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
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
