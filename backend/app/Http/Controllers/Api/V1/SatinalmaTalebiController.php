<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SatinalmaTalebiKaydi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Satınalma Talebi kaydı — SOHOM_SIPARIS_KAYDET.
 *
 * Kayıt ERP'ye yapılır; bizde saklanmaz.
 */
final class SatinalmaTalebiController extends Controller
{
    public function __construct(
        private readonly SatinalmaTalebiKaydi $kayit,
    ) {}

    public function kaydet(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'personel_id' => ['required', 'integer'],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'termin' => ['nullable', 'date_format:Y-m-d'],
            'oncelik_id' => ['nullable', 'integer'],
            'aciklama' => ['nullable', 'string', 'max:200'],
            'hakkinda' => ['nullable', 'string', 'max:3072'],
            'ilgi_cinsi' => ['nullable', 'integer'],
            'ilgili_id' => ['nullable', 'integer'],
            'depomuz_id' => ['nullable', 'integer'],
            'teslimat_adresi_id' => ['nullable', 'integer'],
            'teslimat_adresi' => ['nullable', 'string', 'max:200'],
            'teslimat_bicimi' => ['nullable', 'integer'],
            'teslimat_sekli_id' => ['nullable', 'integer'],
            'teslimat_suresi' => ['nullable', 'numeric'],
            'teslimat_suresi_birimi' => ['nullable', 'integer'],
            'alim_yeri' => ['nullable', 'integer'],

            /*
             * Denetim penceresinden gelen karar (ERP akışının aynısı):
             *   yok            → önce denetle; kısıtlama varsa pencere açılır
             *   onaya_sun      → onaya sun ve onaysız kaydet
             *   onaya_sunmadan → kısıtlamalara rağmen onaya sunmadan kaydet
             */
            'karar' => ['nullable', 'in:onaya_sun,onaya_sunmadan'],

            'satirlar' => ['required', 'array', 'min:1'],
            // Ürün satırın kimliğidir; ERP'ye giden değer URUN_YAMASI_ID'dir
            'satirlar.*.urun_yamasi_id' => ['required', 'integer'],
            'satirlar.*.miktar' => ['nullable', 'numeric'],
        ]);

        $erpKullaniciId = $request->user()?->erp_kullanici_id;

        if ($erpKullaniciId === null) {
            // ERP'ye kaydı YAPAN kullanıcı yazılır; lokal admin bunu yapamaz
            throw ValidationException::withMessages([
                'personel_id' => 'erp_kullanicisi_gerekli',
            ]);
        }

        try {
            $sonuc = $this->kayit->kaydet(
                [...$veri, 'satirlar' => $request->input('satirlar', [])],
                (int) $erpKullaniciId,
                match ($veri['karar'] ?? null) {
                    'onaya_sun' => SatinalmaTalebiKaydi::DENETIM_ONAYA_SUN,
                    'onaya_sunmadan' => SatinalmaTalebiKaydi::DENETIM_ONAYA_SUNMADAN,
                    default => SatinalmaTalebiKaydi::DENETIM_DENETLE,
                },
            );
        } catch (\Throwable $hata) {
            report($hata);

            // ERP'nin kendi hata metni entegrasyon aşamasında en değerli bilgi;
            // makine okunur kod korunarak kullanıcıya da gösterilir
            return response()->json([
                'kod' => 'erp_kayit_basarisiz',
                'message' => $this->erpMesaji($hata),
            ], 422);
        }

        return response()->json(['data' => $sonuc], 201);
    }

    /** SQL Server hata metninden sürücü/bağlantı gürültüsünü ayıklar */
    private function erpMesaji(\Throwable $hata): string
    {
        $mesaj = $hata->getMessage();
        $temiz = preg_replace('/\[[^\]]+\]/', '', strtok($mesaj, "\n")) ?? $mesaj;

        return trim(str_replace('SQLSTATE', '', $temiz), " \t:");
    }
}
