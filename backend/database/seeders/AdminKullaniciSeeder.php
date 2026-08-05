<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Lokal fallback admin — ERP MSSQL erişilemez olsa bile Ayarlar ekranına
 * girilebilsin diye. Kimlik bilgileri .env'den gelir; şifre boşsa atlanır
 * (production'da bilinçli tanımlanmalı).
 */
final class AdminKullaniciSeeder extends Seeder
{
    public function run(): void
    {
        $kullaniciAdi = (string) config('erp.admin_kullanici');
        $sifre = (string) config('erp.admin_sifre');

        if ($kullaniciAdi === '' || $sifre === '') {
            $this->command?->warn('ERP_ADMIN_KULLANICI / ERP_ADMIN_SIFRE tanımsız — lokal admin oluşturulmadı.');

            return;
        }

        User::query()->updateOrCreate(
            ['kullanici_adi' => $kullaniciAdi, 'kaynak' => User::KAYNAK_LOKAL],
            ['ad' => 'Sistem Yöneticisi', 'password' => $sifre, 'aktif_mi' => true],
        );

        $this->command?->info(sprintf("Lokal admin hazır: '%s'", $kullaniciAdi));
    }
}
