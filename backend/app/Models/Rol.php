<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Yetki rolü (EFAT-18). İzinler `rol_izinleri` tablosunda, katalog
 * App\Yetki\Izin'dedir.
 *
 * @property int $id
 * @property string $ad
 * @property string|null $aciklama
 */
final class Rol extends Model
{
    protected $table = 'roller';

    protected $fillable = ['ad', 'aciklama'];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function kullanicilar(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kullanici_rolleri', 'rol_id', 'user_id');
    }

    /**
     * @return list<string>
     */
    public function izinler(): array
    {
        /** @var list<string> */
        return DB::table('rol_izinleri')->where('rol_id', $this->id)->orderBy('izin')->pluck('izin')->all();
    }
}
