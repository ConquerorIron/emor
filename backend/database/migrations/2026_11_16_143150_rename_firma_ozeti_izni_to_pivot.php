<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Firma Özeti ekranı "Pivot" oldu (kullanıcı isteği 2026-11-16): izin anahtarı
 * `firma_ozeti.goruntule` → `pivot.goruntule`. Rollerdeki mevcut atamalar
 * taşınır — kimse ekrana erişimini kaybetmez. Yeni anahtar daha önce yoktu,
 * (rol_id, izin) birincil anahtarı çakışmaz.
 */
return new class extends Migration
{
    private const ESKI = 'firma_ozeti.goruntule';

    private const YENI = 'pivot.goruntule';

    public function up(): void
    {
        DB::table('rol_izinleri')->where('izin', self::ESKI)->update(['izin' => self::YENI]);
    }

    public function down(): void
    {
        DB::table('rol_izinleri')->where('izin', self::YENI)->update(['izin' => self::ESKI]);
    }
};
