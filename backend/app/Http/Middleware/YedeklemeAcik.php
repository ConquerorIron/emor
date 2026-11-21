<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yedekleme uçları modül kapalıyken (YEDEKLEME_AKTIF) çalışmaz: 409
 * YEDEKLEME_KAPALI. Menü de aynı bayrakla gizlenir (`UserResource`);
 * kod sunucuya gitse de modül açılana kadar etkisizdir.
 */
final class YedeklemeAcik
{
    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @param  Closure(Request): Response  $sonraki
     */
    public function handle(Request $request, Closure $sonraki): Response
    {
        if (! $this->yapilandirma->aktif()) {
            return response()->json(['kod' => 'YEDEKLEME_KAPALI', 'mesaj' => __('hata.yedekleme_kapali')], 409);
        }

        return $sonraki($request);
    }
}
