<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KesintiTuruFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Taşeron kesinti türü (Kesinti Yönetimi). Bağlı olduğu icmal ekranında
 * kullanılır; başka ekrana girilemez / aktarılamaz.
 *
 * @property int $id
 * @property string $kod Benzersiz
 * @property string $ad
 * @property string $kdv_orani Yüzde, iki ondalıklı ("20.00")
 * @property string|null $aciklama
 * @property string|null $icmal Bağlı ekran (KesintiYuklemesi::ICMALLER); null: hiçbir ekranda kullanılamaz
 */
final class KesintiTuru extends Model
{
    /** @use HasFactory<KesintiTuruFactory> */
    use HasFactory;

    protected $table = 'kesinti_turleri';

    protected $fillable = ['kod', 'ad', 'kdv_orani', 'aciklama', 'icmal'];

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
