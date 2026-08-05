<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: aynı domain'den gelen frontend istekleri session cookie ile doğrulanır
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // API hata sözleşmesi: yanıt gövdesi makine okunur `kod` alanı taşır,
        // frontend bu kodu i18n mesajına çevirir (frontend/src/api/errors.ts).
        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json(['kod' => 'YETKISIZ', 'mesaj' => __('hata.yetkisiz')], 401)
                : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json(['kod' => 'ERISIM_ENGELLI', 'mesaj' => __('hata.erisim_engelli')], 403)
                : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json(['kod' => 'ERISIM_ENGELLI', 'mesaj' => __('hata.erisim_engelli')], 403)
                : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json([
                    'kod' => 'DOGRULAMA',
                    'mesaj' => $e->getMessage(),
                    'hatalar' => $e->errors(),
                ], $e->status)
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json(['kod' => 'COK_FAZLA_ISTEK', 'mesaj' => __('hata.cok_fazla_istek')], 429, $e->getHeaders())
                : null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? response()->json(['kod' => 'BULUNAMADI', 'mesaj' => __('hata.bulunamadi')], 404)
                : null;
        });

        // Sözleşme garantisi: yukarıda eşlenmeyen HER hata da `kod` alanı taşır;
        // iç detay yalnızca debug modunda görünür. Bu callback EN SONDA kayıtlı olmalıdır.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            $durum = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $basliklar = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

            $govde = ['kod' => 'BILINMEYEN', 'mesaj' => __('hata.bilinmeyen')];

            if (config('app.debug') === true) {
                $govde['debug'] = ['exception' => $e::class, 'mesaj' => $e->getMessage()];
            }

            return response()->json($govde, $durum, $basliklar);
        });
    })->create();
