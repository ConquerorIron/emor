<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Tests\TestCase;

/**
 * BY-016-R2: "Bağlantıyı Sına" tek toplam süre bütçesiyle çalışır (D9);
 * süre monoton saatle ölçülür (D1) — testte sahte saat.
 */
final class HedefSinayiciTest extends TestCase
{
    public function test_yavas_adimlar_toplam_butceyi_asmaz_bitmeyen_adim_sure_doldu_olur_temizlik_yine_denenir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 900, 'yedekleme.sure_siniri.baglanti' => 10]);
        $saat = new class
        {
            public int $ns = 1_000_000_000;
        };

        // Her çağrı kendisine verilen sınırın en kötü hâlini tüketir: 3 deneme × sınır
        $baglayici = new class($saat) implements HedefBaglayicisi
        {
            /** @var array<string, array{kopya: int, baglanti: int, kalan: float}> */
            public array $gorulen = [];

            /** @var array<string, string> */
            public array $dosyalar = [];

            public ?SureButcesi $butce = null;

            public function __construct(private readonly object $saat) {}

            private function yavas(string $adim): void
            {
                $yapilandirma = app(YedeklemeYapilandirmasi::class);
                $kopya = $yapilandirma->sureSiniri('kopya');
                $this->gorulen[$adim] = ['kopya' => $kopya, 'baglanti' => $yapilandirma->sureSiniri('baglanti'), 'kalan' => $this->butce?->kalanSaniye() ?? 0.0];
                $this->saat->ns += 3 * max($kopya, $yapilandirma->sureSiniri('baglanti')) * 1_000_000_000;
            }

            public function baglan(): void
            {
                $this->yavas('baglan');
            }

            public function yaz(string $yerelYol, string $ad): string
            {
                $this->yavas('yaz');
                $this->dosyalar[$ad] = (string) file_get_contents($yerelYol);

                return $ad;
            }

            public function oku(string $uzakKimlik, string $yerelYol): void
            {
                $this->yavas('oku');
                file_put_contents($yerelYol, $this->dosyalar[$uzakKimlik]);
            }

            public function sil(string $uzakKimlik): void
            {
                $this->yavas('sil');
                unset($this->dosyalar[$uzakKimlik]);
            }

            public function listele(): array
            {
                return array_keys($this->dosyalar);
            }

            public function kalanAlan(): ?int
            {
                $this->yavas('kalanAlan');

                return 1;
            }
        };
        $baslangic = $saat->ns;
        $butce = new SureButcesi(HedefSinayici::TOPLAM_SURE, fn (): int => $saat->ns);
        $baglayici->butce = $butce;

        $sonuc = app(HedefSinayici::class)->sonuc($baglayici, $butce);

        // Toplam süre bütçeyi aşmadı; sonuç döndü
        $this->assertLessThanOrEqual(HedefSinayici::TOPLAM_SURE * 1_000_000_000, $saat->ns - $baslangic);
        $adimlar = collect($sonuc['adimlar'])->keyBy('adim');
        $this->assertSame(['baglan' => true, 'yaz' => true, 'oku' => false, 'dogrula' => null, 'sil' => true], $adimlar->map(fn (array $a) => $a['basarili'])->all());
        $this->assertSame(HedefSinayici::SURE_DOLDU, $adimlar['oku']['kod']);
        $this->assertFalse($sonuc['basarili']);
        // Sınama dosyası silindi; her adımın sınırı kalan sürenin üçte birini geçmedi
        $this->assertSame([], $baglayici->dosyalar);
        foreach ($baglayici->gorulen as $adim => $gorulen) {
            $this->assertLessThanOrEqual(max(1, $gorulen['kalan'] / 3), max($gorulen['kopya'], $gorulen['baglanti']), $adim);
        }
        $this->assertArrayNotHasKey('oku', $baglayici->gorulen);
        // Bağlayıcının süre ayarları sınamadan sonra eski değerinde
        $this->assertSame([900, 10], [config('yedekleme.sure_siniri.kopya'), config('yedekleme.sure_siniri.baglanti')]);
    }

    public function test_hizli_hedefte_butce_kalan_alani_da_kapsar(): void
    {
        $sonuc = app(HedefSinayici::class)->sonuc(new class implements HedefBaglayicisi
        {
            /** @var array<string, string> */
            private array $dosyalar = [];

            public function baglan(): void {}

            public function yaz(string $yerelYol, string $ad): string
            {
                $this->dosyalar[$ad] = (string) file_get_contents($yerelYol);

                return $ad;
            }

            public function oku(string $uzakKimlik, string $yerelYol): void
            {
                file_put_contents($yerelYol, $this->dosyalar[$uzakKimlik]);
            }

            public function sil(string $uzakKimlik): void
            {
                unset($this->dosyalar[$uzakKimlik]);
            }

            public function listele(): array
            {
                return [];
            }

            public function kalanAlan(): ?int
            {
                return 123_456;
            }
        });

        $this->assertSame([true, 123_456, null], [$sonuc['basarili'], $sonuc['kalan_alan'], $sonuc['silinme_korumasi']]);
    }
}
