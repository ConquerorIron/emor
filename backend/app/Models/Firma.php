<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FirmaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Firma / taşeron (Kesinti Yönetimi). Elle açılır ya da canlı ERP'den
 * aktarılır (App\Services\Kesinti\ErpFirmaAktarimi).
 *
 * @property int $id
 * @property string $kod Benzersiz, Türkçe kurallı büyük harf
 * @property string $ad
 * @property string|null $vergi_kimlik_no Benzersiz; boşluksuz
 * @property string|null $vergi_dairesi
 * @property string|null $eposta
 * @property string|null $gsm_no
 * @property string|null $erp_guid ERP firmasının GUID'i (küçük harf); elle açılanda null
 * @property bool $firmamiz Firmamız (HAB); yalnız tek firma
 */
final class Firma extends Model
{
    /** @use HasFactory<FirmaFactory> */
    use HasFactory;

    protected $table = 'firmalar';

    protected $fillable = ['kod', 'ad', 'vergi_kimlik_no', 'vergi_dairesi', 'eposta', 'gsm_no', 'erp_guid', 'firmamiz'];

    /** Kaydedilmemiş modelde de veritabanı varsayılanıyla aynı */
    protected $attributes = [
        'firmamiz' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'firmamiz' => 'boolean',
        ];
    }
}
