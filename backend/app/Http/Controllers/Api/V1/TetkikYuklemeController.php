<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\TetkikIceAktarRequest;
use App\Services\Kesinti\TetkikIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Tetkik Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class TetkikYuklemeController extends IcmalYuklemeController
{
    public function __construct(TetkikIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(TetkikIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(TetkikIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
