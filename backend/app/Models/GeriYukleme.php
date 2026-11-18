<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Geri yükleme geçmişi (README §4.5, §6.4). İşlemin asıl durumu işlem durumu
 * DOSYASINDADIR: veritabanı geri yüklenirken bu tablo da değişir.
 *
 * @property int $id
 * @property string $kimlik
 * @property int $yedek_id
 * @property GeriYuklemeTuru $tur
 * @property string $hedef_veritabani
 * @property string|null $onceki_veritabani
 * @property int|null $isteyen_kullanici_id
 * @property GeriYuklemeDurumu $durum
 * @property list<array<string, mixed>>|null $adimlar
 * @property string|null $hata_kodu
 * @property string|null $hata_mesaji
 * @property CarbonImmutable|null $basladi
 * @property CarbonImmutable|null $bitti
 * @property CarbonImmutable|null $veritabani_silinme_zamani
 */
final class GeriYukleme extends Model
{
    protected $table = 'geri_yuklemeler';

    /** Yalnız uygulama kodu doldurur (istekten toplu atama yok) */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Yedek, $this>
     */
    public function yedek(): BelongsTo
    {
        return $this->belongsTo(Yedek::class, 'yedek_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function isteyenKullanici(): BelongsTo
    {
        return $this->belongsTo(User::class, 'isteyen_kullanici_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tur' => GeriYuklemeTuru::class,
            'durum' => GeriYuklemeDurumu::class,
            'adimlar' => 'array',
            'basladi' => 'immutable_datetime',
            'bitti' => 'immutable_datetime',
            'veritabani_silinme_zamani' => 'immutable_datetime',
        ];
    }
}
