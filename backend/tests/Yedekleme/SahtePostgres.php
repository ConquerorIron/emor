<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Geri yükleme testlerinin sahte PostgreSQL sunucusu (BY-026): psql
 * çağrılarını standart girdideki SQL'e göre yanıtlar, açılan / silinen
 * veritabanlarını bellekte tutar. YedeklemeOrtami ile birlikte kullanılır
 * (sahtePgAraclari'nın desenlerine eklenir; ilk eşleşen kazanır).
 */
trait SahtePostgres
{
    /** @var list<string> Sunucudaki veritabanları */
    protected array $veritabanlari = ['erp', 'postgres'];

    /** @var array{tablolar: array<string, int>, migrationlar: list<string>, sahipler: list<string>}|null Geri yüklenen veritabanının içeriği; null = kaynakla aynı */
    protected ?array $geriYuklenenIcerik = null;

    protected ?string $olusturmaHatasi = null;

    protected ?string $pgRestoreHatasi = null;

    protected ?string $silmeHatasi = null;

    /** `SHOW server_version_num` yanıtı (sunucu 18.6) */
    protected string $sunucuSurumNo = '180006';

    /** Canlı veritabanının boyutu (bayt; `pg_database_size`) */
    protected string $canliBoyutu = '1048576';

    /** Canlıdaki kimliğe bağlı özellikler: rol düzeyi ayar sayısı, bağlantı sınırı, sıralama sağlayıcısı (CSV) */
    protected string $canliOzellikleri = "0,-1,c\n";

    /** Bu rol adını taşıyan ACL çözülemez (rol sunucuda yok) */
    protected string $olmayanRol = 'emekli_rol';

    /**
     * @param  array<string, mixed>  $ozel
     */
    protected function sahtePostgres(array $ozel = []): void
    {
        $this->sahtePgAraclari($ozel + [
            '*psql*' => fn (PendingProcess $p) => $this->psqlYaniti($p),
            '*pg_restore*--single-transaction*' => fn () => $this->pgRestoreHatasi === null ? '' : Process::result(errorOutput: $this->pgRestoreHatasi, exitCode: 1),
        ]);
    }

    /**
     * @return array<string, string> psql --set değişkenleri
     */
    protected function psqlDegiskenleri(PendingProcess $p): array
    {
        $degiskenler = [];
        foreach ((array) $p->command as $arguman) {
            if (preg_match('/^--set=([a-z0-9_]+)=(.*)$/sD', (string) $arguman, $m) === 1) {
                $degiskenler[$m[1]] = $m[2];
            }
        }

        return $degiskenler;
    }

    /** SQL'inde $parca geçen bir psql çağrısı (ad verilirse o veritabanı için) çalıştı mı */
    protected function psqlCalisti(string $parca, ?string $ad = null): bool
    {
        try {
            Process::assertRan(fn (PendingProcess $p): bool => str_contains(implode(' ', (array) $p->command), 'psql')
                && str_contains((string) $p->input, $parca)
                && ($ad === null || ($this->psqlDegiskenleri($p)['ad'] ?? null) === $ad));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function pgRestoreGeriYukledi(): bool
    {
        try {
            Process::assertRan(fn (PendingProcess $p): bool => in_array('--single-transaction', (array) $p->command, true));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function psqlYaniti(PendingProcess $p): mixed
    {
        $sql = (string) $p->input;
        $ad = $this->psqlDegiskenleri($p)['ad'] ?? null;

        if (str_contains($sql, 'CREATE DATABASE')) {
            if ($this->olusturmaHatasi !== null || in_array($ad, $this->veritabanlari, true)) {
                return Process::result(errorOutput: $this->olusturmaHatasi ?? 'ERROR:  database already exists', exitCode: 1);
            }
            $this->veritabanlari[] = (string) $ad;

            return '';
        }

        if (str_contains($sql, 'DROP DATABASE')) {
            if ($this->silmeHatasi !== null) {
                return Process::result(errorOutput: $this->silmeHatasi, exitCode: 2);
            }
            $this->veritabanlari = array_values(array_diff($this->veritabanlari, [$ad]));

            return '';
        }

        if (str_contains($sql, "WHERE datname = :'ad'")) {
            return in_array($ad, $this->veritabanlari, true) ? "1\n" : '';
        }

        if (str_contains($sql, 'query_to_xml')) {
            $icerik = $this->geriYuklenenIcerik ?? [
                'tablolar' => $this->kaynakVeritabani->satirSayilari,
                'migrationlar' => $this->kaynakVeritabani->migrationlar,
                'sahipler' => ['erp'],
            ];
            $satirlar = [];
            foreach ($icerik['tablolar'] as $tablo => $sayi) {
                $satirlar[] = "tablo,{$tablo},{$sayi}";
            }
            foreach ($icerik['migrationlar'] as $i => $migration) {
                $satirlar[] = 'migration,'.$migration.','.($i + 1);
            }
            foreach ($icerik['sahipler'] as $sahip) {
                $satirlar[] = "sahip,{$sahip},";
            }

            return implode("\n", $satirlar)."\n";
        }

        if (str_contains($sql, 'erp\\_sinama\\_')) {
            return implode("\n", array_filter($this->veritabanlari, fn (string $v): bool => str_starts_with($v, 'erp_sinama_')))."\n";
        }

        if (str_contains($sql, 'erp\\_geri\\_')) {
            return implode("\n", array_filter($this->veritabanlari, fn (string $v): bool => str_starts_with($v, 'erp_geri_')))."\n";
        }

        if (str_contains($sql, 'server_version_num')) {
            return $this->sunucuSurumNo."\n";
        }

        if (str_contains($sql, 'pg_database_size')) {
            return $this->canliBoyutu."\n";
        }

        if (str_contains($sql, 'datconnlimit')) {
            return $this->canliOzellikleri;
        }

        if (str_contains($sql, '::aclitem[]')) {
            return str_contains($this->psqlDegiskenleri($p)['acl'] ?? '', $this->olmayanRol)
                ? Process::result(errorOutput: 'ERROR:  role "'.$this->olmayanRol.'" does not exist', exitCode: 3)
                : '';
        }

        if (str_contains($sql, 'current_database()')) {
            return "UTF8,C.UTF-8,C.UTF-8\n";
        }

        return '';
    }
}
