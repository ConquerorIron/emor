<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Yedek klasöründen dizini kurar / günceller (`yedek:tara`; README §5.2,
 * §7.3, §4.5; BY-011). Doğruluk kaynağı dosyalardır: imzalı manifest +
 * olay günlüğü.
 *
 * - Kayıtlar manifest KİMLİĞİYLE güncellenir, silinip yeniden açılmaz
 *   (kopyalar ve plan bağları CASCADE ile gitmesin, geri yükleme geçmişi
 *   RESTRICT ile engellemesin — Fable F19).
 * - İmzası tutmayan ya da manifestsiz `.dump` "güvenilmeyen" kayıttır
 *   (`imzali` false): listede görünür, geri yüklenemez.
 * - Diskte olmayan kayıt silinmez: `yerel_silinme_zamani` dolar; başka bir
 *   yerde kopyası yoksa durumu `silindi` olur.
 * - Yerel dosyası silinmiş ama manifesti duran yedek, olay günlüğündeki uzak
 *   kopyalarıyla bulunur (F7).
 * - Tek bir bozuk dosya taramayı durdurmaz.
 */
final class DizinKurucu
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly ManifestDeposu $manifestler,
        private readonly OlayGunlugu $olaylar,
    ) {}

    /**
     * @return array{guvenilir: int, guvenilmeyen: int, eklenen: int, dosyasi_yok: int, bozuk: int, gecersiz_olay: int, bulunamayan_hedef: int}
     */
    public function tara(): array
    {
        $klasor = $this->yapilandirma->klasor();
        $dosyalar = is_dir($klasor) ? @scandir($klasor) : [];

        if ($dosyalar === false) {
            throw new RuntimeException("Yedek klasörü okunamıyor: {$klasor}");
        }

        $sayac = ['guvenilir' => 0, 'guvenilmeyen' => 0, 'eklenen' => 0, 'dosyasi_yok' => 0, 'bozuk' => 0, 'gecersiz_olay' => 0, 'bulunamayan_hedef' => 0];
        $okunan = $this->olaylar->oku();
        $sayac['gecersiz_olay'] = $okunan['gecersiz'];
        $durumlar = $this->olaylar->durumlar();
        $gorulenAdlar = [];

        $manifestler = array_filter($dosyalar, fn (string $ad): bool => ! str_starts_with($ad, '.') && str_ends_with($ad, '.json'));
        $dumplar = array_filter($dosyalar, fn (string $ad): bool => ! str_starts_with($ad, '.') && str_ends_with($ad, '.dump'));
        $guvenilirDumplar = [];

        foreach ($manifestler as $jsonAdi) {
            try {
                $sonuc = $this->manifestler->oku($klasor.'/'.$jsonAdi);
                if (! $sonuc->guvenilirMi()) {
                    $sayac['bozuk']++;

                    continue;
                }

                $manifest = $sonuc->manifest;
                $yeni = $this->manifesttenKur($manifest, $durumlar[$manifest->kimlik] ?? GuncelDurum::hesapla([]), $klasor, $sayac);
                $sayac['guvenilir']++;
                $sayac['eklenen'] += $yeni ? 1 : 0;
                $gorulenAdlar[$manifest->dosyaAdi] = true;
                $guvenilirDumplar[$manifest->dosyaAdi] = true;
            } catch (Throwable $hata) {
                // Tek bozuk dosya taramayı durdurmaz
                $sayac['bozuk']++;
                report($hata);
            }
        }

        foreach ($dumplar as $dumpAdi) {
            if (isset($guvenilirDumplar[$dumpAdi])) {
                continue;
            }

            try {
                $sayac['eklenen'] += $this->guvenilmeyenKur($klasor, $dumpAdi) ? 1 : 0;
                $sayac['guvenilmeyen']++;
                $gorulenAdlar[$dumpAdi] = true;
            } catch (Throwable $hata) {
                $sayac['bozuk']++;
                report($hata);
            }
        }

        // Diskte olmayan kayıtlar: silinmez, "dosya yok" olur
        foreach (Yedek::query()->whereNotIn('durum', [YedekDurumu::Silindi->value, YedekDurumu::Calisiyor->value, YedekDurumu::Kuyrukta->value])->get() as $yedek) {
            if (isset($gorulenAdlar[$yedek->dosya_adi]) || is_file($klasor.'/'.$yedek->dosya_adi)) {
                continue;
            }

            $this->dosyasiYok($yedek);
            $sayac['dosyasi_yok']++;
        }

        return $sayac;
    }

    /**
     * Deterministik kimlik: aynı dosya her taramada aynı kayda düşer.
     */
    public static function dosyaKimligi(string $dosyaAdi): string
    {
        $h = md5('emor-yedek-guvenilmeyen:'.$dosyaAdi);

        return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12));
    }

    /**
     * @param  array<string, int>  $sayac
     * @return bool Yeni kayıt mı açıldı
     */
    private function manifesttenKur(YedekManifesti $m, GuncelDurum $durum, string $klasor, array &$sayac): bool
    {
        return DB::transaction(function () use ($m, $durum, $klasor, &$sayac): bool {
            $yedek = Yedek::query()->where('kimlik', $m->kimlik)->first() ?? new Yedek(['kimlik' => $m->kimlik]);
            $yeni = ! $yedek->exists;
            $dump = $klasor.'/'.$m->dosyaAdi;
            $dumpVar = is_file($dump) && @filesize($dump) === $m->boyut;

            $yedek->fill([
                'dosya_adi' => $m->dosyaAdi,
                'kaynak' => $m->kaynak,
                'boyut' => $m->boyut,
                'sha256' => $m->sha256,
                'alinma_zamani' => $m->alinmaZamani,
                'bitti' => $yedek->bitti ?? $m->alinmaZamani,
                'imzali' => true,
                'ice_alindi' => $m->iceAlindi,
                'sabit' => $durum->sabit,
                'aciklama' => $durum->aciklamaDegisti ? $durum->aciklama : $m->aciklama,
                'dogrulama_durumu' => $durum->dogrulama ?? DogrulamaDurumu::OzetTamam,
                'alan_kullanici_id' => $m->alanKullaniciId !== null && User::query()->whereKey($m->alanKullaniciId)->exists() ? $m->alanKullaniciId : null,
                'hata_kodu' => null,
                'hata_mesaji' => null,
                'yerel_silinme_zamani' => $dumpVar ? null : ($yedek->yerel_silinme_zamani ?? $durum->yerelSilinme ?? CarbonImmutable::now()),
            ]);
            $yedek->durum = YedekDurumu::Tamam;
            $yedek->silinme_zamani = null;
            $yedek->save();

            $uzakKopyaVar = false;
            foreach ($durum->kopyalar as $k) {
                $hedef = ($k['hedef_id'] !== null ? YedekHedefi::query()->find($k['hedef_id']) : null)
                    ?? ($k['hedef_ad'] !== null ? YedekHedefi::query()->where('ad', $k['hedef_ad'])->first() : null);

                if ($hedef === null) {
                    $sayac['bulunamayan_hedef']++;

                    continue;
                }

                $kopya = YedekKopyasi::query()->firstOrNew(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id]);
                $kopyaDurumu = KopyaDurumu::from($k['durum']);
                $kopya->fill([
                    'durum' => $kopyaDurumu,
                    'uzak_kimlik' => $k['uzak_kimlik'],
                    'sifreli' => $k['sifreli'],
                    'sifreli_sha256' => $k['sifreli_sha256'],
                    'deneme' => $kopya->deneme ?? 0,
                ])->save();

                // Bağlar yalnız planın bu hedefle gerçek ilişkisi varsa (bileşik anahtar; BY-004-R8)
                $gecerli = DB::table('yedek_plan_hedefleri')->where('yedek_hedefi_id', $hedef->id)->whereIn('yedek_plani_id', $k['plan_idleri'])->pluck('yedek_plani_id')->all();
                $kopya->planlar()->sync(array_fill_keys(array_map('intval', $gecerli), ['yedek_hedefi_id' => $hedef->id]));

                $uzakKopyaVar = $uzakKopyaVar || ($kopyaDurumu === KopyaDurumu::Tamam && ! $this->anaKlasorHedefiMi($hedef));
            }

            if ($durum->silinme !== null || (! $dumpVar && ! $uzakKopyaVar)) {
                $yedek->forceFill(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => $durum->silinme ?? CarbonImmutable::now()])->save();
            }

            return $yeni;
        });
    }

    /**
     * @return bool Yeni kayıt mı açıldı
     */
    private function guvenilmeyenKur(string $klasor, string $dumpAdi): bool
    {
        $yol = $klasor.'/'.$dumpAdi;
        $kimlik = self::dosyaKimligi($dumpAdi);
        $yedek = Yedek::query()->where('kimlik', $kimlik)->first()
            ?? Yedek::query()->where('dosya_adi', $dumpAdi)->first()
            ?? new Yedek(['kimlik' => $kimlik]);
        $yeni = ! $yedek->exists;
        $mtime = @filemtime($yol);

        $yedek->fill([
            'dosya_adi' => $dumpAdi,
            'kaynak' => $this->adindanKaynak($dumpAdi),
            'durum' => YedekDurumu::Tamam,
            'imzali' => false,
            'dogrulama_durumu' => DogrulamaDurumu::Dogrulanmadi,
            'boyut' => @filesize($yol) ?: null,
            'alinma_zamani' => $yedek->alinma_zamani ?? ($mtime === false ? null : CarbonImmutable::createFromTimestamp($mtime)),
            'yerel_silinme_zamani' => null,
        ])->save();

        return $yeni;
    }

    /** Yedek klasörünün kendisi olan yerel hedef: kopyası yerel dosyanın aynısıdır */
    private function anaKlasorHedefiMi(YedekHedefi $hedef): bool
    {
        $klasor = $hedef->baglanti['klasor'] ?? '';

        return $hedef->tur === HedefTuru::Yerel
            && (! is_string($klasor) || $klasor === '' || rtrim($klasor, '/') === $this->yapilandirma->klasor());
    }

    private function adindanKaynak(string $ad): YedekKaynagi
    {
        foreach (YedekKaynagi::cases() as $kaynak) {
            if (str_starts_with($ad, 'erp_'.$kaynak->value.'_')) {
                return $kaynak;
            }
        }

        // deploy.sh'ın eski yedekleri: erp_predeploy_*
        return str_contains($ad, '_predeploy_') ? YedekKaynagi::YayinOncesi : YedekKaynagi::Elle;
    }

    private function dosyasiYok(Yedek $yedek): void
    {
        $yedek->yerel_silinme_zamani ??= CarbonImmutable::now();

        $baskaKopya = YedekKopyasi::query()->with('hedef')->where('yedek_id', $yedek->id)->where('durum', KopyaDurumu::Tamam->value)->get()
            ->contains(fn (YedekKopyasi $k): bool => $k->hedef !== null && ! $this->anaKlasorHedefiMi($k->hedef));
        if (! $baskaKopya) {
            $yedek->durum = YedekDurumu::Silindi;
            $yedek->silinme_zamani ??= CarbonImmutable::now();
        }

        $yedek->save();
    }
}
