<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PostgreSQL anlık görüntüsü: ayrı bir bağlantıda REPEATABLE READ, READ ONLY
 * işlem açılır, `pg_export_snapshot()` ile görüntü dışa verilir; sayımlar,
 * migration listesi ve veritabanı özellikleri bu işlemin içinden okunur.
 * İşlem açık kaldıkça `pg_dump --snapshot` aynı görüntüyü görür (README §6.1).
 *
 * Uygulamanın kendi bağlantısı kullanılmaz: onun işlem sayacı ve önbelleği
 * yedekle karışmasın. Bağlantı, araçların (pg_dump) kullandığı SOMUT tanımla
 * açılır (PgAraclari::tanim — BY-028-R4).
 */
final class PostgresKaynakVeritabani implements KaynakVeritabani
{
    private const BAGLANTI = 'yedekleme_anlik_goruntu';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly PgAraclari $pg,
    ) {}

    public function anlikGoruntuyle(Closure $is): mixed
    {
        config(['database.connections.'.self::BAGLANTI => $this->pg->tanim()]);
        DB::purge(self::BAGLANTI);
        $baglanti = DB::connection(self::BAGLANTI);
        $pdo = $baglanti->getPdo();

        try {
            $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $goruntu = $this->oku($baglanti);
            $sonuc = $is($goruntu);
            $pdo->exec('COMMIT');

            return $sonuc;
        } catch (Throwable $hata) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Bağlantı kopmuşsa işlem zaten kapanmıştır
            }

            throw $hata;
        } finally {
            DB::purge(self::BAGLANTI);
        }
    }

    private function oku(Connection $baglanti): AnlikGoruntu
    {
        $kimlik = (string) $baglanti->selectOne('SELECT pg_export_snapshot() AS k')->k;
        $verisiAlinmayan = $this->yapilandirma->verisiAlinmayanTablolar();

        $tablolar = array_map(
            fn (object $s): string => (string) $s->ad,
            $baglanti->select("SELECT c.relname AS ad FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.relname"),
        );

        $sayilar = [];
        foreach ($tablolar as $tablo) {
            // Ad SQL'e tırnaklı tanımlayıcı olarak girer (içindeki tırnak ikilenir)
            $sayilar[$tablo] = in_array($tablo, $verisiAlinmayan, true)
                ? 0
                : (int) $baglanti->selectOne('SELECT count(*) AS n FROM public."'.str_replace('"', '""', $tablo).'"')->n;
        }

        $migrationlar = in_array('migrations', $tablolar, true)
            ? array_map(fn (object $s): string => (string) $s->migration, $baglanti->select('SELECT migration FROM migrations ORDER BY id'))
            : [];

        $vt = $baglanti->selectOne('SELECT pg_encoding_to_char(encoding) AS kodlama, datcollate AS siralama, datctype AS karakter_turu, datacl::text AS yetkiler FROM pg_database WHERE datname = current_database()');
        $ayarlar = array_map(
            fn (object $s): string => (string) $s->ayar,
            $baglanti->select('SELECT unnest(s.setconfig) AS ayar FROM pg_db_role_setting s JOIN pg_database d ON d.oid = s.setdatabase WHERE d.datname = current_database() AND s.setrole = 0 ORDER BY 1'),
        );

        $sahipler = array_map(
            fn (object $s): string => (string) $s->sahip,
            $baglanti->select("SELECT DISTINCT tableowner AS sahip FROM pg_tables WHERE schemaname = 'public' ORDER BY 1"),
        );

        return new AnlikGoruntu(
            kimlik: $kimlik,
            satirSayilari: $sayilar,
            migrationlar: $migrationlar,
            ozellikler: [
                'kodlama' => (string) $vt->kodlama,
                'siralama' => (string) $vt->siralama,
                'karakter_turu' => (string) $vt->karakter_turu,
                'ayarlar' => $ayarlar,
                'baglanma_yetkileri' => $vt->yetkiler === null ? null : (string) $vt->yetkiler,
            ],
            sahipRol: $sahipler === [] ? null : implode(',', $sahipler),
            sunucuSurumu: (string) $baglanti->selectOne('SHOW server_version')->server_version,
        );
    }
}
