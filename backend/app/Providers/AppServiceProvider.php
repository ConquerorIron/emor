<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ErpKimlikDogrulama;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            ErpKimlikDogrulayici::class,
            ErpKimlikDogrulama::class,
        );
    }

    public function boot(): void
    {
        // Brute-force koruması: IP + kullanıcı adı bazlı
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->string('kullanici_adi')->value());
        });

        // Bağlantı sınama MSSQL'e gerçek bağlantı açar; makul sıklıkla sınırlı
        RateLimiter::for('sql-sina', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });
    }
}
