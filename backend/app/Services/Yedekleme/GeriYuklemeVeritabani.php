<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;
use LogicException;
use SplFileObject;
use SplTempFileObject;

/**
 * Geri yüklemenin PostgreSQL işlemleri (README §4.5-A, §5.2, §6.3; BY-026).
 * Hepsi `PgAraclari::parolaylaCalis()` içinde, psql / pg_restore ile çalışır
 * (parola PGPASSFILE'da, PG* ortamı temizlenmiş — D2, G12).
 *
 * SQL metni sabittir; ad ve değerler psql değişkeni olarak (`--set`) verilir
 * ve SQL'e psql'in kendi tırnaklamasıyla (`:"ad"` tanımlayıcı, `:'ad'` metin)
 * girer — elle tırnaklama yok (G3). Adlar ayrıca kalıpla sınırlıdır.
 *
 * Sahiplik (kesif.md §4): veritabanı `OWNER <uygulama rolü>` ile açılır,
 * `pg_restore --no-owner --role=<uygulama rolü>` ile yüklenir; servis hesabı
 * uygulama rolünün üyesidir. Silme yalnız VeritabaniAdi kalıplarındaki
 * adlara yapılır; uygulamanın kendi veritabanı hiçbir yoldan silinmez (K3).
 */
final class GeriYuklemeVeritabani
{
    /** CREATE / DROP DATABASE başka bir veritabanından yapılır (kesif.md §5) */
    public const YONETIM_VERITABANI = 'postgres';

    /**
     * Liste türündeki ayarlar (pg_dump `variable_is_guc_list_quote`): değerleri
     * tek metin olarak tırnaklanamaz. Bugün canlıda veritabanı düzeyinde ayar
     * yok (kesif.md §7); biri eklenirse geri yükleme sessizce yanlış ayarla
     * açılmasın diye reddedilir.
     */
    private const LISTE_AYARLARI = ['local_preload_libraries', 'search_path', 'session_preload_libraries', 'shared_preload_libraries', 'temp_tablespaces', 'unix_socket_directories'];

    /**
     * Satır sayıları (tablo başına), migration listesi ve tablo sahipleri tek
     * sorguda. Sayım sorgusunun tablo adı PostgreSQL'in `format('%I')`
     * tırnaklamasıyla kurulur; PHP tarafında SQL'e ad girmez.
     */
    private const ICERIK_SORGUSU = <<<'SQL'
        SELECT 'tablo', c.relname::text, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text
          FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
        UNION ALL
        SELECT 'migration', m.migration::text, m.id::text FROM public.migrations m
        UNION ALL
        SELECT DISTINCT 'sahip', t.tableowner::text, '' FROM pg_tables t WHERE t.schemaname = 'public';
        SQL;

