<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use RuntimeException;

/**
 * Hedef işlemi başarısız. `kod` makine okunurdur (yedek_kopyalari.hata_kodu,
 * ekrandaki sınama adımı); mesaj sır ve ham yanıt gövdesi taşımaz (D2).
 */
final class HedefHatasi extends RuntimeException
{
    public const ERISILEMEDI = 'HEDEF_ERISILEMEDI';

    public const KIMLIK = 'HEDEF_KIMLIK_HATALI';

    public const YAZILAMADI = 'HEDEF_YAZILAMADI';

    public const OKUNAMADI = 'HEDEF_OKUNAMADI';

    public const SILINEMEDI = 'HEDEF_SILINEMEDI';

    /** Hedef bu uygulamaya silme yetkisi vermiyor (değiştirilemez saklama; README §4.6) */
    public const SILME_YETKISI_YOK = 'HEDEF_SILME_YETKISI_YOK';

    public const KOTA = 'HEDEF_KOTA_DOLU';

    /** Bulut bağlantısının izni kaldırıldı / süresi doldu (Google Drive) */
    public const YENIDEN_BAGLAN = 'HEDEF_YENIDEN_BAGLAN';

    public const SUNUCU_PARMAK_IZI = 'HEDEF_SUNUCU_PARMAK_IZI_DEGISTI';

    public const SERTIFIKA = 'HEDEF_SERTIFIKA_HATASI';

    public const BAGLI_DEGIL = 'HEDEF_BAGLI_DEGIL';

    public const YAPILANDIRMA = 'HEDEF_YAPILANDIRMA_HATASI';

    /** Hedefteki dosya yedeğin yerel dosyasının kendisi: bağımsız kopya sayılmaz (BY-008-R1) */
    public const ANA_DOSYA = 'HEDEF_ANA_DOSYA';

    /**
     * Hedef başka bir hedefle aynı fiziksel depoya yazıyor (aynı klasör; uzakta
     * aynı sunucu + kullanıcı + klasör ya da S3 uç noktası + kova + önek): aynı
     * dosya iki bağımsız kopya sayılmaz (HedefKonumu::ayniDepoMu; BY-010-R7, R8). Hedef
     * kaydında 409, kopyada ve saklamada kalıcı hata (yeniden denenmez).
     */
    public const AYNI_DEPO = 'HEDEF_AYNI_DEPO';

    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
