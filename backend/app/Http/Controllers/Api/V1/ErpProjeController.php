<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\ErpProjeAktarRequest;
use App\Services\Kesinti\ErpProjeAktarimi;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * Projeler → "ERP'den Getir": önce önizleme (her ERP projesinin aktarımda
 * ne olacağı), sonra seçilenlerin kaydı. ERP okunamazsa ErpOkumaHatasi
 * (bootstrap/app.php) kod + mesajla döner.
 */
final class ErpProjeController extends Controller
{
    public function __construct(
        private readonly ErpProjeAktarimi $aktarim,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (array $satir): array => Arr::except($satir, 'proje_id'), $this->aktarim->onizle()),
        ]);
    }

    public function store(ErpProjeAktarRequest $request): JsonResponse
    {
        /** @var list<string> $guidler */
        $guidler = $request->validated('guidler');

        return response()->json(['data' => $this->aktarim->aktar($guidler)]);
    }
}
