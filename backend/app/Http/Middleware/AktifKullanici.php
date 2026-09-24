<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasife alınan kullanıcının açık oturumu bir sonraki istekte düşürülür
 * (EFAT-18). Yanıt frontend sözleşmesine uyar: 403 + `HESAP_PASIF`
 * (api/client.ts toast gösterip giriş ekranına döner).
 */
final class AktifKullanici
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->aktif_mi) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['kod' => 'HESAP_PASIF', 'mesaj' => __('auth.pasif')], 403);
        }

        return $next($request);
    }
}
