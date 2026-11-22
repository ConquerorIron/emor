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
use LogicException;

/**
 * Bir yedeğin bir hedefteki TEK fiziksel kopyası (README §4.3, §4.6). Aynı
 * hedefi paylaşan planlar bu kopyaya bağlanır (`planlar`); her plan kendi
 * kuralıyla yalnız kendi bağını kaldırır, dosya hiçbir bağ kalmayınca
 * silinir (BY-010). Bir hedefteki silme diğer hedefleri etkilemez.
 *
 * Hedefi tanımsız kopya (BY-028-R9): olay günlüğündeki kopyanın hedefi
 * veritabanında yoksa (geri yüklenen eski veritabanı, yedekten sonra eklenmiş
 * hedef) kopya `yedek_hedefi_id` boş, `konum_belirsiz` kayıt olarak tutulur;
 * olaydaki hedef anahtarını ve adını taşır. Yalnız varlık kararlarında
 * (yedeği katalogda tutar) sayılır, hiçbir işlemin kaynağı olmaz. Başka bir
 * durumda olamaz (aşağıda booted; PostgreSQL'de CHECK). Aynı hedefe bu
 * yedeğin başka bir yaşayan nesnesi bağlandığı için ayrı tutulan kopya da
 * böyle bir kayıttır (`KOPYA_HEDEFTE_BASKA_KOPYA`; BY-028-R10).
 *
 * @property int $id
 * @property int $yedek_id
 * @property int|null $yedek_hedefi_id Boş = hedefi tanımsız kopya (yalnız konum_belirsiz)
 * @property string|null $olay_hedef_anahtari Hedefsiz kopyada olaydaki hedef anahtarı (GuncelDurum::hedefAnahtari)
 * @property string|null $olay_hedef_ad Hedefi tanımsız kopyada olaydaki hedef adı (gösterim)
 * @property KopyaDurumu $durum
 * @property string|null $uzak_kimlik
 * @property bool $sifreli
 * @property string|null $sifreli_sha256
 * @property bool $kurtarma_hazir Şifreli kopya için: o hedefte bu yedeğin doğrulanmış manifestini (imza, kimlik, özet) taşıyan tamam bir kurtarma paketi sürümü duruyor (BY-010-R2, BY-024-R5)
 * @property string|null $konum_izi Kopyanın yapıldığı yerin kimliği (HedefKonumu::iz; BY-028-R5); izsiz kopya yeri doğrulanamayandır
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

    /** Ekranda ve sonuç mesajında gösterilen hedef adı; hedefi tanımsız kopyada olaydaki ad (BY-028-R9) */
    public function hedefAdi(): ?string
    {
        return $this->hedef?->ad ?? $this->olay_hedef_ad;
    }

    /**
     * Hedefi tanımsız kopya kullanılamaz kalır (K3): `konum_belirsiz`
     * dışındaki her durum bir hedefe dayanır (kopyalama, silme, geri yükleme).
     */
    protected static function booted(): void
    {
        self::saving(function (self $kopya): void {
            if ($kopya->yedek_hedefi_id === null && ($kopya->durum !== KopyaDurumu::KonumBelirsiz || $kopya->olay_hedef_anahtari === null)) {
                throw new LogicException('Hedefi tanımsız kopya yalnız "konumu doğrulanamadı" olarak, olaydaki hedef anahtarıyla tutulur (BY-028-R9).');
            }
        });
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
