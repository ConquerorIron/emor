<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hedefin fiziksel konumu (BY-016-R1): kopya kayıtları hedef kimliğine
 * bağlıdır ve dosyayı o hedefin konumunda arar. Kopyası duran (tamamlanmış
 * ya da sürmekte olan) hedefte konumu belirleyen alanlar değiştirilemez;
 * başka bir yer için yeni hedef açılır. Kimlik bilgisi (parola, anahtar) ve
 * konum belirlemeyen alanlar (ad, port, aktiflik…) serbesttir.
 */
final class HedefKonumu
{
    /** Dosyanın nerede durduğunu belirleyen alanlar (bağlayıcı tanımlarındaki adlar) */
    private const ALANLAR = ['sunucu', 'klasor', 'kova', 'uc_nokta', 'bolge', 'on_ek', 'baglanti_noktasi', 'klasor_adi'];

    /** Göreli klasör kullanıcının ev klasörüne göre çözülür: orada kullanıcı adı da konumdur */
    private const GORELI_KLASORLU_TURLER = [HedefTuru::Ftp, HedefTuru::Ftps, HedefTuru::Sftp];

    /** `withExists` sütunu: liste sorgusunda hedef başına ayrı sorgu olmasın */
    public const KOPYALI_SUTUNU = 'konumda_kopya_var';

    public function __construct(private readonly HedefKatalogu $katalog) {}

    /**
     * Liste sorgusuna "konumda kopya var mı" sütununu ekler.
     *
     * @param  Builder<YedekHedefi>  $sorgu
     * @return Builder<YedekHedefi>
     */
    public static function kopyaVarMiEkle(Builder $sorgu): Builder
    {
        return $sorgu->withExists(['kopyalar as '.self::KOPYALI_SUTUNU => fn ($q) => $q->whereIn('durum', self::kilitleyenDurumlar())]);
    }

    /**
     * Değiştirilemeyen alanlar; kopyası olmayan hedefte boş.
     *
     * @return list<string>
     */
    public function kilitliAlanlar(YedekHedefi $hedef): array
    {
        if (! $this->katalog->destekleniyorMu($hedef->tur) || ! $this->kopyaVarMi($hedef)) {
            return [];
        }

        $adlar = array_values(array_filter(
            array_map(fn (HedefAlani $a): string => $a->ad, $this->katalog->tanim($hedef->tur)->alanlar),
            fn (string $ad): bool => in_array($ad, self::ALANLAR, true),
        ));

        if (in_array($hedef->tur, self::GORELI_KLASORLU_TURLER, true) && ! str_starts_with((string) ($hedef->baglanti['klasor'] ?? ''), '/')) {
            $adlar[] = 'kullanici_adi';
        }

        return $adlar;
    }

    /**
     * Yeni açık alanlarla değişecek kilitli alanlar.
     *
     * @param  array<string, mixed>  $yeniAcik  Formun açık alanları (varsayılanlar uygulanmış)
     * @return list<string>
     */
    public function degisenKilitliAlanlar(YedekHedefi $hedef, array $yeniAcik): array
    {
        $varsayilanlar = [];
        foreach ($this->katalog->tanim($hedef->tur)->alanlar as $alan) {
            $varsayilanlar[$alan->ad] = $alan->varsayilan;
        }

        return array_values(array_filter(
            $this->kilitliAlanlar($hedef),
            fn (string $ad): bool => ($hedef->baglanti[$ad] ?? $varsayilanlar[$ad] ?? null) != ($yeniAcik[$ad] ?? null),
        ));
    }

    private function kopyaVarMi(YedekHedefi $hedef): bool
    {
        if (array_key_exists(self::KOPYALI_SUTUNU, $hedef->getAttributes())) {
            return (bool) $hedef->getAttribute(self::KOPYALI_SUTUNU);
        }

        return $hedef->exists && $hedef->kopyalar()->whereIn('durum', self::kilitleyenDurumlar())->exists();
    }

    /** @return list<string> */
    private static function kilitleyenDurumlar(): array
    {
        return [KopyaDurumu::Kopyalaniyor->value, KopyaDurumu::Tamam->value];
    }
}
