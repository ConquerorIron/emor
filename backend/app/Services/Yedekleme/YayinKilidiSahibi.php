<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Bu süreçte yayın kilidini kim tutuyor (Codex G1-8-R1). Servis döngüsü
 * (`yedek:calistir`) normal işleri (istekler, planlı yedek, temizlik, ayar
 * kopyası) yayın kilidini TUTARAK yürütür: denetimle işin başlaması arasında
 * yayın başlayamaz. O sırada işlenen üstüne geri yükleme isteği aynı kilidi
 * ikinci bir tutamaçla almaya kalkarsa (flock tutamaç başınadır) kendi
 * sürecine takılırdı; kilidin bu süreçte zaten tutulduğunu buradan öğrenir.
 *
 * Konteynerde tekil (AppServiceProvider). Kilidi bırakan, onu alandır.
 */
final class YayinKilidiSahibi
{
    private ?YedekKilidi $kilit = null;

    public function tut(YedekKilidi $kilit): void
    {
        $this->kilit = $kilit;
    }

    public function birak(): void
    {
        $this->kilit?->birak();
        $this->kilit = null;
    }

    public function tutuluyorMu(): bool
    {
        return $this->kilit !== null;
    }
}
