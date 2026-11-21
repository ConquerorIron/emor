<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * İstemcinin bir eylem için ürettiği, yeniden denemede AYNEN gönderdiği
 * anahtar (`Idempotency-Key` başlığı; BY-014-R2). Yedekleme isteğinin
 * kimliği bundan türetilir (YedekIstekServisi): aynı eylemin yeniden
 * denemesi ikinci bir iş bırakmaz, mevcut kabulü alır. Başlık yoksa her
 * istek yenidir (eski istemciler, komut satırı).
 */
final class IstekAnahtari
{
    public const BASLIK = 'Idempotency-Key';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /**
     * @throws ValidationException Başlık var ama UUID değil (422)
     */
    public static function oku(Request $request): ?string
    {
        $deger = $request->header(self::BASLIK);

        if ($deger === null) {
            return null;
        }

        if (! is_string($deger) || preg_match(self::UUID, $deger) !== 1) {
            throw ValidationException::withMessages(['istek_anahtari' => __('hata.istek_anahtari_gecersiz')]);
        }

        return strtolower($deger);
    }
}
