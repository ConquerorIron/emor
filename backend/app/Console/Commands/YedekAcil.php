<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\AyarSecimi;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\OnayBilgisi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYuklemeGorunumu;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Üstüne geri yüklemenin acil durum yolu (README §6.4, §7.2; BY-029):
 * ekran açılamıyorsa, erişim anahtarı kaybolduysa ya da süresi dolduysa.
 * Web'e ve uygulamanın veritabanına dayanmaz: yalnız işlem durumu dosyası,
 * bakım modu dosyası ve PostgreSQL araçları (veritabanına yazılanlar en iyi
 * çabadır). Sunucuda servis hesabıyla çalıştırılır:
 *
 *   sudo -u emor-yedek php artisan yedek:acil durum
 *
 * Durumu değiştirmeden önce yedekleme kilidini alır (tek yazar — Fable F16).
 * Kilidi başka süreç (çalıştırıcı) tutuyorsa BEKLEMEZ, anlaşılır mesajla durur.
 * Modül ayarından (YEDEKLEME_AKTIF) bağımsızdır.
 */
#[Signature('yedek:acil
    {islem : durum | onayla | geri-don}
    {--ayar=* : Onayda farkı olan her ayar grubu için seçim: grup=guncel|geri_yuklenen (ör. --ayar=mail=guncel)}
    {--force : Onay sorusunu sorma}')]
#[Description('Üstüne geri yüklemenin acil durum yolu: durum, onay, geri dönüş (web ve veritabanı erişilemezken de)')]
final class YedekAcil extends Command
{
    public function handle(YedeklemeYapilandirmasi $yapilandirma, UstuneGeriYukleyici $ustune, OnayBilgisi $onayBilgisi): int
    {
        $islem = (string) $this->argument('islem');
        if (! in_array($islem, ['durum', 'onayla', 'geri-don'], true)) {
            $this->error('İşlem durum, onayla ya da geri-don olmalı.');

            return self::INVALID;
        }

        $durum = $yapilandirma->islemDurumu();

        try {
            $kayit = $durum->oku();
        } catch (IslemDurumuBozuk $hata) {
            $this->error($hata->getMessage());

            return self::FAILURE;
        }

        if ($kayit === null || $kayit->tur !== IslemTuru::GeriYuklemeUstune) {
            $this->info('Üstüne geri yükleme işlemi yok.');

            return $islem === 'durum' ? self::SUCCESS : self::FAILURE;
        }

        $this->goster(UstuneGeriYuklemeGorunumu::diziye($kayit, $onayBilgisi->bilgiOku($kayit->kimlik)));
        if ($islem === 'durum') {
            return self::SUCCESS;
        }

        // Ayar seçimleri (BY-028-R2): varsayılan yok; farkı olan grupta seçim eksikse onay reddedilir
        $secimler = [];
        foreach ((array) $this->option('ayar') as $secenek) {
            [$grup, $secim] = array_pad(explode('=', (string) $secenek, 2), 2, '');
            $secimler[$grup] = $secim;
        }
        if (! AyarSecimi::gecerliMi($secimler)) {
            $this->error('--ayar yalnız grup=guncel|geri_yuklenen olabilir; gruplar: '.implode(', ', AyarKopyasi::GRUPLAR).'.');

            return self::INVALID;
        }

        $soru = $islem === 'onayla'
            ? 'Geri yüklenen veritabanı onaylanacak ve uygulama AÇILACAK. Devam edilsin mi?'
            : 'Eski veritabanına geri dönülecek (geri yüklenen kopya silinmez) ve uygulama açılacak. Devam edilsin mi?';
        if (! $this->option('force') && ! $this->confirm($soru)) {
            $this->warn('Vazgeçildi; hiçbir şey değişmedi.');

            return self::FAILURE;
        }

        $kilit = $durum->kilit();
        if (! $kilit->al()) {
            $this->error('Yedekleme kilidi başka bir süreçte (çalıştırıcı ya da başka bir işlem). Beklenmedi; hiçbir şey değişmedi. Çalıştırıcı bir işi yürütüyorsa bitmesini bekleyin; takılı kaldıysa servisi durdurup (sudo systemctl stop emor-yedek) yeniden deneyin.');

            return self::FAILURE;
        }

        try {
            $sonuc = $islem === 'onayla'
                ? $ustune->onayla($kilit, $kayit->kimlik, $secimler)
                : $ustune->geriDon($kilit, $kayit->kimlik);
        } catch (GeriYuklemeHatasi $hata) {
            $this->error("{$hata->kod}: {$hata->getMessage()}");

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        $this->goster(UstuneGeriYuklemeGorunumu::diziye($sonuc, $onayBilgisi->bilgiOku($sonuc->kimlik)));
        // Geri dönüş, yer değiştirme yapılmamışsa geri sarmadır (basarisiz = canlı yerinde)
        $beklenen = $islem === 'onayla'
            ? [UstuneGeriYuklemeAdimi::Onaylandi]
            : [UstuneGeriYuklemeAdimi::GeriDonuldu, UstuneGeriYuklemeAdimi::Basarisiz];

        return in_array(UstuneGeriYuklemeAdimi::tryFrom($sonuc->adim), $beklenen, true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $gorunum  UstuneGeriYuklemeGorunumu::diziye()
     */
    private function goster(array $gorunum): void
    {
        $this->table(['Alan', 'Değer'], [
            ['İşlem', $gorunum['islem_kimligi']],
            ['Durum', $gorunum['durum']],
            ['Adım', $gorunum['adim']],
            ['Açık (uygulama kapalı tutuluyor)', $gorunum['acik'] ? 'evet' : 'hayır'],
            ['Yer değiştirildi', $gorunum['yer_degistirildi'] ? 'evet' : 'hayır'],
            ['Geri yüklenen veritabanı adı', (string) $gorunum['hedef_veritabani']],
            ['Önceki veritabanı adı', (string) $gorunum['onceki_veritabani']],
            ['Hata', $gorunum['hata'] === null ? '-' : $gorunum['hata']['kod'].': '.$gorunum['hata']['mesaj']],
            ['Yapılabilir', implode(', ', array_keys(array_filter($gorunum['yapilabilir']))) ?: '-'],
        ]);

        $bilgi = $gorunum['onay_bilgisi'];
        if (is_array($bilgi)) {
            $this->line('Aktif ERP ortamı: '.($bilgi['aktif_erp_ortami']['onceki'] ?? '?').' → '.($bilgi['aktif_erp_ortami']['geri_yuklenen'] ?? '-'));
            $this->line('Kaybolacak kesinti yüklemesi: '.($bilgi['kaybolacak_yukleme_sayisi'] ?? '?'));
            $this->line('Ayar farkı: '.count($bilgi['ayar_farklari']).', kullanıcı farkı: '.count($bilgi['kullanici_farklari']).', rol farkı: '.count($bilgi['rol_farklari']));
            $farkli = array_column(array_filter((array) ($bilgi['ayar_gruplari'] ?? []), fn (mixed $g): bool => is_array($g) && ($g['farkli'] ?? false) === true), 'grup');
            if ($farkli !== []) {
                $this->line('Onayda seçim isteyen ayar grupları (--ayar=grup=guncel|geri_yuklenen): '.implode(', ', $farkli));
            }
        }
    }
}
