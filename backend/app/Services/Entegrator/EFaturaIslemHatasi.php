<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use RuntimeException;

/**
 * Ekrandan yapılan e-Fatura işleminin (RED, İşlendi, içe aktarım) kural
 * ihlali. `kod` API hata sözleşmesindeki makine okunur koddur
 * (bootstrap/app.php render eder, frontend api/errors.ts çevirir).
 */
final class EFaturaIslemHatasi extends RuntimeException
{
    /** RED faturası "İşlendi" işaretlenemez (kaynakta ya da harici RED) */
    public const REDDEDILDI = 'FATURA_REDDEDILDI';

    /** Kaynağında zaten reddedilmiş fatura ayrıca RED'e çekilmez */
    public const KAYNAKTA_REDDEDILMIS = 'FATURA_KAYNAKTA_REDDEDILMIS';

    /** "İşlendi" işaretli fatura RED'e çekilmez; önce işaret kaldırılır */
    public const ISLENDI_ISARETLI = 'FATURA_ISLENDI_ISARETLI';

    /** Partinin eklediği faturalardan bazılarında elle işlem var: geri alınmaz */
    public const YUKLEME_ELLE_ISLENMIS = 'YUKLEME_ELLE_ISLENMIS';

    /** Aynı kaynağa başka bir içe aktarım ya da geri alma sürüyor */
    public const ICE_AKTARIM_SURUYOR = 'ICE_AKTARIM_SURUYOR';

    /** eMOR'da (ERP) işlenmiş ya da elle işlenmiş fatura RED'e çekilmez (kullanıcı isteği 2026-11-26) */
    public const EMOR_ISLENMIS = 'FATURA_EMOR_ISLENMIS';

    /**
     * eMOR durumu henüz okunmamış (yeni yüklenmiş) fatura RED'e çekilmez:
     * işlenmiş olup olmadığı bilinmiyor (inceleme B-6, D6)
     */
    public const EMOR_BILINMIYOR = 'FATURA_EMOR_BILINMIYOR';

    public static function emorIslenmis(): self
    {
        return new self(self::EMOR_ISLENMIS, __('hata.fatura_emor_islenmis'));
    }

    public static function emorBilinmiyor(): self
    {
        return new self(self::EMOR_BILINMIYOR, __('hata.fatura_emor_bilinmiyor'));
    }

    private function __construct(
        public readonly string $kod,
        string $mesaj,
        public readonly int $httpDurumu = 409,
    ) {
        parent::__construct($mesaj);
    }

    public static function reddedildi(): self
    {
        return new self(self::REDDEDILDI, __('hata.fatura_reddedildi'));
    }

    public static function kaynaktaReddedilmis(): self
    {
        return new self(self::KAYNAKTA_REDDEDILMIS, __('hata.fatura_kaynakta_reddedilmis'));
    }

    public static function islendiIsaretli(): self
    {
        return new self(self::ISLENDI_ISARETLI, __('hata.fatura_islendi_isaretli'));
    }

    public static function yuklemeElleIslenmis(int $adet): self
    {
        return new self(self::YUKLEME_ELLE_ISLENMIS, __('hata.yukleme_elle_islenmis', ['adet' => $adet]));
    }

    /**
     * Partinin eklediği faturalardan bazılarını sonraki (hâlâ duran) bir parti
     * yeniden yükledi: geri alınmaz, önce sonraki partiler geri alınır (inceleme B-4)
     */
    public const YUKLEME_SONRAKI_YUKLEMEDE = 'YUKLEME_SONRAKI_YUKLEMEDE';

    public static function yuklemeSonrakiYuklemede(int $adet): self
    {
        return new self(self::YUKLEME_SONRAKI_YUKLEMEDE, __('hata.yukleme_sonraki_yuklemede', ['adet' => $adet]));
    }

    /** Statik kaynağın faturası eMOR arşivinde yok: PDF gösterilemez (entegratörü yok) */
    public const BELGE_YOK = 'FATURA_BELGESI_YOK';

    public static function belgeYok(): self
    {
        return new self(self::BELGE_YOK, __('hata.fatura_belgesi_yok'), 404);
    }

    public static function iceAktarimSuruyor(): self
    {
        return new self(self::ICE_AKTARIM_SURUYOR, __('hata.ice_aktarim_suruyor'));
    }
}
