<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PostgreSQL araçlarının (pg_dump, pg_restore, psql) tek çalıştırma noktası.
 * Komutlar argüman dizisiyle çalışır (kabuk yok; README §5.2, §8); her
 * çalıştırmada süre sınırı vardır (D1); parola yalnız PGPASSFILE'dan okunur.
 *
 * Süreç ortamındaki `PG*` değişkenlerinin hepsi temizlenir: devralınan
 * PGPASSWORD / PGSERVICE / PGHOST parola dosyasının ve verilen adresin önüne
 * geçerdi (Dikkat_Edilecekler G12).
 */
final class PgAraclari
{
    private ?ParolaDosyasi $parola = null;

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * Yedeklemenin bağlanacağı yer (config/database.php bağlantısı +
     * yedekleme rolü).
     */
    public function baglanti(): PgBaglantisi
    {
        $tanim = $this->tanim();

        return new PgBaglantisi(
            host: (string) ($tanim['host'] ?? '127.0.0.1'),
            port: (int) ($tanim['port'] ?? 5432),
            veritabani: (string) $tanim['database'],
            kullanici: $this->yapilandirma->rol() ?? (string) $tanim['username'],
            uygulamaRolu: (string) $tanim['username'],
        );
    }

    /**
     * Parola dosyasını hazırlar, işi yapar, geçici dosyayı her durumda siler.
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    public function parolaylaCalis(Closure $is): mixed
    {
        $kalici = $this->yapilandirma->parolaDosyasi();
        $tanim = $this->tanim();
        $baglanti = $this->baglanti();

        $this->parola = $kalici !== null
            ? ParolaDosyasi::kalici($kalici)
            : ParolaDosyasi::gecici($baglanti->host, $baglanti->port, $baglanti->kullanici, (string) ($tanim['password'] ?? ''));

        try {
            return $is();
        } finally {
            $this->parola->sil();
            $this->parola = null;
        }
    }

    /**
     * @param  list<string>  $komut  [araç adı, ...argümanlar]; araç adı yapılandırmadan mutlak yola çevrilir
     */
    public function calistir(array $komut, int $sure): ProcessResult
    {
        if ($this->parola === null) {
            throw new RuntimeException('PostgreSQL araçları parolaylaCalis() içinde çalıştırılmalı.');
        }

        $arac = array_shift($komut);
        $ortam = ['PGPASSFILE' => $this->parola->yol, 'PGCONNECT_TIMEOUT' => (string) $this->yapilandirma->sureSiniri('baglanti')];

        foreach (array_keys(getenv()) as $ad) {
            if (str_starts_with((string) $ad, 'PG') && ! isset($ortam[$ad])) {
                $ortam[$ad] = false;
            }
        }
        foreach (['PGPASSWORD', 'PGSERVICE', 'PGSERVICEFILE', 'PGHOST', 'PGHOSTADDR', 'PGPORT', 'PGUSER', 'PGDATABASE', 'PGOPTIONS'] as $ad) {
            $ortam[$ad] ??= false;
        }

        return Process::timeout($sure)
            ->env($ortam)
            ->run([$this->yapilandirma->arac((string) $arac), ...$komut]);
    }

    /** Aracın sürüm satırı (ör. "pg_dump (PostgreSQL) 18.6"); okunamazsa null. */
    public function surum(string $arac): ?string
    {
        $sonuc = Process::timeout(30)->run([$this->yapilandirma->arac($arac), '--version']);

        return $sonuc->successful() ? trim($sonuc->output()) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function tanim(): array
    {
        $tanim = config('database.connections.'.$this->yapilandirma->baglantiAdi());

        if (! is_array($tanim) || ($tanim['driver'] ?? null) !== 'pgsql' || ! is_string($tanim['database'] ?? null)) {
            throw new YapilandirmaHatasi('yedeklenecek bağlantı ('.$this->yapilandirma->baglantiAdi().') bir PostgreSQL bağlantısı değil.');
        }

        return $tanim;
    }
}
