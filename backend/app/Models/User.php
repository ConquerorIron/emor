<?php

declare(strict_types=1);

namespace App\Models;

use App\Yetki\Izin;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

/**
 * Uygulama kullanıcısı. `kaynak` alanı kimlik doğrulama yolunu belirler:
 * - 'lokal': şifre bu tablodadır (hash'li) — kurulum/acil durum admin'i
 * - 'erp': şifre ERP MSSQL tarafında doğrulanır; bu satır yerel yansımadır
 *
 * @property int $id
 * @property string $ad
 * @property string $kullanici_adi
 * @property string|null $email
 * @property string $kaynak
 * @property int|null $erp_kullanici_id
 * @property bool $sistem_yoneticisi
 * @property string|null $password
 * @property bool $aktif_mi
 */
#[Fillable(['ad', 'kullanici_adi', 'email', 'kaynak', 'erp_kullanici_id', 'sistem_yoneticisi', 'password', 'aktif_mi'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const KAYNAK_LOKAL = 'lokal';

    public const KAYNAK_ERP = 'erp';

    /**
     * @return BelongsToMany<Rol, $this>
     */
    public function roller(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'kullanici_rolleri', 'user_id', 'rol_id');
    }

    /**
     * Kullanıcının etkin izinleri (rollerinden). Sistem yöneticisi tüm
     * kataloğa sahiptir. Katalogda olmayan (eskimiş) izin sayılmaz. Her
     * çağrıda veritabanından okunur: rol değişikliği bir sonraki istekte geçerli.
     *
     * @return list<string>
     */
    public function izinler(): array
    {
        if ($this->sistem_yoneticisi) {
            return Izin::degerler();
        }

        $atanmis = DB::table('rol_izinleri')
            ->join('kullanici_rolleri', 'kullanici_rolleri.rol_id', '=', 'rol_izinleri.rol_id')
            ->where('kullanici_rolleri.user_id', $this->id)
            ->distinct()
            ->pluck('rol_izinleri.izin')
            ->all();

        return array_values(array_intersect(Izin::degerler(), $atanmis));
    }

    /**
     * `.env`'deki lokal fallback admin'i: kilitlenmeyi önlemek için pasife
     * alınamaz (ERP erişilemezken tek giriş yolu).
     */
    public function yedekAdminMi(): bool
    {
        return $this->kaynak === self::KAYNAK_LOKAL
            && $this->kullanici_adi === (string) config('erp.admin_kullanici');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif_mi' => 'boolean',
            'sistem_yoneticisi' => 'boolean',
        ];
    }
}
