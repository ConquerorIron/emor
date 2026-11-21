<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use App\Services\Yedekleme\KaynakVeritabani;
use App\Services\Yedekleme\YedekDeposu;
use App\Services\Yedekleme\YedekImzasi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Yedekleme testlerinin ortak ortamı: geçici klasörler, imza anahtarı, sahte
 * anlık görüntü ve sahte PostgreSQL araçları (README §10: dış komutlar
 * Process::fake ile; testler SQLite).
 */
trait YedeklemeOrtami
{
    protected string $yedekKoku;

    protected SahteKaynakVeritabani $kaynakVeritabani;

    protected ?YedekKilidi $tutulanKilit = null;

    protected function yedeklemeOrtaminiKur(): void
    {
        $this->yedekKoku = sys_get_temp_dir().'/emor-yedek-test-'.bin2hex(random_bytes(5));
        mkdir($this->yedekKoku, 0750);

        config([
            'yedekleme.klasor' => $this->yedekKoku.'/yedekler',
            'yedekleme.durum_klasoru' => $this->yedekKoku.'/durum',
            'yedekleme.kalici_kok' => sys_get_temp_dir(),
            'yedekleme.imza_anahtari' => $this->yedekKoku.'/imza.key',
            'yedekleme.istek_klasoru' => $this->yedekKoku.'/istekler',
            'yedekleme.ayar_kopyasi' => $this->yedekKoku.'/durum/ayarlar.json',
            'yedekleme.parola_dosyasi' => null,
            'yedekleme.baglanti' => 'yedek_test',
            'database.connections.yedek_test' => [
                'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
                'database' => 'erp', 'username' => 'erp', 'password' => 'gizli:parola\\1',
            ],
            'yedekleme.araclar' => ['pg_dump' => '/usr/bin/pg_dump', 'pg_restore' => '/usr/bin/pg_restore', 'psql' => '/usr/bin/psql'],
        ]);

        // Kurulumdaki gibi: yedek klasörü depo işaretiyle hazır (YedekDeposu; BY-011-R3)
        mkdir($this->yedekKoku.'/yedekler', 0750);
        file_put_contents($this->yedekKoku.'/yedekler/'.YedekDeposu::ISARET, 'emor-yedek-deposu '.Str::uuid()->toString()."\n");

        app(YedekImzasi::class)->anahtarUret();
        $this->kaynakVeritabani = new SahteKaynakVeritabani;
        $this->app->instance(KaynakVeritabani::class, $this->kaynakVeritabani);
    }

    protected function yedeklemeOrtaminiKaldir(): void
    {
        $this->tutulanKilit?->birak();
        $this->tutulanKilit = null;

        if (isset($this->yedekKoku) && is_dir($this->yedekKoku)) {
            File::deleteDirectory($this->yedekKoku);
        }
    }

    /** Durum klasörünün kilidi, alınmış hâlde */
    protected function kilitAl(): YedekKilidi
    {
        $kilit = app(YedeklemeYapilandirmasi::class)->islemDurumu()->kilit();
        $this->assertTrue($kilit->al());

        return $this->tutulanKilit = $kilit;
    }

    /**
     * Sahte PostgreSQL araçları. `pg_dump` --file ile verilen yola içerik
     * yazar; ek desenler başa eklenir (ilk eşleşen kazanır).
     *
     * @param  array<string, Closure|int|string>  $ozel
     */
    protected function sahtePgAraclari(array $ozel = []): void
    {
        // Birleşim (+): özel desen aynı anahtarlı varsayılanı ezer ve önde kalır
        Process::fake($ozel + [
            '*pg_dump*--version*' => 'pg_dump (PostgreSQL) 18.6',
            '*pg_restore*--version*' => 'pg_restore (PostgreSQL) 18.6',
            '*pg_dump*' => function (PendingProcess $surec): string {
                $this->dosyaArgumaninaYaz($surec, 'PGDMP-sahte-yedek-'.bin2hex(random_bytes(4)));

                return '';
            },
            '*pg_restore*--list*' => ";\n; Archive created\n215; 1259 16390 TABLE public migrations erp\n216; 1259 16391 TABLE public users erp\n",
            '*pg_restore*' => '',
            '*' => '',
        ]);
    }

    protected function dosyaArgumaninaYaz(PendingProcess $surec, string $icerik): void
    {
        foreach ((array) $surec->command as $arguman) {
            if (str_starts_with((string) $arguman, '--file=') && $arguman !== '--file=/dev/null') {
                file_put_contents(substr((string) $arguman, 7), $icerik);
            }
        }
    }

    /** @return list<string> Depo işareti hariç */
    protected function klasordekiDosyalar(): array
    {
        $klasor = config('yedekleme.klasor');

        return is_dir($klasor) ? array_values(array_diff(scandir($klasor) ?: [], ['.', '..', YedekDeposu::ISARET])) : [];
    }
}
