<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Entegrator\EmorDurumu;
use Carbon\CarbonImmutable;
use Database\Factories\EFaturaFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * e-Fatura özeti: entegratörden (İzibiz ya da Protel — EFAT-10, PRT-06)
 * senkronla ya da statik kaynaktan (Logo, e-Arşiv / e-SMM — EK-05) içe
 * aktarımla gelir. Senkron ve içe aktarım yalnız kaynağın alanlarını yazar;
 * ekrandan değişen alanlara (gizleme, harici RED, İşlendi) dokunmaz.
 *
 * Faturanın sınıfı (docs/efatura/Ek-Isler-Jira.md §4.3) TEK yerde, bu
 * modelin sorgu kapsamlarında tanımlıdır: Red / İşlendi / İşlenecek. Hızlı
 * filtreler ve pano kendi koşulunu yazmaz, bunları çağırır. Hatırlatma maili
 * (EK-10) 2026-11-26'dan beri "işlendi" sayılan durumları kuraldan alır
 * (AlarmDegerlendirici::islenmemisKosulu; varsayılanı islenecek ile aynı).
 *
 * @property int $id
 * @property int $entegrator_baglanti_id
 * @property string $yon
 * @property int|null $kaynak_id Entegratör kimliği (statik kaynakta null)
 * @property string|null $ettn e-Arşiv / e-SMM'de null
 * @property string|null $belge_anahtari Statik kaynağın eşleştirme anahtarı (entegratörde null)
 * @property string|null $belge_turu e-Arşiv tanımında 'fatura' | 'makbuz'
 * @property string $belge_no
 * @property CarbonImmutable $belge_tarihi
 * @property string $tutar Ödenecek / tahsil edilecek tutar
 * @property bool|null $erp_okundu
 * @property EmorDurumu|null $emor_durumu ERP'deki aşama (null: henüz kontrol edilmedi)
 * @property CarbonImmutable|null $gizlenme_zamani null: listede görünür
 * @property int|null $gizleyen_id
 * @property CarbonImmutable|null $harici_red_zamani Yetkili kullanıcının RED'e çektiği an (EK-04)
 * @property int|null $harici_red_eden_id
 * @property string|null $harici_red_nedeni
 * @property int|null $yukleme_id Satırı ekleyen içe aktarım partisi (EK-06) * @property string|null $erp_durumu Protel `erpStatus` (İzibiz'de null)
 * @property bool|null $yazdirildi Protel `isPrinted` (İzibiz'de null)
 * @property bool|null $otel_erp_okundu Protel `isErpRecieved` — otelin ERP'si aldı mı (İzibiz'de null; eMOR okuması `erp_okundu`)
 * @property bool|null $sap_islendi SAP'ye işlendi mi. Her kaynakta (İzibiz, Protel, statik) ekrandaki "İşlendi" anahtarı elle yazar (true / NULL; reddedilmiş faturada yazılmaz ve gösterimde "Red" önce gelir); kaynaktan okunmaz
 */
final class EFatura extends Model
{
    /** @use HasFactory<EFaturaFactory> */
    use HasFactory;

    protected $table = 'efatura_faturalari';

    /**
     * Kaynağında reddedilmiş (ya da iptal edilmiş) faturanın durumları:
     * İzibiz `REJECTED` (büyük harf), Protel portal red yanıtı (2026-11-24),
     * Logo dosyasındaki "Uygulama Yanıtı = RED" ve e-Arşiv / e-SMM listesindeki
     * iptal / itiraz. Statik kaynakların değerleri içe aktarımda bu sabitlerle yazılır.
     *
     * @var list<string>
     */
    public const array KAYNAK_RED_DURUMLARI = ['REJECTED', 'RejectAnswered', 'RejectAnswerCompleted', self::DURUM_RED, self::DURUM_IPTAL];

    /** Logo dosyası: uygulama yanıtı RED */
    public const DURUM_RED = 'RED';

    /** e-Arşiv / e-SMM listesi: iptal ya da itiraz edilmiş */
    public const DURUM_IPTAL = 'IPTAL';

    /** @var list<string> */
    public const array ISLENMIS_EMOR_DURUMLARI = [EmorDurumu::Islendi->value, EmorDurumu::ElleIslendi->value];

    /** @var list<string> */
    protected $guarded = ['id'];

    public function kaynaktaReddedildi(): bool
    {
        return in_array($this->getAttribute('durum'), self::KAYNAK_RED_DURUMLARI, true);
    }

    /** Kaynakta RED ya da yetkili kullanıcının RED'e çektiği fatura: "İşlendi" işaretlenemez */
    public function reddedildi(): bool
    {
        return self::redDurumu($this->getAttribute('durum'), $this->harici_red_zamani);
    }

    /**
     * reddedildi()'nin kuralı, model kurmadan okunan satırlar için (raporlar).
     * Liste süzgeci aynı kuralın SQL hâlidir: reddedilen() kapsamı.
     */
    public static function redDurumu(mixed $durum, mixed $hariciRedZamani): bool
    {
        return in_array($durum, self::KAYNAK_RED_DURUMLARI, true) || $hariciRedZamani !== null;
    }

    /**
     * Belge tarihi iki uç dahil aralıkta (Y-m-d). Üst sınır "ertesi günden
     * küçük": SQLite tarihi saatle saklar (`2026-12-31 00:00:00`),
     * `<= '2026-12-31'` o günü dışarıda bırakırdı (Dikkat T6). Liste, pano ve
     * rapor bu tek koşulu kullanır.
     *
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function belgeTarihiAraliginda(Builder $sorgu, string $baslangic, string $bitis): void
    {
        $sorgu->where('belge_tarihi', '>=', $baslangic)
            ->where('belge_tarihi', '<', CarbonImmutable::parse($bitis)->addDay()->toDateString());
    }

    /**
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function reddedilen(Builder $sorgu): void
    {
        $sorgu->where(fn (Builder $q) => $q->whereIn('durum', self::KAYNAK_RED_DURUMLARI)->orWhereNotNull('harici_red_zamani'));
    }

    /**
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function reddedilmeyen(Builder $sorgu): void
    {
        $sorgu->where(fn (Builder $q) => $q->whereNull('durum')->orWhereNotIn('durum', self::KAYNAK_RED_DURUMLARI))
            ->whereNull('harici_red_zamani');
    }

    /**
     * Sınıf "İşlendi": RED değil ve eMOR'da ya da SAP'de işlendi.
     *
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function islenen(Builder $sorgu): void
    {
        $sorgu->reddedilmeyen()->where(fn (Builder $q) => $this->islenmisKosulu($q));
    }

    /**
     * Sınıf "İşlenecek": RED değil, işlenmemiş.
     *
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function islenecek(Builder $sorgu): void
    {
        $sorgu->reddedilmeyen()
            ->where(fn (Builder $q) => $q->whereNull('emor_durumu')->orWhereNotIn('emor_durumu', self::ISLENMIS_EMOR_DURUMLARI))
            ->where(fn (Builder $q) => $q->whereNull('sap_islendi')->orWhere('sap_islendi', false));
    }

    /**
     * Uyarı: RED olduğu hâlde eMOR'da ya da SAP'de işlenmiş (pano; §6 soru 6).
     *
     * @param  Builder<self>  $sorgu
     */
    #[Scope]
    protected function redAmaIslenen(Builder $sorgu): void
    {
        $sorgu->reddedilen()->where(fn (Builder $q) => $this->islenmisKosulu($q));
    }

    /**
     * @param  Builder<self>  $q
     */
    private function islenmisKosulu(Builder $q): void
    {
        $q->whereIn('emor_durumu', self::ISLENMIS_EMOR_DURUMLARI)->orWhere('sap_islendi', true);
    }

    /**
     * @return BelongsTo<EntegratorBaglanti, $this>
     */
    public function entegratorBaglanti(): BelongsTo
    {
        return $this->belongsTo(EntegratorBaglanti::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function gizleyen(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gizleyen_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function hariciRedEden(): BelongsTo
    {
        return $this->belongsTo(User::class, 'harici_red_eden_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kaynak_id' => 'integer',
            'belge_tarihi' => 'immutable_date',
            'olusturma_zamani' => 'immutable_datetime',
            'tutar' => 'decimal:4',
            'vergi_tutari' => 'decimal:4',
            'brut_tutar' => 'decimal:4',
            'stopaj_tutari' => 'decimal:4',
            'satir_sayisi' => 'integer',
            'gib_durum_kodu' => 'integer',
            'erp_okundu' => 'boolean',
            'emor_durumu' => EmorDurumu::class,
            'okundu' => 'boolean',
            'siparis_tarihi' => 'immutable_date',
            'harici_aktarim' => 'boolean',
            'yazdirildi' => 'boolean',
            'otel_erp_okundu' => 'boolean',
            'sap_islendi' => 'boolean',
            'ubl_okundu' => 'immutable_datetime',
            'ubl_son_deneme' => 'immutable_datetime',
            'ubl_hata' => 'integer',
            'satir_toplami' => 'decimal:4',
            'indirim_tutari' => 'decimal:4',
            'vergi_haric_tutar' => 'decimal:4',
            'vergi_dahil_tutar' => 'decimal:4',
            'kur' => 'decimal:6',
            'ilk_gorulme' => 'immutable_datetime',
            'son_gorulme' => 'immutable_datetime',
            'gizlenme_zamani' => 'immutable_datetime',
            'harici_red_zamani' => 'immutable_datetime',
        ];
    }
}
