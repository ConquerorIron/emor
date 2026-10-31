<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\MalzemeIceAktarRequest;
use App\Services\Kesinti\MalzemeIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Malzeme Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class MalzemeYuklemeController extends IcmalYuklemeController
{
    public function __construct(MalzemeIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(MalzemeIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(MalzemeIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
