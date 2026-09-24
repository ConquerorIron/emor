<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * Entegratörden okunan e-Fatura özeti — sağlayıcıdan bağımsız ortak biçim.
 *
 * Tutarlar ondalık METİN olarak tutulur (`"5155262.20"`): float'a çevrilmez.
 * VKN/TCKN metindir (baştaki sıfır kaybolmaz). Bilinmeyen değer null'dır;
 * sıfır ya da "kabul edildi" sayılmaz. `kaynakId` İzibiz'in iç kimliğidir
 * (PDF/HTML görüntüleme bununla yapılır); ETTN ayrı tutulur.
 */
final readonly class EntegratorFatura
{
    public function __construct(
        public FaturaYonu $yon,
        public int $kaynakId,
        public string $ettn,
        public string $belgeNo,
        /** `YYYY-MM-DD` */
        public string $belgeTarihi,
        public ?string $belgeSaati,
        /** İzibiz'e ulaşma/oluşturma zamanı — yerel saat, ekisiz (`YYYY-MM-DDTHH:MM:SS`) */
        public ?string $olusturmaZamani,
        /** SATIS, IADE, TEVKIFAT, ISTISNA… */
        public ?string $faturaTipi,
        /** TEMELFATURA, TICARIFATURA, IHRACAT… */
        public ?string $senaryo,
        public string $paraBirimi,
        public string $tutar,
        public ?string $vergiTutari,
        public ?int $satirSayisi,
        public ?string $gondericiVkn,
        public ?string $gondericiUnvan,
        public ?string $aliciVkn,
        public ?string $aliciUnvan,
        /** İzibiz belge durumu (ör. RECEIVED, ACCEPTED) */
        public ?string $durum,
        public ?string $durumAciklamasi,
        public ?int $gibDurumKodu,
        public ?string $gibDurumAciklamasi,
        /** İzibiz `erpReadFlag` — ERP'nin faturayı alıp almadığı. YALNIZ okunur. */
        public ?bool $erpOkundu,
        public ?bool $okundu,
        public ?string $yanitAciklamasi,
        // Liste ek alanları (2026-09-24) — hepsi isteğe bağlı
        public ?string $gondericiAdSoyad = null,
        public ?string $aliciAdSoyad = null,
        /** GİB gönderici birim etiketi (GB) */
        public ?string $gondericiEtiketi = null,
        /** GİB posta kutusu etiketi (PK) */
        public ?string $aliciEtiketi = null,
        public ?string $irsaliyeNo = null,
        public ?string $siparisNo = null,
        /** `YYYY-MM-DD` */
        public ?string $siparisTarihi = null,
        public ?string $gtbRefNo = null,
        public ?string $gcbTescilNo = null,
        public ?string $gcbTarihi = null,
        public ?string $portalNotu = null,
        /** İzibiz `deliveryRef` — ekran karşılığı netleşmedi */
        public ?string $teslimRef = null,
        /** İzibiz `externalTransferFlag` — ekran karşılığı netleşmedi */
        public ?bool $hariciAktarim = null,
        /** İzibiz `mailStatus` — ekran karşılığı netleşmedi */
        public ?string $mailDurumu = null,
    ) {}
}
