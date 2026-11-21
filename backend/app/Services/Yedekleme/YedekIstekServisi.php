<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ekranın uzun işlemleri: istek klasörüne bırakılır, işi çalıştırıcı yapar
 * (README §5.2 "işlem web isteğinde çalışmaz"; BY-014). İşin sonucunu
 * çalıştırıcı ayrıca yazar. Oturumlu ekran istekleri (birak) ve üstüne geri
 * yüklemenin oturumsuz kararları (kararBirak) aynı kabul sözleşmesinden
 * geçer (yayinla; BY-030-R3):
 *
 * - Kayıtsız istek olmaz (BY-014-R1): kimlik önce belirlenir, denetim kaydı
 *   yazılır, istek ANCAK ondan sonra yayınlanır. Denetim kaydı yazılamazsa
 *   istek hiç yayınlanmaz ve hata istemciye döner.
 * - Yayımlanmış istek kabul edilmiştir (BY-014-R2): istek dosyası çalıştırıcıya
 *   göründükten sonraki hata (klasör eşitlemesi — KalicilikDogrulanamadi)
 *   istemciye başarısızlık olarak dönmez; aynı kimlikle belirsizlik notu
 *   (`ISTEK_KALICILIGI_DOGRULANAMADI`) eklenir. Taşımadan önceki hata
 *   "yayınlanmadı"dır: `basarisiz` izleme kaydı (`ISTEK_YAYINLANAMADI`) ve hata.
 *   Denetim kaydı salt eklemelidir (K3): önceki satır değiştirilmez.
 * - Kimlik yeniden sorgulanabilir: istemci anahtarı (IstekAnahtari) verilirse
 *   kimlik ondan ve eylemden (tür, kullanıcı, parametreler) türetilir. Aynı
 *   eylemin yeniden denemesi aynı kimliğe düşer; o kimlikle istek varsa ikinci
 *   dosya yazılmaz, mevcut kabul döner (IstekKutusu::yoksaYayinla). Başka eylem
 *   ya da başka kullanıcı aynı anahtarla başka kimlik alır.
 */
final class YedekIstekServisi
{
    public function __construct(
        private readonly IstekKutusu $kutu,
        private readonly YedekDenetimi $denetim,
    ) {}

    /**
     * @param  array<string, mixed>  $parametreler
     * @param  string|null  $istemciAnahtari  Aynı eylemin yeniden denemesinde aynı (IstekAnahtari::oku)
     * @return string Yoklanacak istek kimliği (yeni ya da daha önce kabul edilmiş)
     *
     * @throws Throwable Denetim kaydı ya da istek yazılamadı (istek yayınlanmadı)
     */
    public function birak(string $tur, array $parametreler, int $kullaniciId, ?int $yedekId = null, ?string $istemciAnahtari = null): string
    {
        $kimlik = $this->kimlik($istemciAnahtari, $tur, $kullaniciId, $parametreler);
        $ayrinti = ['tur' => $tur, 'istek' => $kimlik];

        return $this->yayinla($kimlik, $istemciAnahtari !== null, $tur, fn (): array => $parametreler, $kullaniciId,
            function (IslemSonucu $sonuc, array $ek, ?string $hata) use ($yedekId, $kullaniciId, $ayrinti): void {
                $this->denetim->kaydet('istek', $sonuc, $yedekId, $kullaniciId, null, [...$ayrinti, ...$ek], $hata);
            });
    }

