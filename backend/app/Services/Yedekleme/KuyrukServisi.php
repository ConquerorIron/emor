<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Illuminate\Support\Facades\Process;

/**
 * Üstüne geri yüklemede kuyruk işçisinin servis olarak durdurulması ve
 * başlatılması (README §6.4 2. ve 10. adım, §9 madde 1). Servis hesabı
 * yalnız `systemctl stop|start <servis>` komutlarını şifresiz çalıştırabilir
 * (kurulum betiği bölüm 7). `-n`: şifre sorulmaz, kural yoksa hemen hata.
 *
 * Durdurma o anki işin bitmesini bekler (işçinin iş süresi sınırı 300 sn):
 * geri yükleme öncesi yedek, yarım kalmış bir iş yazarken alınmasın.
 * Servis tanımlı değilse (`kuyruk_servisi` boş) hiçbir şey yapılmaz.
 */
final class KuyrukServisi
{
    /** systemctl'in en çok bekleyeceği süre (sn): işçinin iş süresi sınırının üstünde */
    private const SURE = 420;

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @throws GeriYuklemeHatasi KUYRUK_SERVISI
     */
    public function durdur(): void
    {
        $this->calistir('stop');
    }

    /**
     * @throws GeriYuklemeHatasi KUYRUK_SERVISI
     */
    public function baslat(): void
    {
        $this->calistir('start');
    }

    /**
     * @param  'stop'|'start'  $eylem
     *
     * @throws GeriYuklemeHatasi
     */
    private function calistir(string $eylem): void
    {
        try {
            $servis = $this->yapilandirma->kuyrukServisi();
            if ($servis === null) {
                return;
            }

            $sonuc = Process::timeout(self::SURE)->run([
                $this->yapilandirma->arac('sudo'), '-n', $this->yapilandirma->arac('systemctl'), $eylem, $servis,
            ]);
        } catch (YapilandirmaHatasi $hata) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KUYRUK_SERVISI, $hata->getMessage());
        }

        if (! $sonuc->successful()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KUYRUK_SERVISI, "Kuyruk servisi ({$servis}) için '{$eylem}' başarısız (çıkış {$sonuc->exitCode()}).");
        }
    }
}
