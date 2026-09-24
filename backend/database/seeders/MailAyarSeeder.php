<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MailAyari;
use Illuminate\Database\Seeder;

/**
 * İlk SMTP tanımı (EFAT-12). Şifre BURADA YOK: seeder repoya girer; şifre
 * Ayarlar → Mail (SMTP) ekranından girilir. Tanım zaten varsa (elle
 * değiştirilmiş olabilir) dokunulmaz.
 */
final class MailAyarSeeder extends Seeder
{
    public function run(): void
    {
        $ayar = MailAyari::query()->firstOrCreate(
            ['anahtar' => MailAyari::ANAHTAR],
            [
                'sunucu' => 'smtp.office365.com',
                'port' => 587,
                'sifreleme' => MailAyari::SIFRELEME_TLS,
                'kullanici_adi' => 'emor@tersane-istanbul.com',
                'gonderen_adres' => 'emor@tersane-istanbul.com',
                'gonderen_ad' => 'eMOR ERP',
            ],
        );

        $this->command?->info($ayar->wasRecentlyCreated
            ? 'Mail (SMTP) tanımı oluşturuldu; şifreyi Ayarlar → Mail (SMTP) ekranından girin.'
            : 'Mail (SMTP) tanımı zaten var; dokunulmadı.');
    }
}
