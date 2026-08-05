<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SatinalmaSecenekController;
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

        // Satınalma — ERP view'larından seçim listeleri
        Route::get('/satinalma/secenekler/personeller', [SatinalmaSecenekController::class, 'personeller'])
            ->name('satinalma.secenekler.personeller');

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
