<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\IsgucuIceAktarRequest;
use App\Services\Kesinti\IsgucuIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * İşgücü Kesintileri yüklemeleri (akış: IcmalYuklemeController).
 */
final class IsgucuYuklemeController extends IcmalYuklemeController
{
    public function __construct(IsgucuIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(IsgucuIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(IsgucuIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
