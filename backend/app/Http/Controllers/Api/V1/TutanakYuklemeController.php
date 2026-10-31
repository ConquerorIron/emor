<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\TutanakIceAktarRequest;
use App\Services\Kesinti\TutanakIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Tutanak Kesintisi yüklemeleri (akış: IcmalYuklemeController).
 */
final class TutanakYuklemeController extends IcmalYuklemeController
{
    public function __construct(TutanakIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(TutanakIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(TutanakIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
