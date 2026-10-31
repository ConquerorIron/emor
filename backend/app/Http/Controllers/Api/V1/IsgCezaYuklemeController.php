<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\IsgCezaIceAktarRequest;
use App\Services\Kesinti\IsgCezaIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * İSG Ceza Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class IsgCezaYuklemeController extends IcmalYuklemeController
{
    public function __construct(IsgCezaIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(IsgCezaIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(IsgCezaIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
