<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Yedekleme ayarları — tek satır (MailAyari kalıbı): zamanlanmamış yedeklerin
 * saklama süreleri, hedef başına en az yedek sayısı, disk eşiği, bildirim
 * alıcıları (README §4.3, §4.8).
 *
 * Varsayılanlar migration'dadır ve `$attributes`'ta AYNEN yansıtılır: yeni
 * nesne kaydedilmeden önce de onları taşır (Dikkat_Edilecekler K4). İkisi
 * birlikte değişir; YedeklemeVeriModeliTest farkı yakalar.
 *
 * @property int $id
 * @property string $anahtar
 * @property int $yayin_oncesi_son_adet
 * @property int $yayin_oncesi_gun
 * @property int $elle_gun
 * @property int $geri_yukleme_oncesi_gun
 * @property int $enaz_adet
 * @property int $enaz_bos_alan_yuzde
 * @property list<string> $bildirim_alicilari
 * @property string|null $sinyal_yerel_adresi Yaşam sinyali: yerel yedek (README §4.8)
 * @property string|null $sinyal_uzak_adresi Yaşam sinyali: sunucu dışı kopya
 * @property string|null $sinyal_sinama_adresi Yaşam sinyali: sınama geri yüklemesi
 * @property string|null $sifreleme_acik_anahtari Sunucu dışı kopyaların açık anahtarı, ASCII zırhlı (README §4.7; özel anahtar burada DEĞİL)
 * @property string|null $sifreleme_parmak_izi Açık anahtarın parmak izi (büyük harf onaltılık)
 * @property string|null $sifreleme_anahtar_kullanicisi Anahtarın kullanıcı kimliği (bilgi)
 * @property CarbonImmutable|null $sifreleme_anahtar_bitisi Anahtarın son geçerlilik anı; süresizse null
 */
final class YedekAyari extends Model
{
    public const ANAHTAR = 'varsayilan';

    protected $table = 'yedek_ayarlari';

    /** @var array<string, mixed> Migration varsayılanlarının aynısı (tek satırın anahtarı dahil) */
    protected $attributes = [
        'anahtar' => self::ANAHTAR,
        'yayin_oncesi_son_adet' => 20,
        'yayin_oncesi_gun' => 30,
        'elle_gun' => 30,
        'geri_yukleme_oncesi_gun' => 30,
        'enaz_adet' => 3,
        'enaz_bos_alan_yuzde' => 15,
        'bildirim_alicilari' => '[]',
    ];

    protected $fillable = [
        'anahtar',
        'yayin_oncesi_son_adet',
        'yayin_oncesi_gun',
        'elle_gun',
        'geri_yukleme_oncesi_gun',
        'enaz_adet',
        'enaz_bos_alan_yuzde',
        'bildirim_alicilari',
        'sinyal_yerel_adresi',
        'sinyal_uzak_adresi',
        'sinyal_sinama_adresi',
        'sifreleme_acik_anahtari',
        'sifreleme_parmak_izi',
        'sifreleme_anahtar_kullanicisi',
        'sifreleme_anahtar_bitisi',
    ];

    /**
     * Tek satır; kaydedilmemişse migration varsayılanlarıyla yeni nesne
     * (kaydetmez — okuyan yer satır açmasın).
     */
    public static function guncel(): self
    {
        return self::query()->where('anahtar', self::ANAHTAR)->first() ?? new self;
    }

    /**
     * Özel anahtar veritabanına (ve dolayısıyla yedeklere) hiçbir yoldan
     * yazılmaz (README §4.7, K3). Asıl doğrulama yüklemede
     * (KopyaSifreleyici::acikAnahtarBilgisi); bu, onu atlayan yazıma karşı son engel.
     */
    protected static function booted(): void
    {
        self::saving(function (self $ayar): void {
            if (is_string($ayar->sifreleme_acik_anahtari) && preg_match('/BEGIN PGP (?:PRIVATE|SECRET) KEY/i', $ayar->sifreleme_acik_anahtari) === 1) {
                throw new LogicException('Yedek ayarlarına özel anahtar yazılamaz; yalnız açık anahtar saklanır.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yayin_oncesi_son_adet' => 'integer',
            'yayin_oncesi_gun' => 'integer',
            'elle_gun' => 'integer',
            'geri_yukleme_oncesi_gun' => 'integer',
            'enaz_adet' => 'integer',
            'enaz_bos_alan_yuzde' => 'integer',
            'bildirim_alicilari' => 'array',
            'sifreleme_anahtar_bitisi' => 'immutable_datetime',
        ];
    }
}
