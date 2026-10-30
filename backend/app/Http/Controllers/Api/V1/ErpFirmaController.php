<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\ErpFirmaAktarRequest;
use App\Services\Kesinti\ErpFirmaAktarimi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * Firmalar → "ERP'den Getir": önce önizleme, sonra seçilenlerin kaydı
 * (akış: App\Services\Kesinti\ErpAktarimi). ERP okunamazsa ErpOkumaHatasi
 * (bootstrap/app.php) kod + mesajla döner.
 */
final class ErpFirmaController extends Controller
{
    public function __construct(
        private readonly ErpFirmaAktarimi $aktarim,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (array $satir): array => Arr::except($satir, 'kayit_id'), $this->aktarim->onizle()),
        ]);
    }

    public function store(ErpFirmaAktarRequest $request): JsonResponse
    {
        /** @var list<string> $guidler */
        $guidler = $request->validated('guidler');

        return response()->json(['data' => $this->aktarim->aktar($guidler)]);
    }
}
