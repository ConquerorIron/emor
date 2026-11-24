<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Üstüne geri yüklemenin ekrana / komut satırına gösterilen hâli (BY-029,
 * BY-030). Yalnız DOSYALARDAN kurulur (işlem durumu + onay bilgisi):
 * ilerleme ucu bakım modunda ve veritabanı erişilemezken de yanıt verir
 * (README §7.2).
 *
 * `durum` değerleri:
 * - `calisiyor`: adımlar yürüyor (ya da yarıda kalmış, çalıştırıcı sürdürecek)
 * - `onay_bekliyor`: yer değiştirme bitti, yöneticinin onayı / geri dönüşü bekleniyor
 * - `hata`: işlem açık ve hatayla durmuş; yer değiştirmeden sonraysa karar
 *   yöneticinin (onayla / geri dön), geri sarma hatasıysa ya da adlar
 *   okunamadıysa (`GERI_YUKLEME_ADLAR_OKUNAMADI`, YF-41) çalıştırıcı yeniden dener
 * - `onaylandi`, `geri_donuldu`, `basarisiz` (geri sarıldı; canlıya dokunulmadı): kapandı
 * - `bilinmiyor`: adım bu sürümün bildiği bir adım değil (elle incelenmeli)
 */
final class UstuneGeriYuklemeGorunumu
{
    /**
     * @param  array<string, mixed>|null  $onayBilgisi  OnayBilgisi::bilgiOku()
     * @return array<string, mixed>
     */
    public static function diziye(IslemKaydi $kayit, ?array $onayBilgisi): array
    {
        $adim = UstuneGeriYuklemeAdimi::tryFrom($kayit->adim);
        $yerDegisti = $adim !== null && ($adim->yerDegistirmedenSonraMi() || $adim === UstuneGeriYuklemeAdimi::Onaylandi);

        return [
            'islem_kimligi' => $kayit->kimlik,
            'durum' => self::durum($kayit, $adim),
            'adim' => $kayit->adim,
            'acik' => $kayit->acik,
            'yer_degistirildi' => $yerDegisti,
            'hata' => $kayit->hata,
            'baslangic' => $kayit->baslangic->toIso8601String(),
            'son_guncelleme' => $kayit->sonGuncelleme->toIso8601String(),
            'erisim_anahtari_bitis' => $kayit->erisimAnahtariBitis?->toIso8601String(),
            'yedek_kimligi' => $kayit->yedekKimligi,
            'hedef_veritabani' => $kayit->hedefVeritabani,
            'onceki_veritabani' => $kayit->oncekiVeritabani,
            'adimlar' => self::adimlar($kayit, $adim),
            'yapilabilir' => [
                'onayla' => self::onaylanabilirMi($kayit, $adim),
                'geri_don' => self::geriDonulebilirMi($kayit, $adim),
            ],
            'onay_bilgisi' => $yerDegisti ? $onayBilgisi : null,
        ];
    }

    private static function durum(IslemKaydi $kayit, ?UstuneGeriYuklemeAdimi $adim): string
    {
        return match (true) {
            $adim === null => 'bilinmiyor',
            $adim === UstuneGeriYuklemeAdimi::Onaylandi => 'onaylandi',
            $adim === UstuneGeriYuklemeAdimi::GeriDonuldu => 'geri_donuldu',
            $adim === UstuneGeriYuklemeAdimi::Basarisiz => 'basarisiz',
            $kayit->hata !== null => 'hata',
            $adim === UstuneGeriYuklemeAdimi::OnayBekliyor => 'onay_bekliyor',
            default => 'calisiyor',
        };
    }

    /**
     * İleri akışın adımları ve her birinin durumu: tamam | suruyor | hata | bekliyor.
     *
     * @return list<array{adim: string, durum: string}>
     */
    private static function adimlar(IslemKaydi $kayit, ?UstuneGeriYuklemeAdimi $adim): array
    {
        $sira = $adim === null ? false : array_search($adim, UstuneGeriYuklemeAdimi::ILERI_AKIS, true);
        // Onay / geri dönüş aşamasında ileri akışın hepsi bitmiştir
        $hepsiTamam = $adim !== null && in_array($adim, [UstuneGeriYuklemeAdimi::Onaylaniyor, UstuneGeriYuklemeAdimi::Onaylandi, UstuneGeriYuklemeAdimi::GeriDonuluyor, UstuneGeriYuklemeAdimi::GeriDonuldu], true);

        $liste = [];
        foreach (UstuneGeriYuklemeAdimi::ILERI_AKIS as $i => $a) {
            $durum = match (true) {
                $hepsiTamam => 'tamam',
                $sira === false => 'bekliyor',
                $i < $sira => 'tamam',
                $i === $sira && $a === UstuneGeriYuklemeAdimi::OnayBekliyor => 'suruyor',
                $i === $sira => $kayit->hata !== null ? 'hata' : 'suruyor',
                default => 'bekliyor',
            };
            $liste[] = ['adim' => $a->value, 'durum' => $durum];
        }

        return $liste;
    }

    private static function onaylanabilirMi(IslemKaydi $kayit, ?UstuneGeriYuklemeAdimi $adim): bool
    {
        return $adim !== null && $adim->yerDegistirmedenSonraMi() && $adim !== UstuneGeriYuklemeAdimi::GeriDonuluyor
            && ($adim === UstuneGeriYuklemeAdimi::OnayBekliyor || $kayit->hata !== null);
    }

    private static function geriDonulebilirMi(IslemKaydi $kayit, ?UstuneGeriYuklemeAdimi $adim): bool
    {
        if ($adim === null || $adim->bittiMi() || $adim === UstuneGeriYuklemeAdimi::Onaylaniyor) {
            return false;
        }

        return $adim === UstuneGeriYuklemeAdimi::OnayBekliyor
            || ($kayit->hata !== null && ($adim->yerDegistirmedenSonraMi() || $adim === UstuneGeriYuklemeAdimi::YerDegistirme));
    }
}
