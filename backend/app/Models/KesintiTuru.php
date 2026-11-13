<?php

declare(strict_types=1);

namespace App\Models;

use Collator;
use Database\Factories\KesintiTuruFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * Türler, bağlı oldukları ekranın menü sırasıyla (KesintiYuklemesi::ICMALLER);
     * ekrana bağlanmamışlar sonda, koduna göre — Firma Özeti kolonları ve
     * ekstre bölümleri bu sırada.
     *
     * @param  list<int>|null  $idler  null: hepsi
     * @return list<self>
     */
    public static function ekranSirasiyla(?array $idler = null): array
    {
        $ekranSirasi = array_flip(KesintiYuklemesi::ICMALLER);
        $sira = fn (self $tur): int => $tur->icmal === null ? PHP_INT_MAX : ($ekranSirasi[$tur->icmal] ?? PHP_INT_MAX);
        $siralayici = new Collator('tr_TR');
        $turler = self::query()->when($idler !== null, fn (Builder $q): Builder => $q->whereKey($idler))->get()->all();
        usort($turler, fn (self $a, self $b): int => $sira($a) <=> $sira($b) ?: (int) $siralayici->compare($a->kod, $b->kod));

        return $turler;
    }

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
