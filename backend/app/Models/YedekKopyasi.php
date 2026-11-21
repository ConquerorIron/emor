<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Yedekleme\KopyaDurumu;
use Carbon\CarbonImmutable;
use Database\Factories\YedekKopyasiFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Bir yedeğin bir hedefteki TEK fiziksel kopyası (README §4.3, §4.6). Aynı
 * hedefi paylaşan planlar bu kopyaya bağlanır (`planlar`); her plan kendi
 * kuralıyla yalnız kendi bağını kaldırır, dosya hiçbir bağ kalmayınca
 * silinir (BY-010). Bir hedefteki silme diğer hedefleri etkilemez.
 *
 * @property int $id
 * @property int $yedek_id
 * @property int $yedek_hedefi_id
 * @property KopyaDurumu $durum
 * @property string|null $uzak_kimlik
 * @property bool $sifreli
 * @property string|null $sifreli_sha256
 * @property bool $kurtarma_hazir Şifreli kopya için: o hedefe bu yedeğin manifestini taşıyan kurtarma paketi doğrulandı (BY-010-R2)
 * @property int $deneme
 * @property string|null $hata_kodu
 * @property string|null $hata_mesaji
 * @property CarbonImmutable|null $tamamlanma_zamani
 * @property CarbonImmutable|null $silinme_zamani
 */
final class YedekKopyasi extends Model
{
    /** @use HasFactory<YedekKopyasiFactory> */
    use HasFactory;

    protected $table = 'yedek_kopyalari';

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
     * @return BelongsTo<YedekHedefi, $this>
     */
    public function hedef(): BelongsTo
    {
        return $this->belongsTo(YedekHedefi::class, 'yedek_hedefi_id');
    }

    /**
     * Kopyayı tutan planlar. Boş olması yedeğin türünü söylemez: zamanlanmamış
     * yedek de, son bağı kalkıp koruma kuralıyla tutulan plan yedeği de bağsızdır
     * (kullanıcı kararı F6); saklama kuralı `yedek->kaynak`'tan seçilir. Bağ
     * planın bu hedefteki saklama kuralına bağlıdır: eklerken pivotta
     * `yedek_hedefi_id` kopyanın hedefi olarak verilir; plan o hedefi
     * kullanmıyorsa veritabanı reddeder (BY-004-R8).
     *
     * @return BelongsToMany<YedekPlani, $this>
     */
    public function planlar(): BelongsToMany
    {
        return $this->belongsToMany(YedekPlani::class, 'yedek_kopya_planlari', 'yedek_kopyasi_id', 'yedek_plani_id');
    }

    /**
     * Kopya, bulunduğu yerin olağan yoluyla geri yüklenebilir mi (BY-010-R2,
     * BY-024-R3). `tamam` yalnız aktarımın doğrulandığını söyler:
     *
     * - Şifreli (sunucu dışı) kopya, sunucu kaybında kurtarma paketiyle açılır
     *   (kurtarma.md §4); o hedefte bu yedeğin imzalı manifestini taşıyan
     *   doğrulanmış bir paket olmalı (`kurtarma_hazir`, KurtarmaPaketi işaretler).
     * - Şifresiz kopya yalnız sunucu içi yerel disk hedefidir (HedefKatalogu:
     *   `sunucuDisi` false); kurtarma paketi ona gönderilmez, ekrandaki geri
     *   yükleme yedek klasöründe kalan manifestle yapar (README §4.3): `tamam`
     *   olması yeter.
     */
    public function kurtarmayaHazirMi(): bool
    {
        return $this->durum === KopyaDurumu::Tamam && (! $this->sifreli || $this->kurtarma_hazir === true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'durum' => KopyaDurumu::class,
            'sifreli' => 'boolean',
            'kurtarma_hazir' => 'boolean',
            'deneme' => 'integer',
            'tamamlanma_zamani' => 'immutable_datetime',
            'silinme_zamani' => 'immutable_datetime',
        ];
    }
}
