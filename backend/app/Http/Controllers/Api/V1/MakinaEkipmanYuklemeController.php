<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\MakinaEkipmanIceAktarRequest;
use App\Services\Kesinti\MakinaEkipmanIceAktarimi;
use Illuminate\Http\JsonResponse;

/**
 * Makina Ekipman Kesintileri yüklemeleri (akış: IcmalYuklemeController).
 */
final class MakinaEkipmanYuklemeController extends IcmalYuklemeController
{
    public function __construct(MakinaEkipmanIceAktarimi $aktarim)
    {
        parent::__construct($aktarim);
    }

    public function onizle(MakinaEkipmanIceAktarRequest $request): JsonResponse
    {
        return $this->onizleme($request);
    }

    public function store(MakinaEkipmanIceAktarRequest $request): JsonResponse
    {
        return $this->kayit($request);
    }
}
