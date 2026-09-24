<?php

declare(strict_types=1);

namespace App\Yetki;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Yetki devrinin sınırı: Roller/Kullanıcılar ekranı yönetici olmayan birine
 * açıldığında kendini (ya da başkasını) kendinden yetkili yapamasın.
 *
 * - Yönetici olmayan yalnız KENDİNDE olan izinleri rollere verebilir, yalnız
 *   izinleri kendi izinlerinin içinde kalan rolleri düzenleyip silebilir ve
 *   kullanıcılara atayabilir.
 * - Sistem yöneticisi hesapları yalnız sistem yöneticisince değiştirilir.
 *
 * Sistem yöneticisi bu sınırlara takılmaz.
 */
final class YetkiSiniri
{
    /**
     * Verenin sahip olmadığı izinler (boşsa verebilir).
     *
     * @param  list<string>  $izinler
     * @return list<string>
     */
    public static function asanIzinler(User $veren, array $izinler): array
    {
        if ($veren->sistem_yoneticisi) {
            return [];
        }

        return array_values(array_diff(Izin::tamamla($izinler), $veren->izinler()));
    }

    /**
     * Rollerin izinlerinden verenin sahip olmadıkları.
     *
     * @param  list<int>  $rolIdleri
     * @return list<string>
     */
    public static function asanRolIzinleri(User $veren, array $rolIdleri): array
    {
        if ($veren->sistem_yoneticisi || $rolIdleri === []) {
            return [];
        }

        /** @var list<string> $izinler */
        $izinler = DB::table('rol_izinleri')->whereIn('rol_id', $rolIdleri)->distinct()->pluck('izin')->all();

        return self::asanIzinler($veren, $izinler);
    }
}
