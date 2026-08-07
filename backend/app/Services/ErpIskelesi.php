<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * ERP'nin oturumluk iskele tablolarını kurar.
 *
 * SOHOM_* kayıt proc'ları `#TOHOM_ISKELE_*` geçici tablolarını KULLANIR ama
 * YARATMAZ (proc'ta tek bir CREATE TABLE yoktur). ERP istemcisi bunları
 * oturum açarken kuruyor; biz de proc'u çağırmadan önce aynısını yapmak
 * zorundayız, yoksa SQL Server "Invalid object name '#TOHOM_ISKELE_SINIRLAMA'"
 * hatası verir.
 *
 * Geçici tablolar BAĞLANTIYA bağlıdır: kurulum ile proc çağrısı aynı bağlantıda
 * olmalıdır. Betik kendi içinde `IF EXISTS … DROP TABLE` ile başladığı için
 * tekrar tekrar çalıştırılabilir.
 */
final class ErpIskelesi
{
    /**
     * Kurulumun yapılıp yapılmadığı BAYRAKLA takip edilemez: bağlantı
     * koparsa (proc hata verip PDO oturumu düşürebiliyor) Laravel yeniden
     * bağlanır ve geçici tablolar yok olur — bayrak ise hâlâ "kurulu" der.
     * Bu yüzden her seferinde tablonun gerçekten orada olduğuna bakılır.
     */
    private const TANIK_TABLO = 'tempdb..#TOHOM_ISKELE_SINIRLAMA';

    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function kur(): Connection
    {
        $baglanti = $this->mssql->baglan();

        if ($baglanti->scalar('SELECT OBJECT_ID(?)', [self::TANIK_TABLO]) !== null) {
            return $baglanti;
        }

        foreach ($this->topluIsler() as $toplu) {
            $baglanti->unprepared($toplu);
        }

        return $baglanti;
    }

    /**
     * Betik GO ayraçlarıyla bölünmüş toplu işlerden oluşur; PDO tek çağrıda
     * birden çok toplu iş gönderemediği için ayrı ayrı çalıştırılır.
     *
     * @return list<string>
     */
    private function topluIsler(): array
    {
        $yol = base_path('../SQL/Iskeleler.sql');

        if (! is_file($yol)) {
            throw new RuntimeException("ERP iskele betiği bulunamadı: {$yol}");
        }

        $parcalar = preg_split('/^\s*go\s*$/mi', (string) file_get_contents($yol)) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $parcalar),
            static fn (string $toplu): bool => $toplu !== '',
        ));
    }
}
