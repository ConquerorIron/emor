<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use Illuminate\Support\Str;

/**
 * Yedek klasörünün GERÇEK depo olduğunun işareti (BY-011-R3; D6). Yedek
 * klasörü ayrı bir diske bağlanmışsa disk ayrılınca bağlama noktası, alttaki
 * boş ve okunabilir klasör olarak kalır: `scandir` başarı döner, tarama
 * katalogdaki yedekleri "dosya yok" sayar, yedek alma da kök diske yazar.
 *
 * Depo kökünde tek satırlık işaret dosyası durur:
 * `.emor-yedek-deposu` → `emor-yedek-deposu <uuid>`. Dosya diskin kendisinde
 * olduğu için disk bağlı değilken görünmez. Kurulum betiği açar
 * (`scripts/sunucu-yedekleme-kurulumu.sh`, aynı biçim); var olanın üstüne
 * hiçbir yol yazmaz. Kimlik yalnız depoyu ayırt etmek içindir (işletimde
 * "bu disk hangi depo" sorusu); denetim biçimi ister.
 *
 * İşaret yoksa (hazirla) — katalog değişmeden ve dosya yazılmadan ÖNCE:
 *
 * - Katalogda tamamlanmış yedek yoksa işaret açılır: ilk kurulum, geçiş kipi
 *   ya da tablolar boşaltılıp yeniden kurma; tarama yalnız ekler, kaybedilecek
 *   kayıt yoktur.
 * - Katalogdaki tamamlanmış bir yedeğin manifesti ya da dump'ı klasördeyse
 *   klasör depodur (işaretten önce kurulmuş depo): işaret açılır.
 * - Aksi hâlde (katalogda yedek var, hiçbiri burada değil: boş bağlama
 *   noktası, yanlış klasör) hata; çağıran hiçbir şeye dokunmaz.
 *
 * Ana disk bağlanmadan önce kurulum çalıştırılırsa işaret bağlama noktasının
 * altındaki klasörde kalır (disk bağlıyken görünmez, ayrılınca görünür):
 * sunucu-kurulumu.md'deki sıra (önce diski bağla, sonra kurulum) buna karşıdır.
 */
final class YedekDeposu
{
    public const ISARET = '.emor-yedek-deposu';

    private const ON_EK = 'emor-yedek-deposu ';

    private const BICIM = '/^emor-yedek-deposu [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\n?\z/D';

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * Klasör bu sistemin deposu mu; yukarıdaki kurallarla gerekirse işareti açar.
     *
     * @throws DepoIsaretiYok Depo doğrulanamadı (hiçbir şey değişmedi)
     */
    public function hazirla(): void
    {
        $klasor = $this->yapilandirma->klasor();
        $isaret = $klasor.'/'.self::ISARET;

        if (is_link($isaret) || (file_exists($isaret) && ! is_file($isaret))) {
            throw new DepoIsaretiYok("Yedek deposunun işareti düz dosya değil ({$isaret}); elle inceleyin. Bu yüzden dizin değiştirilmedi, klasöre yazılmadı.");
        }

        if (is_file($isaret)) {
            $icerik = @file_get_contents($isaret, length: 200);
            if (! is_string($icerik) || preg_match(self::BICIM, $icerik) !== 1) {
                throw new DepoIsaretiYok("Yedek deposunun işareti okunamadı ya da biçimi tanınmıyor ({$isaret}); elle inceleyin. Bu yüzden dizin değiştirilmedi, klasöre yazılmadı.");
            }

            return;
        }

        if (! $this->katalogBosMu() && ! $this->katalogdakiBirYedekBurada($klasor)) {
            throw new DepoIsaretiYok("Yedek klasöründe depo işareti yok ve katalogdaki yedeklerin hiçbiri bu klasörde değil ({$klasor}): yedek diski bağlı olmayabilir. Disk bağlıysa ve klasör doğruysa kurulum betiğini çalıştırın (sunucu-kurulumu.md). Bu yüzden dizin değiştirilmedi, klasöre yazılmadı.");
        }

        AtomikDosya::yaz($isaret, self::ON_EK.Str::uuid()->toString()."\n");
    }

    private function katalogBosMu(): bool
    {
        return ! Yedek::query()->where('durum', YedekDurumu::Tamam->value)->exists();
    }

    private function katalogdakiBirYedekBurada(string $klasor): bool
    {
        foreach (Yedek::query()->where('durum', YedekDurumu::Tamam->value)->select(['id', 'dosya_adi'])->lazyById(200) as $yedek) {
            $ad = $yedek->dosya_adi;
            if (basename($ad) === $ad && (is_file($klasor.'/'.$ad) || is_file(ManifestDeposu::jsonYolu($klasor.'/'.$ad)))) {
                return true;
            }
        }

        return false;
    }
}
