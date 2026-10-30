<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProjeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Proje (Kesinti Yönetimi). Elle açılır ya da canlı ERP'den aktarılır
 * (App\Services\Kesinti\ErpProjeAktarimi).
 *
 * @property int $id
 * @property string $kod Benzersiz, Türkçe kurallı büyük harf
 * @property string $ad
 * @property string|null $erp_guid ERP projesinin GUID'i (küçük harf); elle açılanda null
 */
final class Proje extends Model
{
    /** @use HasFactory<ProjeFactory> */
    use HasFactory;

    protected $table = 'projeler';

    protected $fillable = ['kod', 'ad', 'erp_guid'];
}
