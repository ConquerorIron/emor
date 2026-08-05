<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
 * @property string|null $password
 * @property bool $aktif_mi
 */
#[Fillable(['ad', 'kullanici_adi', 'email', 'kaynak', 'erp_kullanici_id', 'password', 'aktif_mi'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const KAYNAK_LOKAL = 'lokal';

    public const KAYNAK_ERP = 'erp';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'aktif_mi' => 'boolean',
        ];
    }
}
