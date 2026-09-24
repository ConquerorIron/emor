<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Giriş artık izne bağlı (kullanıcı kararı 2026-09-24): ERP kullanıcıları
 * yalnız Kullanıcılar ekranında izin ve rol verildikten sonra girer. Şimdiye
 * kadar ERP şifresiyle kendiliğinden tanımlanmış, rolü olmayan (ve sistem
 * yöneticisi olmayan) kullanıcıların giriş izni kaldırılır; rol atanmış
 * olanlar izinli kalır. Geri alınamaz veri düzeltmesi: `down` boştur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('kaynak', 'erp')
            ->where('sistem_yoneticisi', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('kullanici_rolleri')
                ->whereColumn('kullanici_rolleri.user_id', 'users.id'))
            ->update(['aktif_mi' => false]);
    }

    public function down(): void
    {
        //
    }
};
