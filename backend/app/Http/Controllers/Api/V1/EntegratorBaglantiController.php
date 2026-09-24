<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ayar\EntegratorBaglantiGuncelleRequest;
use App\Http\Resources\EntegratorBaglantiResource;
use App\Models\EntegratorBaglanti;
use App\Rules\EntegratorApiAdresi;
use App\Services\Entegrator\IzibizIstemcisi;
use App\Services\EntegratorBaglantiServisi;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ayarlar → Entegratör Bağlantıları: Test/Canlı İzibiz tanımları + aktif ortam.
 * Tüm uçlar `can:sistem-yonetimi` ile korunur (routes/api.php); sınama ayrıca
 * `throttle:entegrator-sina` ile sınırlıdır.
 */
final class EntegratorBaglantiController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $servis,
    ) {}

    /**
     * Entegratörün ve SQL'in aktif ortamı birlikte döner: seçimler bağımsızdır
     * (EFAT-15, S5), uyuşmazlık ekranda uyarı olarak gösterilir.
     */
    public function index(MssqlBaglantiServisi $sql): JsonResponse
    {
        $tanimlar = $this->servis->listele();
        $aktifOrtam = $this->servis->aktif()?->ortam;
        $sqlAktifOrtam = $sql->aktif()?->ortam;

        return response()->json([
            'data' => [
                'test' => isset($tanimlar[EntegratorBaglanti::ORTAM_TEST])
                    ? new EntegratorBaglantiResource($tanimlar[EntegratorBaglanti::ORTAM_TEST])
                    : null,
                'canli' => isset($tanimlar[EntegratorBaglanti::ORTAM_CANLI])
                    ? new EntegratorBaglantiResource($tanimlar[EntegratorBaglanti::ORTAM_CANLI])
                    : null,
                'aktif_ortam' => $aktifOrtam,
                'sql_aktif_ortam' => $sqlAktifOrtam,
                'ortam_uyumsuz' => $aktifOrtam !== null && $sqlAktifOrtam !== null && $aktifOrtam !== $sqlAktifOrtam,
                // Adres alanı boş bırakılırsa kullanılacak adresler (formun ipucu)
                'varsayilan_api_url' => [
                    EntegratorBaglanti::ORTAM_TEST => (string) config('entegrator.izibiz.ortamlar.test.api_url'),
                    EntegratorBaglanti::ORTAM_CANLI => (string) config('entegrator.izibiz.ortamlar.canli.api_url'),
                ],
            ],
        ]);
    }

    public function guncelle(EntegratorBaglantiGuncelleRequest $request, string $ortam): JsonResponse
    {
        /** @var array{kullanici_adi: string, sifre?: string|null, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null, api_url?: string|null} $veri */
        $veri = $request->validated();

        $tanim = $this->servis->guncelle($ortam, $veri);

        // Değişiklik kaydı — şifrenin kendisi değil, yalnız değişip değişmediği yazılır
        Log::info('Entegratör bağlantı tanımı güncellendi', [
            'saglayici' => $tanim->saglayici,
            'ortam' => $ortam,
            'kullanici_id' => $request->user()?->id,
            'sifre_degisti' => ($veri['sifre'] ?? '') !== '',
            'api_url' => $tanim->apiUrl(),
        ]);

        return (new EntegratorBaglantiResource($tanim))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Bağlantıyı sına: formdaki (henüz kaydedilmemiş) kullanıcı/şifreyle ya da
     * kayıtlı tanımla token alınır. Önbellek kullanılmaz ve kayıtlı tanım
     * değişmez. Boş şifre yalnız kullanıcı adı VE adres kayıtlıyla aynıysa
     * kayıtlı şifreye düşer (kayıtlı şifre formdaki yeni adrese gitmez).
     *
     * Başarı yalnız kimlik doğrulamanın geçtiğini söyler; fatura okuma
     * yetkisi listeleme sırasında (EFAT-07) ayrıca sınanır.
     */
    public function sina(Request $request, string $ortam, IzibizIstemcisi $istemci): JsonResponse
    {
        /** @var array{kullanici_adi?: string|null, sifre?: string|null, api_url?: string|null} $veri */
        $veri = $request->validate([
            'kullanici_adi' => ['nullable', 'string', 'max:128'],
            'sifre' => ['nullable', 'string', 'max:255'],
            'api_url' => ['nullable', 'string', 'max:255', new EntegratorApiAdresi],
        ]);

        $kayitli = $this->servis->tanim($ortam);
        $kullaniciAdi = $veri['kullanici_adi'] ?? $kayitli?->kullanici_adi ?? '';
        $sifre = $veri['sifre'] ?? '';

        // Kaydedilmeyen geçici tanım: kayıtlı modele dokunulmaz
        $tanim = new EntegratorBaglanti([
            'saglayici' => EntegratorBaglanti::SAGLAYICI_IZIBIZ,
            'ortam' => $ortam,
            'api_url' => array_key_exists('api_url', $veri)
                ? EntegratorBaglantiServisi::saklanacakAdres($veri['api_url'])
                : $kayitli?->api_url,
            'kullanici_adi' => $kullaniciAdi,
        ]);

        if ($kullaniciAdi === '') {
            throw ValidationException::withMessages([
                'kullanici_adi' => __('hata.entegrator_kullanici_zorunlu'),
            ]);
        }

        if ($sifre === '') {
            if ($kayitli === null) {
                throw ValidationException::withMessages([
                    'sifre' => __('hata.entegrator_sifre_zorunlu'),
                ]);
            }

            if ($kayitli->kullanici_adi !== $kullaniciAdi) {
                throw ValidationException::withMessages([
                    'sifre' => __('hata.entegrator_sifre_kullanici_degisti'),
                ]);
            }

            if ($tanim->apiUrl() !== $kayitli->apiUrl()) {
                throw ValidationException::withMessages([
                    'sifre' => __('hata.entegrator_sifre_adres_degisti'),
                ]);
            }

            $sifre = $kayitli->sifre;
        }

        $tanim->sifre = $sifre;

        $token = $istemci->tokenAl($tanim);

        return response()->json([
            'data' => [
                'musteri_tipi' => $token->musteriTipi,
                'gecerlilik_bitis' => $token->bitis->toIso8601String(),
            ],
        ]);
    }

    public function aktifYap(Request $request): JsonResponse
    {
        /** @var array{ortam: string} $veri */
        $veri = $request->validate([
            'ortam' => ['required', Rule::in(EntegratorBaglanti::ORTAMLAR)],
        ]);

        $tanim = $this->servis->aktifYap($veri['ortam']);

        Log::info('Aktif entegratör ortamı değiştirildi', [
            'saglayici' => $tanim->saglayici,
            'ortam' => $tanim->ortam,
            'kullanici_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => new EntegratorBaglantiResource($tanim)]);
    }
}
