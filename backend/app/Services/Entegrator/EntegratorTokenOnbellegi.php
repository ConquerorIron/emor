<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Entegratör token önbelleği — İzibiz ve Protel istemcilerinin ortak parçası
 * (Fable incelemesi PRT-F8). Token önbellekte şifreli durur; anahtar kimlik
 * sürümünü içerir (kullanıcı, şifre, adres, Protel'de VKN değişince eski
 * token kullanılmaz). Eşzamanlı yenileme kilitle tek girişe indirilir.
 *
 * Kimlik reddi hatırlanır (PRT-F1): şifre karşı tarafta değiştiyse otomatik
 * senkron her turda yeniden giriş denemesin — başarısız girişler birikip
 * hesabı kilitleyebilir (Protel'de hesap otelindir). Red, aynı kimlik sürümü
 * için RED_SURESI boyunca istek gönderilmeden yinelenir; şifre / kullanıcı /
 * adres güncellenince sürüm değiştiği için kendiliğinden düşer. Bağlantı
 * sınaması (önbelleksiz `tokenAl`) bu kilide takılmaz; kayıtlı şifreyle
 * başarılı sınama reddi siler.
 */
final class EntegratorTokenOnbellegi
{
    /** Reddedilen kimlik bu kadar süre yeniden denenmez (sn): günde en çok 4 deneme */
    public const RED_SURESI = 6 * 3600;

    /**
     * Önbellekteki token; yoksa kilit altında `$al` ile alınıp saklanır.
     *
     * @param  Closure(): array{0: string, 1: CarbonImmutable}  $al  önbelleksiz giriş: [token, UTC bitiş]
     * @param  int  $kilitSuresi  kilidin ömrü ve en uzun bekleme (sn) — girişin tekrarlarıyla toplam süresinden uzun
     * @param  int  $guvenlikPayi  bitişten bu kadar önce yenilenir (sn)
     */
    public function token(EntegratorBaglanti $tanim, int $kilitSuresi, int $guvenlikPayi, Closure $al): string
    {
        // Kuyruk işi tanımı önceden yüklemiş olabilir: anahtar güncel sürümden
        $tanim->refresh();
        $anahtar = $tanim->tokenOnbellekAnahtari();

        $this->reddedildiyseDur($anahtar);

        $onbellekte = $this->oku($anahtar);
        if ($onbellekte !== null) {
            return $onbellekte;
        }

        try {
            return Cache::lock($anahtar.':kilit', $kilitSuresi)->block($kilitSuresi, function () use ($anahtar, $tanim, $guvenlikPayi, $al): string {
                $onbellekte = $this->oku($anahtar);
                if ($onbellekte !== null) {
                    return $onbellekte;
                }

                // Kilidi bekleyen süreç, kilit sahibinin aldığı reddi tekrarlamasın
                $this->reddedildiyseDur($anahtar);

                try {
                    [$token, $bitis] = $al();
                } catch (EntegratorHatasi $hata) {
                    if ($hata->kod === EntegratorHatasi::KIMLIK_HATALI) {
                        $this->reddiHatirla($tanim, $anahtar);
                    }

                    throw $hata;
                }

                $sure = $bitis->getTimestamp() - CarbonImmutable::now()->getTimestamp() - $guvenlikPayi;
                Cache::put($anahtar, Crypt::encryptString($token), max(60, $sure));

                return $token;
            });
        } catch (LockTimeoutException) {
            // Başka süreç kilidi tutarken token alamadı / çok uzun sürdü
            throw EntegratorHatasi::erisilemedi();
        }
    }

    /**
     * Önbellekteki token'ı atar. `$reddedilen` verilirse yalnız önbellekteki
     * hâlâ o token'sa: eşzamanlı iki 401'de ikincisi, birincinin yeni aldığı
     * token'ı silip fazladan giriş yaptırmasın (PRT-F14).
     */
    public function unut(EntegratorBaglanti $tanim, ?string $reddedilen = null): void
    {
        $anahtar = $tanim->tokenOnbellekAnahtari();

        if ($reddedilen === null || $this->oku($anahtar) === $reddedilen) {
            Cache::forget($anahtar);
        }
    }

    /** Kayıtlı kimlikle giriş yeniden başardı (bağlantı sınaması): otomatik işler yeniden denesin. */
    public function reddiUnut(EntegratorBaglanti $tanim): void
    {
        Cache::forget($tanim->tokenOnbellekAnahtari().':red');
    }

    private function reddedildiyseDur(string $anahtar): void
    {
        if (Cache::has($anahtar.':red')) {
            throw EntegratorHatasi::girisBekletiliyor(intdiv(self::RED_SURESI, 3600));
        }
    }

    /**
     * Red bir kez günlüğe yazılır: EntegratorHatasi kimlik hatasını raporlamaz,
     * zamanlanmış senkron durursa iz kalsın (PRT-F3).
     */
    private function reddiHatirla(EntegratorBaglanti $tanim, string $anahtar): void
    {
        Cache::put($anahtar.':red', true, self::RED_SURESI);

        Log::warning('Entegratör girişi reddedildi; kimlik güncellenene kadar otomatik giriş denenmez', [
            'saglayici' => $tanim->saglayici,
            'ortam' => $tanim->ortam,
            'bekleme_saat' => intdiv(self::RED_SURESI, 3600),
        ]);
    }

    private function oku(string $anahtar): ?string
    {
        $sifreli = Cache::get($anahtar);

        if (! is_string($sifreli)) {
            return null;
        }

        try {
            return Crypt::decryptString($sifreli);
        } catch (DecryptException) {
            // APP_KEY değişmiş olabilir — bozuk kaydı at, yeniden alınsın
            Cache::forget($anahtar);

            return null;
        }
    }
}
