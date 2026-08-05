<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SqlBaglanti;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP MSSQL bağlantı yönetimi. Bağlantı tanımları PostgreSQL'de tutulur
 * (SqlBaglanti); bu servis aktif tanımı çalışma zamanında Laravel'in
 * 'erp' bağlantısı olarak kurar. Store proc çalıştıracak tüm modüller
 * MSSQL'e BU servis üzerinden erişir.
 */
final class MssqlBaglantiServisi
{
    /** Çalışma zamanında kurulan Laravel bağlantı adı. */
    public const BAGLANTI_ADI = 'erp';

    /**
     * @return array<string, SqlBaglanti> ortam => tanım
     */
    public function listele(): array
    {
        return SqlBaglanti::query()
            ->orderBy('ortam')
            ->get()
            ->keyBy('ortam')
            ->all();
    }

    public function aktif(): ?SqlBaglanti
    {
        return SqlBaglanti::query()->where('aktif', true)->first();
    }

    /**
     * Ortam tanımını oluşturur/günceller. Şifre yalnızca gönderildiyse değişir
     * (form maskeli gösterir — puantaj MailAyarServisi deseni).
     *
     * @param  array{sunucu: string, port?: int|null, veritabani: string, kullanici_adi: string, sifre?: string|null}  $veri
     */
    public function guncelle(string $ortam, array $veri): SqlBaglanti
    {
        return DB::transaction(function () use ($ortam, $veri): SqlBaglanti {
            $baglanti = SqlBaglanti::query()
                ->where('ortam', $ortam)
                ->lockForUpdate()
                ->first() ?? new SqlBaglanti(['ortam' => $ortam]);

            $sifre = $veri['sifre'] ?? null;

            if (! $baglanti->exists && ($sifre === null || $sifre === '')) {
                throw ValidationException::withMessages([
                    'sifre' => __('hata.sql_sifre_zorunlu'),
                ]);
            }

            $baglanti->fill([
                'sunucu' => $veri['sunucu'],
                'port' => $veri['port'] ?? null,
                'veritabani' => $veri['veritabani'],
                'kullanici_adi' => $veri['kullanici_adi'],
            ]);

            if ($sifre !== null && $sifre !== '') {
                $baglanti->sifre = $sifre;
            }

            $baglanti->save();

            return $baglanti;
        });
    }

    /**
     * Global aktif ortamı değiştirir — bundan sonra tüm store proc'lar
     * bu ortamın MSSQL'inde çalışır.
     */
    public function aktifYap(string $ortam): SqlBaglanti
    {
        return DB::transaction(function () use ($ortam): SqlBaglanti {
            $hedef = SqlBaglanti::query()
                ->where('ortam', $ortam)
                ->lockForUpdate()
                ->first();

            if ($hedef === null) {
                throw ValidationException::withMessages([
                    'ortam' => __('hata.sql_baglanti_tanimsiz'),
                ]);
            }

            SqlBaglanti::query()->where('id', '!=', $hedef->id)->update(['aktif' => false]);
            $hedef->aktif = true;
            $hedef->save();

            // Önceden kurulmuş bağlantı eski ortamı taşımasın
            DB::purge(self::BAGLANTI_ADI);

            return $hedef;
        });
    }

    /**
     * Aktif ortamın MSSQL bağlantısını döner — store proc exec'leri için
     * tek giriş noktası.
     */
    public function baglan(?SqlBaglanti $tanim = null): Connection
    {
        $tanim ??= $this->aktif();

        if ($tanim === null) {
            throw ValidationException::withMessages([
                'ortam' => __('hata.sql_aktif_ortam_yok'),
            ]);
        }

        Config::set('database.connections.'.self::BAGLANTI_ADI, $this->baglantiConfig($tanim));
        DB::purge(self::BAGLANTI_ADI);

        return DB::connection(self::BAGLANTI_ADI);
    }

    /**
     * Tanımla bağlantıyı dener; sunucu sürümü ve veritabanı adını döner.
     * Başarısızlıkta kullanıcıya anlaşılır 422 döner.
     *
     * @return array{surum: string, veritabani: string, kullanici: string}
     */
    public function sina(SqlBaglanti $tanim): array
    {
        try {
            $baglanti = $this->baglan($tanim);

            /** @var object{surum: string, veritabani: string, kullanici: string}|null $satir */
            $satir = $baglanti->selectOne(
                'SELECT @@VERSION AS surum, DB_NAME() AS veritabani, SUSER_SNAME() AS kullanici',
            );
        } catch (ValidationException $hata) {
            throw $hata;
        } catch (Throwable $hata) {
            throw ValidationException::withMessages([
                'sunucu' => __('hata.sql_baglanti_basarisiz', ['detay' => $hata->getMessage()]),
            ]);
        } finally {
            // Sınama bağlantısı kalıcı olmasın — sonraki istek aktif tanımı kurar
            DB::purge(self::BAGLANTI_ADI);
        }

        if ($satir === null) {
            throw ValidationException::withMessages([
                'sunucu' => __('hata.sql_baglanti_basarisiz', ['detay' => 'Sunucu yanıt vermedi.']),
            ]);
        }

        return [
            // @@VERSION çok satırlıdır; ilk satır yeterli (ör. "Microsoft SQL Server 2019 ...")
            'surum' => strtok($satir->surum, "\n") ?: $satir->surum,
            'veritabani' => $satir->veritabani,
            'kullanici' => $satir->kullanici,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baglantiConfig(SqlBaglanti $tanim): array
    {
        $config = [
            'driver' => 'sqlsrv',
            'host' => $tanim->sunucu,
            'database' => $tanim->veritabani,
            'username' => $tanim->kullanici_adi,
            'password' => $tanim->sifre,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // Şirket içi sunucular genellikle self-signed sertifika kullanır
            'encrypt' => 'yes',
            'trust_server_certificate' => 'true',
            'login_timeout' => 5,
        ];

        // Named instance ("SUNUCU\INSTANCE") kullanılıyorsa port verilmez
        if ($tanim->port !== null) {
            $config['port'] = $tanim->port;
        }

        return $config;
    }
}
