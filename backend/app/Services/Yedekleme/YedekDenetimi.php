<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekIslemKaydi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Yedekleme denetim kaydı (README §4.9): veritabanına (YedekIslemKaydi,
 * salt eklemeli) VE dosyaya (`yedekleme` günlük kanalı). Veritabanı geri
 * yüklenince içindeki kayıtlar eskir; dosya kalır (README §7.3). Veritabanına
 * yazılamazsa (geri yükleme sırasında, bağlantı yokken) dosya yine yazılır.
 * Hata mesajı kısaltılır; parola / anahtar taşıyan alanlar buraya verilmez.
 */
final class YedekDenetimi
{
    /**
     * @param  array<string, mixed>  $ayrinti
     */
    public function kaydet(
        string $islem,
        IslemSonucu $sonuc,
        ?int $yedekId = null,
        ?int $kullaniciId = null,
        ?int $sureMs = null,
        array $ayrinti = [],
        ?string $hata = null,
        ?int $geriYuklemeId = null,
    ): void {
        $hata = $hata === null ? null : Str::limit($hata, 1000);

        Log::channel('yedekleme')->info('yedekleme.'.$islem, array_filter([
            'sonuc' => $sonuc->value,
            'yedek_id' => $yedekId,
            'geri_yukleme_id' => $geriYuklemeId,
            'kullanici_id' => $kullaniciId,
            'sure_ms' => $sureMs,
            'ayrinti' => $ayrinti === [] ? null : $ayrinti,
            'hata' => $hata,
        ], fn (mixed $deger): bool => $deger !== null));

        try {
            YedekIslemKaydi::query()->create([
                'kullanici_id' => $kullaniciId,
                'islem' => $islem,
                'yedek_id' => $yedekId,
                'geri_yukleme_id' => $geriYuklemeId,
                'sonuc' => $sonuc,
                'sure_ms' => $sureMs,
                'ayrinti' => $ayrinti === [] ? null : $ayrinti,
                'hata_mesaji' => $hata,
            ]);
        } catch (Throwable $e) {
            Log::channel('yedekleme')->warning('Denetim kaydı veritabanına yazılamadı; yalnız dosyada', ['hata' => $e::class]);
        }
    }
}
