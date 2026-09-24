<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ayar\MailAyarGuncelleRequest;
use App\Http\Resources\MailAyarResource;
use App\Mail\TestMaili;
use App\Services\MailAyarServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Ayarlar → Mail (SMTP): uygulamanın giden mail tanımı ve test maili.
 * Uçlar `can:sistem-yonetimi` ile korunur (routes/api.php).
 */
final class MailAyarController extends Controller
{
    public function __construct(
        private readonly MailAyarServisi $servis,
    ) {}

    public function goster(): JsonResponse
    {
        $ayar = $this->servis->ayar();

        return response()->json(['data' => $ayar === null ? null : new MailAyarResource($ayar)]);
    }

    public function guncelle(MailAyarGuncelleRequest $request): JsonResponse
    {
        /** @var array{sunucu: string, port: int, sifreleme: string, kullanici_adi?: string|null, sifre?: string|null, gonderen_adres: string, gonderen_ad: string, yonlendirme_adresi?: string|null} $veri */
        $veri = $request->validated();

        $ayar = $this->servis->guncelle($veri);

        Log::info('Mail (SMTP) ayarı güncellendi', [
            'kullanici_id' => $request->user()?->id,
            'sunucu' => $ayar->sunucu,
            'sifre_degisti' => ($veri['sifre'] ?? '') !== '',
        ]);

        return response()->json(['data' => new MailAyarResource($ayar)]);
    }

    /**
     * Kayıtlı tanımla senkron test maili gönderir; SMTP'nin kabul edip
     * etmediği hemen görülür (kabul, alıcı kutusuna teslim garantisi değildir).
     */
    public function testGonder(Request $request): JsonResponse
    {
        /** @var array{alici: string} $veri */
        $veri = $request->validate([
            'alici' => ['required', 'email', 'max:255'],
        ]);

        try {
            $this->servis->gonder([$veri['alici']], new TestMaili((string) $request->user()?->ad));
        } catch (TransportExceptionInterface $hata) {
            // Sunucu yanıtı (ör. "535 Authentication unsuccessful") gösterilir;
            // şifre bu mesajlarda yer almaz
            throw ValidationException::withMessages([
                'alici' => __('hata.mail_gonderilemedi', ['detay' => Str::limit($hata->getMessage(), 400)]),
            ]);
        }

        return response()->json(['data' => ['gonderildi' => true]]);
    }
}
