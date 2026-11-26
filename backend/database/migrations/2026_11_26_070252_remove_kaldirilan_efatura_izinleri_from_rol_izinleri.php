<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Katalogdan kaldırılan izinler (kullanıcı kararı 2026-11-26): RED'e çekme
 * yalnız Protel faturasında; Logo ve e-Arşiv ekranlarında PDF yok (belgenin
 * aslı elimizde değil). Rollerde kalan kayıtlar okunmaz ama Roller ekranında
 * karşılığı olmayan izin olarak durmasın diye silinir.
 */
return new class extends Migration
{
    private const IZINLER = [
        'efatura.red',
        'logo_faturalari.red',
        'logo_faturalari.pdf',
        'earsiv_faturalari.red',
        'earsiv_faturalari.pdf',
    ];

    public function up(): void
    {
        DB::table('rol_izinleri')->whereIn('izin', self::IZINLER)->delete();
    }

    /** Silinen izinler hangi rolde olduğu bilinmeden geri verilemez; geri dönüşte bir şey yapılmaz. */
    public function down(): void {}
};
