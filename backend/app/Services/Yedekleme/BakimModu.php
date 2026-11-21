<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use JsonException;
use Throwable;

/**
 * Üstüne geri yüklemenin bakım modu (README §6.4 2. ve 10. adım; BY-028).
 * Laravel'in dosya sürücüsüyle aynı dosyayı (`storage/framework/down`) aynı
 * biçimde yazar; `artisan down` ÇAĞRILMAZ: o komut ayrıca
 * `storage/framework/maintenance.php`'yi (web'in her istekte yüklediği PHP)
 * yazar — servis hesabı web'in çalıştırdığı kodu yazmamalı (D7). Dosyada
 * `template` olmadığı için o betik (yayından kalmışsa bile) isteği Laravel'e
 * bırakır; ilerleme ucu bakım modundan muaftır (bootstrap/app.php).
 *
 * Dosya web'in yazabildiği klasördedir: yazım bağlantı İZLEMEZ (D5,
 * BY-003-R6). Yol ya da klasörü bağlantıysa hiçbir şey yazılmaz; yazım geçici
 * ada (`x` kipi, bağlantıyı izlemez) yapılıp tek adımda taşınır — taşıma,
 * hedefteki bağlantının kendisini değiştirir, gösterdiği dosyayı değil.
 *
 * Yalnız kendi yazdığını kaldırır (`yedekleme_islemi` alanı = işlem
 * kimliği): başkasının (ör. elle `artisan down`) bakım modu bu işlemle açılmaz.
 */
final class BakimModu
{
    /** Bu işlemin dosyasını tanıtan alan; Laravel bilmediği alanı okumaz */
    public const ISARET = 'yedekleme_islemi';

    public function yol(): string
    {
        return storage_path('framework/down');
    }

    /** Uygulama bakım modunda mı (kimin açtığına bakmadan) */
    public function aktifMi(): bool
    {
        $yol = $this->yol();
        clearstatcache(true, $yol);

        return file_exists($yol) || is_link($yol);
    }

    /**
     * Bu işlemin bakım modunu açar. Dosya zaten bu işlemin ise bir şey yapmaz
     * (yarıda kalan işlemin yeniden denenmesi).
     *
     * @throws GeriYuklemeHatasi BAKIM_MODU: sürücü dosya değil, yol bağlantı, başkası bakım moduna almış ya da yazılamadı
     */
    public function gir(string $islemKimligi): void
    {
        $this->surucuDosyaOlmali();
        $yol = $this->yol();
        $this->baglantiOlmamali($yol);

        if ($this->aktifMi()) {
            if ($this->isaret($yol) === $islemKimligi) {
                return;
            }

            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Uygulama zaten bakım modunda (başka bir işlem ya da elle); geri yükleme başlamadı. Önce bakım modunun nedenini inceleyin.');
        }

        // Laravel'in `artisan down` yükü (DownCommand::getDownFilePayload) + işaret
        $yuk = [
            'except' => [], 'redirect' => null, 'retry' => null, 'refresh' => null,
            'secret' => null, 'status' => 503, 'template' => null,
            self::ISARET => $islemKimligi,
        ];

        try {
            AtomikDosya::yaz($yol, json_encode($yuk, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0644);
        } catch (Throwable $hata) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Bakım modu dosyası yazılamadı: '.$hata::class);
        }
    }

    /**
     * Bu işlemin bakım modunu kaldırır; dosya yoksa ya da başkasınınsa
     * dokunmaz (başkasının bakım modu bu işlemle açılmaz).
     *
     * @return bool Bakım modu kalktı mı (dosya artık yok)
     *
     * @throws GeriYuklemeHatasi BAKIM_MODU: yol bağlantı ya da silinemedi
     */
    public function cik(string $islemKimligi): bool
    {
        $yol = $this->yol();
        $this->baglantiOlmamali($yol);

        if (! $this->aktifMi()) {
            return true;
        }

        if ($this->isaret($yol) !== $islemKimligi) {
            return false;
        }

        // unlink bağlantıyı izlemez; yukarıda bağlantı olmadığı da denetlendi
        if (! @unlink($yol)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Bakım modu dosyası silinemedi.');
        }

        return true;
    }

    /**
     * Yol ya da klasörü (storage/framework, storage) bağlantıysa durur:
     * web kullanıcısı onları değiştirebilir (D5).
     *
     * @throws GeriYuklemeHatasi
     */
    private function baglantiOlmamali(string $yol): void
    {
        foreach ([$yol, dirname($yol), dirname($yol, 2)] as $parca) {
            clearstatcache(true, $parca);
            if (is_link($parca)) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, "Bakım modu yolu bir bağlantı ({$parca}); dosyaya dokunulmadı.");
            }
        }
    }

    /**
     * @throws GeriYuklemeHatasi
     */
    private function surucuDosyaOlmali(): void
    {
        if (config('app.maintenance.driver', 'file') !== 'file') {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Bakım modu sürücüsü dosya değil (APP_MAINTENANCE_DRIVER); üstüne geri yükleme veritabanına dayanmayan dosya sürücüsünü ister.');
        }
    }

    /** Dosyadaki işaret; düz dosya değilse ya da okunamıyorsa null */
    private function isaret(string $yol): ?string
    {
        if (is_link($yol) || ! is_file($yol)) {
            return null;
        }

        $icerik = @file_get_contents($yol, length: 65_536);
        if (! is_string($icerik)) {
            return null;
        }

        try {
            $veri = json_decode($icerik, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($veri) && is_string($veri[self::ISARET] ?? null) ? $veri[self::ISARET] : null;
    }
}
