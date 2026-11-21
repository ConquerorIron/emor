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
 * gerekmez (G1).
 */
enum KopyaDurumu: string
{
    case Bekliyor = 'bekliyor';
    case Kopyalaniyor = 'kopyalaniyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case Silindi = 'silindi';
    case KonumBelirsiz = 'konum_belirsiz';
}
