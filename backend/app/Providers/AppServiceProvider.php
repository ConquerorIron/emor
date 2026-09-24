<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\ErpFaturaKaynagi;
use App\Services\ErpFaturaSorgusu;
use App\Services\ErpKimlikDogrulama;
use App\Services\ErpKimlikDogrulayici;
use App\Yetki\Izin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        $this->app->bind(
            ErpFaturaKaynagi::class,
            ErpFaturaSorgusu::class,
        );
    }

    public function boot(): void
    {
        // ERP sistem yöneticisi: tüm izinlere sahiptir ve yetki devrinin
        // sınırlarına takılmaz (yalnız kendi izinlerini dağıtabilme kuralı).
        // Bayrak ERP kullanıcılarında her girişte ERP'den tazelenir; lokal
        // fallback admin'de seeder ile açıktır. Ekranlar artık ekran izinleriyle
        // korunur (App\Yetki\Izin::ekranlar).
        Gate::define('sistem-yonetimi', fn (User $user): bool => $user->sistem_yoneticisi === true);

        // Rol tabanlı izinler (EFAT-18): her katalog değeri aynı adla bir Gate.
        // Sistem yöneticisi tüm izinlere sahiptir (User::izinler).
        foreach (Izin::cases() as $izin) {
            Gate::define($izin->value, fn (User $user): bool => in_array($izin->value, $user->izinler(), true));
        }

        // Rol listesi Kullanıcılar ekranında rol atamak için de okunur
        Gate::define('rol-listesi', fn (User $user): bool => array_intersect(
            [Izin::RollerGoruntule->value, Izin::KullanicilarGoruntule->value],
            $user->izinler(),
        ) !== []);

        // Brute-force koruması: IP + kullanıcı adı bazlı
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip().'|'.$request->string('kullanici_adi')->value());
        });

        // Bağlantı sınama MSSQL'e gerçek bağlantı açar; makul sıklıkla sınırlı
        RateLimiter::for('sql-sina', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        // Entegratör sınaması gerçek hesapla giriş yapar; art arda hatalı
        // denemelerin hesabı kilitleyip kilitlemediği bilinmediği için sıkı
        RateLimiter::for('entegrator-sina', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        // Test maili gerçek SMTP'ye bağlanır ve dışarı mail gönderir
        RateLimiter::for('mail-test', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        // PDF her açılışta İzibiz'den okunur; aynı hesabı kullanan ERP
        // entegrasyonunu zorlamamak için kullanıcı başına sınırlı
        RateLimiter::for('efatura-pdf', function (Request $request): Limit {
            return Limit::perMinute(30)->by($request->user()?->id ?? $request->ip());
        });

        // Excel çıktısı bellekte üretilir (en fazla config('efatura.excel_azami_satir') satır)
        RateLimiter::for('efatura-excel', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        // ERP Senkronla: her basış ERP'de tüm işlenmiş fatura ETTN'lerini okur
        RateLimiter::for('efatura-erp', function (Request $request): Limit {
            return Limit::perMinute(6)->by($request->user()?->id ?? $request->ip());
        });
    }
}