    public function __construct(
        private readonly PgAraclari $pg,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    /**
     * Bu sınıfın komutları için parola dosyasını hazırlar. PgAraclari
     * kapsayıcıda tekil değildir: parola dosyası, komutu çalıştıracak nesnede
     * hazırlanmalı — bu yüzden çağıranlar buradan geçer.
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    public function parolaylaCalis(Closure $is): mixed
    {
        return $this->pg->parolaylaCalis($is);
    }

    public function varMi(string $ad): bool
    {
        $this->yonetilenOlmali($ad);

        return trim($this->psql(self::YONETIM_VERITABANI, "SELECT 1 FROM pg_database WHERE datname = :'ad';", ['ad' => $ad], GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI)) === '1';
    }

    /**
     * Manifestteki özelliklerin denetimi: veritabanı açılmadan ÖNCE çağrılır;
     * desteklenmeyen özellikte hiçbir şey açılmaz.
     *
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}  $ozellikler
     * @return list<array{0: string, 1: string}> [ayar adı, değer]
     *
     * @throws GeriYuklemeHatasi
     */
    public static function ozellikleriDenetle(array $ozellikler): array
    {
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/D', $ozellikler['kodlama']) !== 1
            || preg_match('/^[A-Za-z0-9_.@-]{1,64}$/D', $ozellikler['siralama']) !== 1
            || preg_match('/^[A-Za-z0-9_.@-]{1,64}$/D', $ozellikler['karakter_turu']) !== 1) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_OZELLIGI, 'Manifestteki kodlama / sıralama değeri tanınmıyor.');
        }

        $ayarlar = [];
        foreach ($ozellikler['ayarlar'] as $ayar) {
            $esittir = strpos($ayar, '=');
            $ad = $esittir === false ? '' : substr($ayar, 0, $esittir);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,62}$/D', $ad) !== 1) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_OZELLIGI, 'Manifestteki veritabanı ayarı tanınmıyor.');
            }

            if (in_array(strtolower($ad), self::LISTE_AYARLARI, true)) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_OZELLIGI, "Liste türündeki veritabanı ayarı ({$ad}) geri yüklemede desteklenmiyor; elle uygulanmalı.");
            }

            $ayarlar[] = [$ad, substr($ayar, $esittir + 1)];
        }

        return $ayarlar;
    }

    /**
     * Boş veritabanını manifestteki kodlama ve sıralamayla, sahibi uygulama
     * rolü olarak açar (tek komut; başarısızsa veritabanı açılmamıştır).
     *
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}  $ozellikler
     */
    public function olustur(string $ad, array $ozellikler): void
    {
        $this->yonetilenOlmali($ad);
        self::ozellikleriDenetle($ozellikler);

        $this->psql(
            self::YONETIM_VERITABANI,
            "CREATE DATABASE :\"ad\" OWNER :\"sahip\" TEMPLATE template0 ENCODING :'kodlama' LC_COLLATE :'siralama' LC_CTYPE :'karakter_turu';",
            ['ad' => $ad, 'sahip' => $this->pg->baglanti()->uygulamaRolu, 'kodlama' => $ozellikler['kodlama'], 'siralama' => $ozellikler['siralama'], 'karakter_turu' => $ozellikler['karakter_turu']],
            GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI,
        );
    }

    /**
     * Veritabanı düzeyindeki ayarlar (ör. TimeZone): `--create` olmadan arşivden
     * uygulanmadıkları için manifestten verilir (kesif.md §7).
     *
     * @param  list<array{0: string, 1: string}>  $ayarlar  ozellikleriDenetle() çıktısı
     */
    public function ayarla(string $ad, array $ayarlar): void
    {
        if ($ayarlar === []) {
            return;
        }

        $this->yonetilenOlmali($ad);
        $sql = '';
        $degiskenler = ['ad' => $ad];
        foreach (array_values($ayarlar) as $i => [$ayar, $deger]) {
            $sql .= "ALTER DATABASE :\"ad\" SET :\"a{$i}\" = :'d{$i}';\n";
            $degiskenler["a{$i}"] = $ayar;
            $degiskenler["d{$i}"] = $deger;
        }

        $this->psql(self::YONETIM_VERITABANI, $sql, $degiskenler, GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI);
    }

    /**
     * `--exit-on-error --single-transaction`: ya tamamı yüklenir ya hiçbiri
     * (README §6.3 4. adım). Hata metninin yalnız ilk satırı alınır: devamı
     * (CONTEXT, "Command was") satır verisi taşıyabilir (D2).
     */
    public function geriYukle(string $ad, string $dosya): void
    {
        $this->yonetilenOlmali($ad);
        $baglanti = $this->pg->baglanti();

        $sonuc = $this->pg->calistir([
            'pg_restore', '--exit-on-error', '--single-transaction', '--no-owner', '--role='.$baglanti->uygulamaRolu,
            ...$baglanti->argumanlar($ad), $dosya,
        ], $this->yapilandirma->sureSiniri('geri_yukleme'));

        if (! $sonuc->successful()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::PG_RESTORE, 'pg_restore başarısız (çıkış '.$sonuc->exitCode().'): '.self::ilkSatir($sonuc->errorOutput()));
        }
    }

    /**
     * Geri yüklenen veritabanının içeriği (karşılaştırma için).
     *
     * @return array{tablolar: array<string, int>, migrationlar: list<string>, sahipler: list<string>}
     */
    public function icerik(string $ad): array
    {
        $this->yonetilenOlmali($ad);
        $cikti = $this->psql($ad, self::ICERIK_SORGUSU, [], GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, csv: true);

        $tablolar = [];
        $migrationlar = [];
        $sahipler = [];
        foreach ($this->csvSatirlari($cikti) as $satir) {
            if (count($satir) !== 3) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, 'Geri yüklenen veritabanının içeriği okunamadı.');
            }

            [$tur, $deger, $sayi] = $satir;
            match ($tur) {
                'tablo' => $tablolar[$deger] = (int) $sayi,
                'migration' => $migrationlar[(int) $sayi] = $deger,
                'sahip' => $sahipler[] = $deger,
                default => throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, 'Geri yüklenen veritabanının içeriği okunamadı.'),
            };
        }
        ksort($migrationlar);

        return ['tablolar' => $tablolar, 'migrationlar' => array_values($migrationlar), 'sahipler' => $sahipler];
    }

    /**
     * Manifesti özellik taşımayan (içe alınmış eski) yedek için: kaynak
     * veritabanının kodlaması ve sıralaması. Ayar taşınmaz.
     *
     * @return array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}
     */
    public function kaynakOzellikleri(): array
    {
        $baglanti = $this->pg->baglanti();
        $satirlar = $this->csvSatirlari($this->psql(
            $baglanti->veritabani,
            'SELECT pg_encoding_to_char(encoding), datcollate, datctype FROM pg_database WHERE datname = current_database();',
            [],
            GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI,
            csv: true,
        ));

        if (count($satirlar) !== 1 || count($satirlar[0]) !== 3) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI, 'Kaynak veritabanının kodlaması okunamadı.');
        }

        return ['kodlama' => $satirlar[0][0], 'siralama' => $satirlar[0][1], 'karakter_turu' => $satirlar[0][2], 'ayarlar' => [], 'baglanma_yetkileri' => null];
    }

    /**
     * Çökmüş bir sınamadan kalmış olabilecek veritabanları (yalnız sınama kalıbı).
     *
     * @return list<string>
     */
    public function sinamaVeritabanlari(): array
    {
        $cikti = $this->psql(self::YONETIM_VERITABANI, "SELECT datname FROM pg_database WHERE datname LIKE 'erp\\_sinama\\_%' ORDER BY 1;", [], GeriYuklemeHatasi::VERITABANI_SILINEMEDI);

        return array_values(array_filter(array_map('trim', explode("\n", $cikti)), VeritabaniAdi::sinamaMi(...)));
    }

    /**
     * Veritabanını bağlantılarını koparıp siler. Yalnız çalıştırıcının
     * kalıbındaki adlar; uygulamanın kendi veritabanı asla (K3).
     *
     * @throws LogicException Ad yönetilen bir ad değil; hiçbir komut çalışmadı
     */
    public function dusur(string $ad): void
    {
        $this->yonetilenOlmali($ad);

        $this->psql(self::YONETIM_VERITABANI, 'DROP DATABASE IF EXISTS :"ad" WITH (FORCE);', ['ad' => $ad], GeriYuklemeHatasi::VERITABANI_SILINEMEDI);
    }

    /**
     * Canlı veritabanının boyutu (bayt): üstüne geri yüklemenin disk ön
     * denetimi için (BY-028).
     */
    public function boyut(string $canli): int
    {
        $this->canliOlmali($canli);
        $cikti = trim($this->psql(self::YONETIM_VERITABANI, "SELECT pg_database_size(:'ad');", ['ad' => $canli], GeriYuklemeHatasi::DISK_YETERSIZ));

        if (preg_match('/^[0-9]{1,19}$/D', $cikti) !== 1) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::DISK_YETERSIZ, 'Canlı veritabanının boyutu okunamadı.');
        }

        return (int) $cikti;
    }

    /**
     * Verilen adlardan sunucuda var olanlar. Üstüne geri yüklemede "hangi
     * tarafta kalındı" kararı adlara bakarak verilir (README §6.4 yarıda kalan
     * işlem): yer değiştirme tek işlemdir, adlar ya eski ya yeni hâlindedir.
     *
     * @param  list<string>  $adlar  Canlı ad, `erp_onceki_*` ya da `erp_geri_*`
     * @return list<string>
     */
    public function mevcutlar(array $adlar): array
    {
        $adlar = array_values(array_unique($adlar));
        $yer = [];
        $degiskenler = [];
        foreach ($adlar as $i => $ad) {
            $this->ustuneAdOlmali($ad);
            $yer[] = ":'a{$i}'";
            $degiskenler["a{$i}"] = $ad;
        }

        $cikti = $this->psql(self::YONETIM_VERITABANI, 'SELECT datname FROM pg_database WHERE datname IN ('.implode(', ', $yer).') ORDER BY 1;', $degiskenler, GeriYuklemeHatasi::ADLAR_TUTARSIZ);

        return array_values(array_intersect($adlar, array_map('trim', explode("\n", $cikti))));
    }

    /**
     * Önce yeni bağlantılara kapatır (`ALLOW_CONNECTIONS false`), sonra açık
     * bağlantıları koparır ve hiçbiri kalmayana kadar (en çok `$saniye`)
     * bekler. Sıra önemlidir: önce koparılırsa araya giren bağlantı ad
     * değiştirmeyi bozar (README §6.4 6. adım; kesif.md §6). Koparılamayan
     * bağlantı (ör. yönetici oturumu) istisnadır; çağıran geri sarar.
     *
     * @throws GeriYuklemeHatasi BAGLANTI_KAPATILAMADI
     */
    public function baglantilariKapat(string $ad, int $saniye = 15): void
    {
        $this->ustuneAdOlmali($ad);
        $this->psql(
            self::YONETIM_VERITABANI,
            "ALTER DATABASE :\"ad\" WITH ALLOW_CONNECTIONS false;\nSELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity WHERE datname = :'ad' AND pid <> pg_backend_pid();",
            ['ad' => $ad],
            GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI,
        );

        // pg_terminate_backend sinyal gönderir; süreçlerin bitmesi beklenir.
        // Süre monoton saatle ölçülür (D1).
        $son = hrtime(true) + $saniye * 1_000_000_000;
        while (true) {
            $acik = trim($this->psql(self::YONETIM_VERITABANI, "SELECT count(*) FROM pg_stat_activity WHERE datname = :'ad';", ['ad' => $ad], GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI));
            if ($acik === '0') {
                return;
            }

            if (hrtime(true) >= $son) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI, "{$ad} veritabanında koparılamayan {$acik} bağlantı kaldı.");
            }

            usleep(200_000);
        }
    }

    public function baglantiyaAc(string $ad): void
    {
        $this->ustuneAdOlmali($ad);
        $this->psql(self::YONETIM_VERITABANI, 'ALTER DATABASE :"ad" WITH ALLOW_CONNECTIONS true;', ['ad' => $ad], GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI);
    }

    /**
     * Veritabanının bağlanma yetkileri (`datacl::text`); null = hiç
     * değiştirilmemiş, varsayılan yetkiler (sahibe her şey, PUBLIC'e
     * CONNECT + TEMP).
     *
     * @throws GeriYuklemeHatasi Veritabanı yok ya da okunamadı
     */
    public function yetkileriOku(string $ad): ?string
    {
        $this->yetkiAdiOlmali($ad);
        $satirlar = $this->csvSatirlari($this->psql(
            self::YONETIM_VERITABANI,
            "SELECT datacl IS NULL, coalesce(datacl::text, '') FROM pg_database WHERE datname = :'ad';",
            ['ad' => $ad],
            GeriYuklemeHatasi::VERITABANI_YETKILERI,
            csv: true,
        ));

        if (count($satirlar) !== 1 || count($satirlar[0]) !== 2 || ! in_array($satirlar[0][0], ['t', 'f'], true)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_YETKILERI, "{$ad} veritabanının bağlanma yetkileri okunamadı.");
        }

        return $satirlar[0][0] === 't' ? null : $satirlar[0][1];
    }

    /**
     * Veritabanının bağlanma yetkilerini verilen ACL'ye EŞDEĞER yapar
     * (BY-026-R1, BY-028-R1): şimdiki ve hedefteki her role (PUBLIC dahil)
     * bütün yetkiler geri alınır, hedefteki yetkiler verilir; sonuç
     * (rol, yetki, devredilebilir) kümesi olarak hedefle karşılaştırılır.
     * Tek DO bloğu = tek işlem: eşdeğerlik sağlanamazsa (rol yok, başka bir
     * vericinin verdiği yetki kaldı) yetkiler HİÇ değişmez, istisna.
     *
     * Veren (grantor) karşılaştırılmaz: servis rolü sahibin üyesi olarak
     * verir, kayıtta veren sahip olur. `null` = varsayılan yetkiler
     * (`acldefault`); sonuç açık yazılmış ama eşdeğer bir ACL'dir.
     *
     * @param  string|null  $acl  `datacl::text` (manifestteki `baglanma_yetkileri` ya da yetkileriOku())
     *
     * @throws GeriYuklemeHatasi VERITABANI_YETKILERI (yetkiler değişmedi)
     */
    public function yetkileriUygula(string $ad, ?string $acl): void
    {
        $this->yetkiAdiOlmali($ad);

        $this->psql(self::YONETIM_VERITABANI, <<<'SQL'
            SELECT set_config('erp_yedek.vt', :'ad', false), set_config('erp_yedek.acl', :'acl', false), set_config('erp_yedek.varsayilan', :'varsayilan', false);
            DO $$
            DECLARE
              vt text := current_setting('erp_yedek.vt');
              hedef aclitem[];
              simdiki aclitem[];
              r record;
            BEGIN
              SELECT coalesce(d.datacl, acldefault('d', d.datdba)),
                     CASE WHEN current_setting('erp_yedek.varsayilan') = '1' THEN acldefault('d', d.datdba) ELSE current_setting('erp_yedek.acl')::aclitem[] END
                INTO simdiki, hedef FROM pg_database d WHERE d.datname = vt;
              IF NOT FOUND THEN
                RAISE EXCEPTION 'veritabani bulunamadi';
              END IF;
              FOR r IN SELECT DISTINCT g.grantee FROM (SELECT a.grantee FROM aclexplode(simdiki) a UNION ALL SELECT b.grantee FROM aclexplode(hedef) b) g LOOP
                EXECUTE format('REVOKE ALL ON DATABASE %I FROM %s CASCADE', vt, CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END);
              END LOOP;
              FOR r IN SELECT * FROM aclexplode(hedef) LOOP
                EXECUTE format('GRANT %s ON DATABASE %I TO %s%s', r.privilege_type, vt,
                  CASE WHEN r.grantee = 0 THEN 'PUBLIC' ELSE quote_ident(pg_get_userbyid(r.grantee)) END,
                  CASE WHEN r.is_grantable THEN ' WITH GRANT OPTION' ELSE '' END);
              END LOOP;
              IF (SELECT array_agg(x ORDER BY x) FROM (SELECT a.grantee::text || ':' || a.privilege_type || ':' || a.is_grantable::text AS x
                    FROM pg_database d, aclexplode(coalesce(d.datacl, acldefault('d', d.datdba))) a WHERE d.datname = vt) s)
                 IS DISTINCT FROM
                 (SELECT array_agg(x ORDER BY x) FROM (SELECT a.grantee::text || ':' || a.privilege_type || ':' || a.is_grantable::text AS x FROM aclexplode(hedef) a) s) THEN
                RAISE EXCEPTION 'baglanma yetkileri esdeger uygulanamadi';
              END IF;
            END $$;
            SQL, ['ad' => $ad, 'acl' => $acl ?? '', 'varsayilan' => $acl === null ? '1' : '0'], GeriYuklemeHatasi::VERITABANI_YETKILERI);
    }

    /**
     * Veritabanının bağlanma yetkileri verilen ACL'ye eşdeğer mi — hiçbir şeyi
     * DEĞİŞTİRMEDEN, yetkileriUygula()'nın sonundaki ölçütle: (rol, yetki,
     * devredilebilir) kümesi, veren karşılaştırılmaz; `null` = varsayılan
     * yetkiler. Elle kurtarmada açılıştan önceki denetim (kurtarma.md;
     * BY-031-R2). ACL değeri psql değişkeni + current_setting ile girer:
     * sabit olarak verilseydi kullanılmayan CASE dalı da planlamada çözülürdü.
     *
     * @throws GeriYuklemeHatasi VERITABANI_YETKILERI (veritabanı yok, okunamadı ya da ACL çözülemedi)
     */
    public function yetkilerEsdegerMi(string $ad, ?string $acl): bool
    {
        $this->yetkiAdiOlmali($ad);

        $satirlar = $this->csvSatirlari($this->psql(self::YONETIM_VERITABANI, <<<'SQL'
            SELECT set_config('erp_yedek.acl', :'acl', false), set_config('erp_yedek.varsayilan', :'varsayilan', false) \g /dev/null
            SELECT (SELECT array_agg(x ORDER BY x) FROM (SELECT a.grantee::text || ':' || a.privilege_type || ':' || a.is_grantable::text AS x
                      FROM aclexplode(coalesce(d.datacl, acldefault('d', d.datdba))) a) s)
                   IS NOT DISTINCT FROM
                   (SELECT array_agg(x ORDER BY x) FROM (SELECT a.grantee::text || ':' || a.privilege_type || ':' || a.is_grantable::text AS x
                      FROM aclexplode(CASE WHEN current_setting('erp_yedek.varsayilan') = '1' THEN acldefault('d', d.datdba) ELSE current_setting('erp_yedek.acl')::aclitem[] END) a) s)
              FROM pg_database d WHERE d.datname = :'ad';
            SQL, ['ad' => $ad, 'acl' => $acl ?? '', 'varsayilan' => $acl === null ? '1' : '0'], GeriYuklemeHatasi::VERITABANI_YETKILERI, csv: true));

        if (count($satirlar) !== 1 || count($satirlar[0]) !== 1 || ! in_array($satirlar[0][0], ['t', 'f'], true)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::VERITABANI_YETKILERI, "{$ad} veritabanının bağlanma yetkileri okunamadı.");
        }

        return $satirlar[0][0] === 't';
    }

    /**
     * Yazıcı bariyeri (README §6.4 2–3. adım arası; BY-028-R1): canlıya
     * bağlanma yetkisi yalnız servis rolünde kalır (PUBLIC'ten ve diğer
     * rollerden CONNECT geri alınır — tek DO bloğu, ya hepsi ya hiçbiri), sonra
     * canlıdaki diğer istemci oturumları sonlandırılır ve bitmeleri beklenir.
     * Sonlandırılan oturumun açık işlemi geri alınır (istemci hata görür):
     * bariyerden sonra alınan yedek, kabul edilmiş her yazıyı içerir.
     *
     * Bakım modu yeni web isteklerini keser; ama başlamış istek, zamanlanmış
     * komut ya da sonradan bağlantı açan iş, bu bariyer olmadan öncesi yedekten
     * sonra da canlıya yazabilirdi. Yetkiyi geri koymak çağıranın işidir
     * (özgün ACL işlem durumundadır: yetkileriUygula()).
     *
     * @param  list<int>  $haricPidler  Bu sürecin canlıdaki kendi oturumları (sonlandırılmaz)
     *
     * @throws GeriYuklemeHatasi YAZICI_BARIYERI
     */
    public function bariyerKur(string $canli, string $servisRolu, array $haricPidler, int $saniye = 15): void
    {
        $this->canliOlmali($canli);
        $haric = implode(',', array_map('intval', $haricPidler));

        $this->psql(self::YONETIM_VERITABANI, <<<'SQL'
            SELECT set_config('erp_yedek.vt', :'ad', false), set_config('erp_yedek.rol', :'rol', false);
            DO $$
            DECLARE
              vt text := current_setting('erp_yedek.vt');
              rol text := current_setting('erp_yedek.rol');
              r record;
            BEGIN
              FOR r IN SELECT DISTINCT a.grantee FROM pg_database d, aclexplode(coalesce(d.datacl, acldefault('d', d.datdba))) a
                        WHERE d.datname = vt AND a.privilege_type = 'CONNECT' LOOP
                IF r.grantee = 0 THEN
                  EXECUTE format('REVOKE CONNECT ON DATABASE %I FROM PUBLIC CASCADE', vt);
                ELSIF pg_get_userbyid(r.grantee) <> rol THEN
                  EXECUTE format('REVOKE CONNECT ON DATABASE %I FROM %I CASCADE', vt, pg_get_userbyid(r.grantee));
                END IF;
              END LOOP;
              EXECUTE format('GRANT CONNECT ON DATABASE %I TO %I', vt, rol);
              IF EXISTS (SELECT 1 FROM pg_database d, aclexplode(d.datacl) a
                          WHERE d.datname = vt AND a.privilege_type = 'CONNECT' AND a.grantee <> (SELECT oid FROM pg_roles WHERE rolname = rol)) THEN
                RAISE EXCEPTION 'servis rolu disinda baglanma yetkisi kaldi';
              END IF;
            END $$;
            SELECT count(pg_terminate_backend(pid)) FROM pg_stat_activity
             WHERE datname = :'ad' AND backend_type = 'client backend' AND pid <> pg_backend_pid() AND NOT (pid = ANY (string_to_array(:'haric', ',')::int[]));
            SQL, ['ad' => $canli, 'rol' => $servisRolu, 'haric' => $haric], GeriYuklemeHatasi::YAZICI_BARIYERI);

        // Sonlandırma sinyaldir; oturumların bitmesi beklenir (monoton saat — D1)
        $son = hrtime(true) + $saniye * 1_000_000_000;
        while (true) {
            $acik = trim($this->psql(
                self::YONETIM_VERITABANI,
                "SELECT count(*) FROM pg_stat_activity WHERE datname = :'ad' AND backend_type = 'client backend' AND pid <> pg_backend_pid() AND NOT (pid = ANY (string_to_array(:'haric', ',')::int[]));",
                ['ad' => $canli, 'haric' => $haric],
                GeriYuklemeHatasi::YAZICI_BARIYERI,
            ));
            if ($acik === '0') {
                return;
            }

            if (hrtime(true) >= $son) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::YAZICI_BARIYERI, "{$canli} veritabanında sonlandırılamayan {$acik} oturum kaldı (başka rolün ya da yöneticinin oturumu olabilir).");
            }

            usleep(200_000);
        }
    }

    /**
     * Bu süreçten canlıya YENİ açılan Laravel bağlantıları, iş süresince
     * servis rolüyle ve parola dosyasıyla açılır (yazıcı bariyerinde CONNECT
     * yalnız onda — BY-028-R1). Parola PHP'ye girmez: libpq'ya parola
     * verilmez, `PGPASSFILE`'dan okur (D2). Açık bağlantılar etkilenmez; iş
     * bitince yapılandırma ve ortam eski hâline döner. Bağlantı adını
     * değiştirmez: uygulama rolü (PgAraclari::baglanti) yalnız bu sürede
     * okunmamalı — çağıran yalnız yedek almayı sarar.
     *
     * Tanım bütünüyle SOMUT tanımla değiştirilir (`url` yok): yalnız kullanıcı
     * ve parola değişseydi Laravel bağlantıyı açarken DB_URL'deki uygulama
     * rolünü ve parolasını onların üstüne yazardı (BY-028-R4).
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    public function servisRoluyle(string $servisRolu, string $parolaDosyasi, Closure $is): mixed
    {
        $anahtar = 'database.connections.'.$this->yapilandirma->baglantiAdi();
        $onceki = config($anahtar);
        $oncekiOrtam = getenv('PGPASSFILE');

        config([$anahtar => $this->pg->servisRoluTanimi($servisRolu)]);
        putenv('PGPASSFILE='.$parolaDosyasi);

        try {
            return $is();
        } finally {
            config([$anahtar => $onceki]);
            putenv($oncekiOrtam === false ? 'PGPASSFILE' : 'PGPASSFILE='.$oncekiOrtam);
        }
    }

    /**
     * İki ad değişikliği TEK işlemde: ikincisi hata verirse birincisi de geri
     * alınır, adlar değişmez (kesif.md §5). Bağlı olunan veritabanının adı
     * değiştirilemediği için `postgres`'ten yapılır. İleri: canlı → önceki,
     * geri yüklenen → canlı. Geri dönüş: canlı → geri yüklenenin adı,
     * önceki → canlı.
     *
     * @param  array{0: string, 1: string}  $birinci  [eski ad, yeni ad]
     * @param  array{0: string, 1: string}  $ikinci  [eski ad, yeni ad]
     *
     * @throws GeriYuklemeHatasi YER_DEGISTIRILEMEDI (adlar değişmedi)
     */
    public function yerDegistir(array $birinci, array $ikinci): void
    {
        foreach ([...$birinci, ...$ikinci] as $ad) {
            $this->ustuneAdOlmali($ad);
        }

        $this->psql(
            self::YONETIM_VERITABANI,
            "BEGIN;\nALTER DATABASE :\"a\" RENAME TO :\"b\";\nALTER DATABASE :\"c\" RENAME TO :\"d\";\nCOMMIT;",
            ['a' => $birinci[0], 'b' => $birinci[1], 'c' => $ikinci[0], 'd' => $ikinci[1]],
            GeriYuklemeHatasi::YER_DEGISTIRILEMEDI,
        );
    }

    /** Üstüne geri yüklemenin dokunabildiği adlar: canlı ad, `erp_onceki_*`, `erp_geri_*` */
    private function ustuneAdOlmali(string $ad): void
    {
        if ($ad !== $this->pg->baglanti()->veritabani && ! VeritabaniAdi::oncekiMi($ad) && ! VeritabaniAdi::kopyaMi($ad)) {
            throw new LogicException('Üstüne geri yükleme yalnız canlı veritabanında, erp_onceki_* ve erp_geri_* adlarında çalışır.');
        }
    }

    /** Yetkileri okunan / değiştirilen adlar: üstüne geri yüklemenin adları ve kopya / sınama */
    private function yetkiAdiOlmali(string $ad): void
    {
        if (! VeritabaniAdi::yonetilenMi($ad)) {
            $this->ustuneAdOlmali($ad);
        }
    }

    private function canliOlmali(string $ad): void
    {
        if ($ad !== $this->pg->baglanti()->veritabani) {
            throw new LogicException('Bu işlem yalnız canlı veritabanı için yapılır.');
        }
    }

    private function yonetilenOlmali(string $ad): void
    {
        if (! VeritabaniAdi::yonetilenMi($ad) || $ad === $this->pg->baglanti()->veritabani) {
            throw new LogicException('Geri yükleme yalnız kendi açtığı kalıptaki veritabanlarında çalışır (erp_geri_* / erp_sinama_*).');
        }
    }

    /**
     * @param  array<string, string>  $degiskenler  psql değişkenleri (SQL'de `:'ad'` / `:"ad"`)
     *
     * @throws GeriYuklemeHatasi Komut başarısız ($hataKodu ile)
     */
    private function psql(string $veritabani, string $sql, array $degiskenler, string $hataKodu, bool $csv = false): string
    {
        $komut = ['psql', '--no-psqlrc', '--quiet', '--set=ON_ERROR_STOP=1', $csv ? '--csv' : '--no-align', '--tuples-only', ...$this->pg->baglanti()->argumanlar($veritabani)];
        foreach ($degiskenler as $ad => $deger) {
            $komut[] = '--set='.$ad.'='.$deger;
        }
        $komut[] = '--file=-';

        $sonuc = $this->pg->calistir($komut, $this->yapilandirma->sureSiniri('geri_yukleme'), $sql);

        if (! $sonuc->successful()) {
            throw new GeriYuklemeHatasi($hataKodu, 'psql başarısız (çıkış '.$sonuc->exitCode().'): '.self::ilkSatir($sonuc->errorOutput()));
        }

        return $sonuc->output();
    }

    /**
     * @return list<list<string>>
     */
    private function csvSatirlari(string $cikti): array
    {
        $dosya = new SplTempFileObject;
        $dosya->fwrite($cikti);
        $dosya->rewind();
        $dosya->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $dosya->setCsvControl(',', '"', '');

        $satirlar = [];
        foreach ($dosya as $satir) {
            if (is_array($satir) && $satir !== [null]) {
                $satirlar[] = array_map(fn (mixed $h): string => (string) $h, $satir);
            }
        }

        return $satirlar;
    }

    private static function ilkSatir(string $metin): string
    {
        return mb_substr(strtok(trim($metin), "\n") ?: '', 0, 200);
    }
}
