<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\YemekIceAktarRequest;
use App\Services\Kesinti\YemekIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Yemek Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class YemekYuklemeController extends IcmalYuklemeController
{
    public function __construct(YemekIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(YemekIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(YemekIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
