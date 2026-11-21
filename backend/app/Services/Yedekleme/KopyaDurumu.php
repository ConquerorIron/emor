<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Bir yedeğin bir hedefteki kopyasının durumu (README §4.6). Başarısız kopya
 * yeniden denenir; yedeğin kendisi yine geçerlidir.
 *
 * `KonumBelirsiz` (BY-028-R5): olay günlüğündeki kopya, hedefin BUGÜNKÜ
 * konumunda yapıldığı doğrulanamayan kopyadır — konum izi hedefinkiyle
 * uyuşmuyor ya da hiç yok (HedefKonumu::iz). Dosya başka bir yerde
 * olabilir: `tamam` sayılmaz, bu yüzden geri yükleme kaynağı, silme, saklama
 * ve kurtarma hazırlığı (hepsi yalnız `tamam`'a bakar) onu kullanmaz, hedefin
 * konum kilidini de tutmaz. Hedef kopyanın yerine dönünce sonraki taramada
 * yeniden `tamam` olur. Ayrı bir kolon değil durum değeri: kopyayı kullanan
 * her yer zaten `tamam`'a baktığı için bayrağı her yerde ayrıca denetlemek
 * gerekmez (G1). İstisna, fiziksel varlığa dayanan kararlar: fiziksel kopya
 * durabileceği için yedeği katalogda tutar (`silindi` kararı; BY-028-R7) ve
 * kurtarma paketinde son doğrulanmış manifestinin sürümünü korur (BY-024-R8);
 * Google Drive hedefinin hesabı doğrulanmadan değişmez (kopya eski hesapta
 * kalır; BY-021-R5) — hepsi yedegiTutanlar.
 */
enum KopyaDurumu: string
{
    case Bekliyor = 'bekliyor';
    case Kopyalaniyor = 'kopyalaniyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case Silindi = 'silindi';
    case KonumBelirsiz = 'konum_belirsiz';

    /**
     * Dosyası hedefte duran ya da durabilecek kopya durumları: böyle bir kopya
     * kaldıkça yedek `silindi` olmaz, varsayılan listede kopyanın uyarısıyla
     * görünür (F7; BY-028-R7); kurtarma paketi dönüşümü böyle bir kopyanın
     * son doğrulanmış manifestini taşıyan sürümü silmez (BY-024-R8); Google
     * Drive hedefinin hesabı böyle bir kopya varken doğrulanmadan değişmez
     * (BY-021-R5). Yalnız
     * varlık kararları içindir: geri yükleme kaynağı, silme, saklama ve
     * kurtarma hazırlığı yine yalnız `Tamam`'a bakar.
     *
     * @return list<string>
     */
    public static function yedegiTutanlar(): array
    {
        return [self::Tamam->value, self::KonumBelirsiz->value];
    }
}
