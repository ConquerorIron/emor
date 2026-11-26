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

    /** RED'e çekme yalnız Protel faturasında (kullanıcı kararı 2026-11-26) */
    public const RED_YALNIZ_PROTEL = 'RED_YALNIZ_PROTEL';

    public static function redYalnizProtel(): self
    {
        return new self(self::RED_YALNIZ_PROTEL, __('hata.red_yalniz_protel'));
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