    /**
     * Üstüne geri yüklemenin onay / geri dönüş kararı (oturumsuz uç; BY-030).
     * Denetim kaydı yalnız günlüğe: veritabanı yer değiştirmiş ya da
     * erişilemez olabilir (çalıştırıcı işlerken ayrıca kaydeder). Kimliği
     * kararın kendisi (işlem, karar, ayar seçimleri) belirler; kısa ömürlü
     * kanıt her denemede yenidir, kimliğe girmez.
     *
     * @param  'onayla'|'geri_don'  $tur
     * @param  Closure(): array<string, mixed>  $parametreler  Yalnız yayınlanacaksa çağrılır (kanıt yayın anında üretilir)
     * @return string Kararın istek kimliği (yeni ya da daha önce kabul edilmiş)
     *
     * @throws Throwable Günlük ya da istek yazılamadı (istek yayınlanmadı)
     */
    public function kararBirak(string $tur, string $islem, string $ayarSecimleri, Closure $parametreler, ?int $kullaniciId, ?string $istemciAnahtari): string
    {
        $kimlik = $this->kimlik($istemciAnahtari, $tur, $kullaniciId, ['islem_kimligi' => $islem, 'ayar_secimleri' => $ayarSecimleri]);
        $ayrinti = ['tur' => $tur, 'istek' => $kimlik, 'islem' => $islem];

        return $this->yayinla($kimlik, $istemciAnahtari !== null, $tur, $parametreler, $kullaniciId,
            function (IslemSonucu $sonuc, array $ek, ?string $hata) use ($kullaniciId, $ayrinti): void {
                $this->denetim->gunlugeYaz('istek', $sonuc, null, $kullaniciId, null, [...$ayrinti, ...$ek], $hata);
            });
    }

    /**
     * @param  Closure(): array<string, mixed>  $parametreler
     * @param  Closure(IslemSonucu, array<string, string>, string|null): void  $denetle
     */
    private function yayinla(string $kimlik, bool $tekrarOlabilir, string $tur, Closure $parametreler, ?int $kullaniciId, Closure $denetle): string
    {
        $yayin = function () use ($kimlik, $tur, $parametreler, $kullaniciId, $denetle): void {
            $denetle(IslemSonucu::Basarili, [], null);

            try {
                $this->kutu->yaz($tur, $parametreler(), $kullaniciId, $kimlik);
            } catch (KalicilikDogrulanamadi $hata) {
                // Yayımlandı: çalıştırıcı işleyebilir, istemci kabul görür
                $this->enIyiCaba(function () use ($hata, $denetle): void {
                    report($hata);
                    $denetle(IslemSonucu::Basarili, ['kod' => 'ISTEK_KALICILIGI_DOGRULANAMADI'], $hata::class);
                });
            } catch (Throwable $hata) {
                $this->enIyiCaba(fn () => $denetle(IslemSonucu::Basarisiz, ['kod' => 'ISTEK_YAYINLANAMADI'], $hata::class));

                throw $hata;
            }
        };

        // Rastgele kimlik yenidir: tekrar denetimi (ve kabul kilidi) yalnız istemci anahtarıyla
        if ($tekrarOlabilir) {
            $this->kutu->yoksaYayinla($kimlik, $yayin);
        } else {
            $yayin();
        }

        return $kimlik;
    }

    /**
     * İstemci anahtarı yoksa rastgele; varsa eylemden türetilmiş, UUID
     * biçiminde (sürüm 8: uygulamaya özgü) kimlik. APP_KEY'li HMAC: kimlik
     * dışarıdan başka kullanıcının eylemine denk getirilemez.
     *
     * @param  array<string, mixed>  $eylem
     */
    private function kimlik(?string $istemciAnahtari, string $tur, ?int $kullaniciId, array $eylem): string
    {
        if ($istemciAnahtari === null) {
            return (string) Str::uuid();
        }

        $h = hash_hmac('sha256', json_encode([$tur, $kullaniciId, $eylem, $istemciAnahtari], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) config('app.key'));

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-8'.substr($h, 13, 3).'-'
            .dechex(8 | (hexdec($h[16]) & 3)).substr($h, 17, 3).'-'.substr($h, 20, 12);
    }

    private function enIyiCaba(Closure $is): void
    {
        try {
            $is();
        } catch (Throwable) {
            // En iyi çaba: asıl sonuç (kabul ya da hata) istemciye döner
        }
    }
}
