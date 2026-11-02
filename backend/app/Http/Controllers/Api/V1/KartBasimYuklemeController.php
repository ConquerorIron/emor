<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KartBasimIceAktarRequest;
use App\Services\Kesinti\KartBasimIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Personel Kart Basım Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class KartBasimYuklemeController extends IcmalYuklemeController
{
    public function __construct(KartBasimIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(KartBasimIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(KartBasimIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
