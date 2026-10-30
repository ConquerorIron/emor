<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ParaBirimiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Para birimi (Kesinti Yönetimi). Excel'deki "PARA BİRİMİ" koduyla eşleşir.
 *
 * @property int $id
 * @property string $kod Benzersiz, Türkçe kurallı büyük harf (TL, USD, EUR)
 * @property string $ad
 */
final class ParaBirimi extends Model
{
    /** @use HasFactory<ParaBirimiFactory> */
    use HasFactory;

    protected $table = 'para_birimleri';

    protected $fillable = ['kod', 'ad'];
}
