<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AuthController extends Controller
{
    public function login(LoginRequest $request, ErpKimlikDogrulayici $erp): JsonResponse
    {
        $user = $request->authenticate($erp);

        // Session fixation koruması; stateful (SPA) isteklerde session vardır
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // İlk ERP girişinde kullanıcı yeni oluşturulur; resource'un 201'i yerine
        // login her zaman 200 döner
        return (new UserResource($user))->response()->setStatusCode(200);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
