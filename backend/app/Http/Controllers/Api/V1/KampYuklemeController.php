<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KampIceAktarRequest;
use App\Services\Kesinti\KampIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Kamp (oda) Kesintileri yüklemeleri (akış: IcmalYuklemeController).
 */
final class KampYuklemeController extends IcmalYuklemeController
{
    public function __construct(KampIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(KampIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(KampIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
