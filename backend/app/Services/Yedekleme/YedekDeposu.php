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
 * - Katalogda HİÇ yedek kaydı yoksa işaret açılır: ilk kurulum, geçiş kipi ya
 *   da tablolar boşaltılıp yeniden kurma; tarama yalnız ekler, kaybedilecek
 *   kayıt yoktur. Ölçüt durumdan bağımsızdır (BY-011-R5): ilk yedek dump'ı ve
 *   manifesti yayımlayıp `calisiyor`da kalmış olabilir; `basarisiz` kayıt da
 *   taşımadan sonraki bir hatada (klasör eşitleme) dosyasını bırakmış
 *   olabilir. "Bu kayıt klasöre hiç yazmadı" kanıtlanamadığı için her kayıt
 *   sayılır; yanlış ret güvenli yöndür (kurulum betiği işareti açar).
 *   YedekAlici bu denetimi kendi kaydını açmadan önce yapar.
 * - Katalogdaki bir kaydın İMZALI manifesti bu klasördeyse ve kimliği ile
 *   dosya adı kayıtla aynıysa klasör depodur (işaretten önce kurulmuş ya da
 *   işareti silinmiş depo): işaret açılır. Adı tutan dosya kanıt değildir.
 * - Aksi hâlde (kayıt var, hiçbirinin manifesti burada değil: boş bağlama
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

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly ManifestDeposu $manifestler,
    ) {}

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

        if (Yedek::query()->exists() && ! $this->katalogdakiBirYedekBurada($klasor)) {
            throw new DepoIsaretiYok("Yedek klasöründe depo işareti yok ve katalogdaki yedeklerin hiçbirinin imzalı manifesti bu klasörde değil ({$klasor}): yedek diski bağlı olmayabilir. Disk bağlıysa ve klasör doğruysa kurulum betiğini çalıştırın (sunucu-kurulumu.md). Bu yüzden dizin değiştirilmedi, klasöre yazılmadı.");
        }

        AtomikDosya::yaz($isaret, self::ON_EK.Str::uuid()->toString()."\n");
    }

    /** Somut kanıt: katalogdaki bir kaydın imzası geçerli, kimliği ve dosya adı kayıtla aynı manifesti burada (durumdan bağımsız) */
    private function katalogdakiBirYedekBurada(string $klasor): bool
    {
        foreach (Yedek::query()->select(['id', 'kimlik', 'dosya_adi'])->lazyById(200) as $yedek) {
            $ad = $yedek->dosya_adi;
            if (basename($ad) !== $ad) {
                continue;
            }

            $sonuc = $this->manifestler->oku(ManifestDeposu::jsonYolu($klasor.'/'.$ad));
            if ($sonuc->guvenilirMi() && $sonuc->manifest->kimlik === $yedek->kimlik && $sonuc->manifest->dosyaAdi === $ad) {
                return true;
            }
        }

        return false;
    }
}
