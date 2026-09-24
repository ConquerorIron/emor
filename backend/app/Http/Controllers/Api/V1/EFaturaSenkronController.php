<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaSenkronRequest;
use App\Jobs\EFaturaManuelSenkron;
use App\Models\EFaturaSenkronCalismasi;
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EmorIslenmeServisi;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\EntegratorBaglantiServisi;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * e-Fatura verisinin güncelliği ve elle senkron (EFAT-11).
 */
final class EFaturaSenkronController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaDurumServisi $durum,
    ) {}

    /**
     * "ERP Senkronla": eMOR kolonunu hemen tazeler (5 dakikalık efatura:emor
     * komutunun elle hali). ERP'den yalnız SELECT yapılır; okunamazsa
     * bayraklar değişmez. Aktif ERP ortamı yoksa 422 (MssqlBaglantiServisi).
     */
    public function erpEslestir(Request $request, EmorIslenmeServisi $emor): JsonResponse
    {
        try {
            $sonuc = $emor->tazele();
        } catch (ValidationException $hata) {
            throw $hata;
        } catch (Throwable $hata) {
            Log::warning('eMOR elle tazelenemedi: ERP okunamadı', ['hata' => $hata->getMessage()]);

            return response()->json(['kod' => 'ERP_OKUNAMADI', 'mesaj' => __('hata.erp_okunamadi')], 502);
        }

        Log::info('eMOR elle tazelendi', ['kullanici_id' => $request->user()?->id, ...$sonuc]);

        return response()->json(['data' => $sonuc]);
    }

    /**
     * Aktif ortam, yön başına veri zamanı/güncellik, süren ve son çalışma.
     * Hata mesajları dönmez (iç ayrıntı); yalnız makine okunur kod.
     */
    public function durum(): JsonResponse
    {
        $tanim = $this->baglantilar->aktif();

        if ($tanim === null) {
            return response()->json(['data' => [
                'ortam' => null,
                'senkron_aktif' => (bool) config('entegrator.izibiz.senkron_aktif'),
                'manuel_istek' => null,
                'yonler' => null,
            ]]);
        }

        $yonler = [];
        foreach (FaturaYonu::cases() as $yon) {
            $d = $this->durum->yonDurumu($tanim, $yon);
            $yonler[$yon->value] = [
                'veri_zamani' => $d['veri_zamani']?->toIso8601String(),
                'guncel' => $d['guncel'],
                'calisiyor' => $d['calisiyor'],
                'ardisik_hata' => $d['ardisik_hata'],
                'son_calisma' => $this->calisma($d['son_calisma']),
            ];
        }

        $istek = $this->durum->manuelIstek($tanim);

        return response()->json(['data' => [
            'ortam' => $tanim->ortam,
            'senkron_aktif' => (bool) config('entegrator.izibiz.senkron_aktif'),
            'manuel_istek' => $istek === null ? null : [
                'zaman' => $istek['zaman'],
                'baslangic' => $istek['baslangic'],
                'bitis' => $istek['bitis'],
            ],
            'yonler' => $yonler,
        ]]);
    }

    public function baslat(EFaturaSenkronRequest $request): JsonResponse
    {
        if (! config('entegrator.izibiz.senkron_aktif')) {
            return response()->json(['kod' => 'EFATURA_SENKRON_KAPALI', 'mesaj' => __('hata.efatura_senkron_kapali')], 409);
        }

        $tanim = $this->baglantilar->aktif() ?? throw EntegratorHatasi::aktifYok();
        $kullaniciId = $request->user()?->id;
        $baslangic = (string) $request->validated('baslangic');
        $bitis = (string) $request->validated('bitis');

        $istek = [
            // İş yalnız KENDİ isteğinin bayrağını kaldırır (gecikmiş eski iş yenisini silmesin)
            'istek_id' => (string) Str::uuid(),
            'zaman' => CarbonImmutable::now()->toIso8601String(),
            'kullanici_id' => $kullaniciId,
            'baslangic' => $baslangic,
            'bitis' => $bitis,
        ];

        // Atomik: aynı tanım için ikinci istek kuyruğa girmez. Süre, işin
        // çökmesi hâlinde bayrağın kendiliğinden düşmesi içindir.
        if (! Cache::add(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id), $istek, now()->addMinutes(30))) {
            return response()->json(['kod' => 'EFATURA_SENKRON_SURUYOR', 'mesaj' => __('hata.efatura_senkron_suruyor')], 409);
        }

        try {
            EFaturaManuelSenkron::dispatch($tanim->id, $baslangic, $bitis, $kullaniciId, $istek['istek_id']);
        } catch (Throwable $hata) {
            // Kuyruğa girmeyen istek bayrağı 30 dk tutup yeni isteği engellemesin
            EFaturaDurumServisi::manuelIstegiBitir($tanim->id, $istek['istek_id']);

            throw $hata;
        }

        Log::info('Elle e-Fatura senkronu kuyruğa alındı', [
            'kullanici_id' => $kullaniciId,
            'ortam' => $tanim->ortam,
            'baslangic' => $baslangic,
            'bitis' => $bitis,
        ]);

        return response()->json(['data' => ['kuyrukta' => true]], 202);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function calisma(?EFaturaSenkronCalismasi $c): ?array
    {
        if ($c === null) {
            return null;
        }

        return [
            'durum' => $c->durum,
            'tetikleyen' => $c->tetikleyen,
            'tarih_turu' => $c->tarih_turu,
            'baslangic' => $c->baslangic->toDateString(),
            'bitis' => $c->bitis->toDateString(),
            'basladi' => $c->basladi->toIso8601String(),
            'bitti' => $c->bitti?->toIso8601String(),
            'okunan_adet' => $c->okunan_adet,
            'beklenen_adet' => $c->beklenen_adet,
            'yeni_adet' => $c->yeni_adet,
            'eksik_nedeni' => $c->eksik_nedeni,
            'hata_kodu' => $c->hata_kodu,
        ];
    }
}
