<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SQL Bağlantıları ekranı artık yalnız sistem yöneticisine açık. Lokal
 * fallback admin, ERP erişilemezken bağlantıyı düzeltebilecek tek hesap
 * olduğundan mevcut kaydında bayrak açılır (yeni kurulumda seeder açar).
 * Lokal hesabın bayrağı ERP girişinde tazelenmez; bu değer kalıcıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->lokalAdmin()->update(['sistem_yoneticisi' => true]);
    }

    /**
     * Önceki yetki değeri saklanmadığından güvenli bir ters işlem yoktur.
     * Önceden yönetici olan veya sonradan yetkilendirilen hesabı düşürme;
     * yetki geri alınacaksa ayrı, hedefi belli bir işlemle yapılmalıdır.
     */
    public function down(): void {}

    private function lokalAdmin(): Builder
    {
        return DB::table('users')
            ->where('kaynak', 'lokal')
            ->where('kullanici_adi', (string) config('erp.admin_kullanici'));
    }
};
