<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KesintiTuruFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Taşeron kesinti türü (Kesinti Yönetimi). Excel'den aktarılan icmal
 * satırları ileride bu türe bağlanacak.
 *
 * @property int $id
 * @property string $kod Benzersiz
 * @property string $ad
 * @property string $kdv_orani Yüzde, iki ondalıklı ("20.00")
 * @property string|null $aciklama
 */
final class KesintiTuru extends Model
{
    /** @use HasFactory<KesintiTuruFactory> */
    use HasFactory;

    protected $table = 'kesinti_turleri';

    protected $fillable = ['kod', 'ad', 'kdv_orani', 'aciklama'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kdv_orani' => 'decimal:2',
        ];
    }
}
