<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\OsgbIceAktarRequest;
use App\Services\Kesinti\OsgbIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * OSGB Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class OsgbYuklemeController extends IcmalYuklemeController
{
    public function __construct(OsgbIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(OsgbIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(OsgbIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
