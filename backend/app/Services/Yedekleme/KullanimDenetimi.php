<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Throwable;

/**
 * "Kullanımdaki yedek" (README §4.3 koruma kuralı): o anda kopyalanan,
 * doğrulanan ya da geri yüklenen yedek temizlikte silinmez.
 *
 * - Kopyası `bekliyor` / `kopyalaniyor` durumunda olan yedek,
 * - işlem durumu dosyasında yürüyen (bitmemiş) işlemin yedeği.
 *
 * Durum dosyası okunamıyorsa bütün yedekler kullanımda sayılır (bilinmeyen
 * durum silme izni değildir — D6).
 */
final class KullanimDenetimi
{
    private const BITMIS_ADIMLAR = ['bitti', 'basarisiz', 'tamam', 'geri_alindi', 'geri_sarildi'];

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @return array<int, true>|null Kullanımdaki yedek kimlikleri (id); null = hepsi (durum bilinmiyor)
     */
    public function kullanimdakiler(): ?array
    {
        $idler = YedekKopyasi::query()
            ->whereIn('durum', [KopyaDurumu::Bekliyor->value, KopyaDurumu::Kopyalaniyor->value])
            ->pluck('yedek_id')
            ->all();
        $kume = array_fill_keys(array_map('intval', $idler), true);

        try {
            $kayit = $this->yapilandirma->islemDurumu()->oku();
        } catch (Throwable) {
            return null;
        }

        // Üstüne geri yüklemenin bitişi kendi enum'undan (Fable F17); bilinmeyen adım "bitmedi" sayılır
        $bitti = $kayit !== null && $kayit->tur === IslemTuru::GeriYuklemeUstune
            ? UstuneGeriYuklemeAdimi::tryFrom($kayit->adim)?->bittiMi() === true
            : in_array($kayit?->adim, self::BITMIS_ADIMLAR, true);

        if ($kayit !== null && $kayit->yedekKimligi !== null && ! $bitti) {
            $id = Yedek::query()->where('kimlik', $kayit->yedekKimligi)->value('id');
            if ($id !== null) {
                $kume[(int) $id] = true;
            }
        }

        return $kume;
    }

    public function kullanimdaMi(Yedek $yedek): bool
    {
        $kume = $this->kullanimdakiler();

        return $kume === null || isset($kume[$yedek->id]);
    }
}
