<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KampMalzemeIceAktarRequest;
use App\Services\Kesinti\KampMalzemeIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Kamp Malzeme Kesintileri yüklemeleri (akış: IcmalYuklemeController).
 */
final class KampMalzemeYuklemeController extends IcmalYuklemeController
{
    public function __construct(KampMalzemeIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(KampMalzemeIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(KampMalzemeIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
