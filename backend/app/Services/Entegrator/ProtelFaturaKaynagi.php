<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Protel'den (otelin posta kutusu) GELEN e-Fatura listesini okur (PRT-07).
 * Tek istek: `GetInvoiceList` sayfalama yapmaz, aralık en çok 180 gündür.
 * Durum süzgeci `All` gönderilir (bütün durumlar). Giden faturalar kapsam dışı.
 *
 * Alan eşlemesi docs/protel/api-notlari.md'deki gerçek yanıta göre. Protel'in
 * iç kimliği (`id`) `kaynakId`'dir; PDF ETTN (`invoiceUUID`) ile alınır.
 */
final class ProtelFaturaKaynagi implements FaturaKaynagi
{
    /** Bizim tarih türümüz → Protel `filterDateType` */
    private const TARIH_TURLERI = [
        self::TARIH_BELGE => 'DocumentDate',
        // Protel'e ulaşma (oluşturulma) tarihi — İzibiz DELIVERY karşılığı
        self::TARIH_ULASMA => 'CreateDate',
    ];

    public function __construct(
        private readonly ProtelIstemcisi $istemci,
    ) {}

    public function oku(
        EntegratorBaglanti $tanim,
        FaturaYonu $yon,
        CarbonImmutable $baslangic,
        CarbonImmutable $bitis,
        string $tarihTuru = self::TARIH_BELGE,
    ): FaturaOkumaSonucu {
        if ($yon !== FaturaYonu::Gelen) {
            throw new InvalidArgumentException('Protel giden faturaları kapsam dışıdır.');
        }

        $this->araligiDogrula($baslangic, $bitis, $tarihTuru);
        $basladi = CarbonImmutable::now();

        // Tarihler İstanbul takvim günüdür; Protel saat dilimi eki beklemez
        $govde = $this->istemci->postSayilariKoruyarak($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, [
            'startDate' => $baslangic->format('Y-m-d').'T00:00:00',
            'endDate' => $bitis->format('Y-m-d').'T23:59:59',
            'filterDateType' => self::TARIH_TURLERI[$tarihTuru],
            'documentDirection' => 'Incoming',
            'erpRecievedStatus' => 'All',
            'erpStatus' => 'All',
        ]);

        // `isSuccess` başarılı yanıtta da false geliyor: liste dizisi esastır
        $kayitlar = $govde['invoiceList'] ?? null;
        if (! is_array($kayitlar) || ! array_is_list($kayitlar)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        /** @var array<int, EntegratorFatura> $faturalar kaynak ID'sine göre tekil */
        $faturalar = [];
        $hataliKayitlar = [];

        foreach ($kayitlar as $ham) {
            $sonuc = $this->cevir($ham);

            if ($sonuc instanceof EntegratorFatura) {
                $faturalar[$sonuc->kaynakId] ??= $sonuc;
            } else {
                $hataliKayitlar[] = $sonuc;
            }
        }

        return new FaturaOkumaSonucu(
            yon: $yon,
            tarihTuru: $tarihTuru,
            baslangic: $baslangic,
            bitis: $bitis,
            faturalar: array_values($faturalar),
            // Protel toplam adet vermez: beklenen = dönen kayıt sayısı
            beklenenAdet: count($kayitlar),
            okunanSayfa: 1,
            hataliKayitlar: $hataliKayitlar,
            eksikNedeni: $hataliKayitlar === [] ? null : FaturaOkumaSonucu::EKSIK_VERI_HATASI,
            basladi: $basladi,
            bitti: CarbonImmutable::now(),
        );
    }

    private function araligiDogrula(CarbonImmutable $baslangic, CarbonImmutable $bitis, string $tarihTuru): void
    {
        if (! isset(self::TARIH_TURLERI[$tarihTuru])) {
            throw new InvalidArgumentException("Geçersiz tarih türü: {$tarihTuru}");
        }

        if ($bitis->lt($baslangic->startOfDay())) {
            throw new InvalidArgumentException('Bitiş tarihi başlangıçtan önce olamaz.');
        }

        $azamiGun = (int) config('entegrator.protel.azami_gun');

        if ($baslangic->startOfDay()->diffInDays($bitis->startOfDay()) + 1 > $azamiGun) {
            throw new InvalidArgumentException("Protel tarih aralığı en çok {$azamiGun} gün olabilir.");
        }
    }

    /**
     * Ham kaydı ortak biçime çevirir; zorunlu alanı eksik/bozuk kayıt sessizce
     * düşürülmez, hata olarak döner.
     *
     * @return EntegratorFatura|array{kaynak_id: int|null, alanlar: list<string>}
     */
    private function cevir(mixed $ham): EntegratorFatura|array
    {
        if (! is_array($ham)) {
            return ['kaynak_id' => null, 'alanlar' => ['kayit']];
        }

        $kaynakId = is_int($ham['id'] ?? null) && $ham['id'] > 0 ? $ham['id'] : null;
        $belgeTarihi = $this->tarih($ham['issueDate'] ?? null);
        $tutar = $this->tutar($ham['payableAmount'] ?? null);
        $vergiTutari = $this->tutar($ham['taxTotal'] ?? null);
        $ettn = $this->metin($ham['invoiceUUID'] ?? null);

        $hatalar = array_keys(array_filter([
            'id' => $kaynakId === null,
            'invoiceUUID' => $ettn === null || preg_match('/^[0-9a-fA-F-]{36}$/D', $ettn) !== 1,
            'documentId' => ! $this->doluMetin($ham['documentId'] ?? null) || $this->fazlaUzun($ham['documentId'] ?? null, 64),
            'issueDate' => $belgeTarihi === null,
            'documentCurrencyCode' => ! $this->doluMetin($ham['documentCurrencyCode'] ?? null) || $this->fazlaUzun($ham['documentCurrencyCode'] ?? null, 3),
            'payableAmount' => $tutar === null,
            'taxTotal' => ($ham['taxTotal'] ?? null) !== null && $vergiTutari === null,
            'documentType' => $this->fazlaUzun($ham['documentType'] ?? null, 64),
            'profileId' => $this->fazlaUzun($ham['profileId'] ?? null, 64),
            'supplierId' => $this->fazlaUzun($ham['supplierId'] ?? null, 32),
            'customerId' => $this->fazlaUzun($ham['customerId'] ?? null, 32),
            'status' => $this->fazlaUzun($ham['status'] ?? null, 64),
            'erpStatus' => $this->fazlaUzun($ham['erpStatus'] ?? null, 32),
            // PostgreSQL `integer` (Laravel unsignedInteger PG'de işaretli integer'dır) — PRT-R5
            'lineCount' => is_int($ham['lineCount'] ?? null) && ($ham['lineCount'] < 0 || $ham['lineCount'] > 2147483647),
        ]));

        if ($hatalar !== [] || $kaynakId === null || $ettn === null || $belgeTarihi === null || $tutar === null) {
            return ['kaynak_id' => $kaynakId, 'alanlar' => $hatalar];
        }

        $gibDurumu = $this->metin($ham['gibStatus'] ?? null);

        return new EntegratorFatura(
            yon: FaturaYonu::Gelen,
            kaynakId: $kaynakId,
            ettn: mb_strtolower($ettn),
            belgeNo: trim((string) $ham['documentId']),
            belgeTarihi: $belgeTarihi,
            belgeSaati: $this->saat($ham['issueTime'] ?? null),
            // Mikro saniye atılır; ekisiz İstanbul saati (senkron UTC'ye çevirir)
            olusturmaZamani: $this->kisalt($this->metin($ham['createAt'] ?? null), 19),
            faturaTipi: $this->metin($ham['documentType'] ?? null),
            senaryo: $this->metin($ham['profileId'] ?? null),
            paraBirimi: trim((string) $ham['documentCurrencyCode']),
            tutar: $tutar,
            vergiTutari: $vergiTutari,
            satirSayisi: is_int($ham['lineCount'] ?? null) ? $ham['lineCount'] : null,
            gondericiVkn: $this->metin($ham['supplierId'] ?? null),
            gondericiUnvan: $this->metin($ham['supplierTitle'] ?? null),
            aliciVkn: $this->metin($ham['customerId'] ?? null),
            aliciUnvan: $this->metin($ham['customerTitle'] ?? null),
            durum: $this->metin($ham['status'] ?? null),
            durumAciklamasi: $this->metin($ham['statusDescription'] ?? null),
            gibDurumKodu: $gibDurumu !== null && preg_match('/^\d{1,9}$/D', $gibDurumu) === 1 ? (int) $gibDurumu : null,
            gibDurumAciklamasi: $this->metin($ham['gibStatusMessage'] ?? null),
            erpOkundu: $this->bayrak($ham['isErpRecieved'] ?? null),
            okundu: $this->bayrak($ham['isRead'] ?? null),
            yanitAciklamasi: $this->metin($ham['appRespMessage'] ?? null),
            gondericiEtiketi: $this->metin($ham['supplierAlias'] ?? null),
            aliciEtiketi: $this->metin($ham['customerAlias'] ?? null),
            irsaliyeNo: $this->metin($ham['despatchId'] ?? null),
            gtbRefNo: $this->metin($ham['gtB_REFNO'] ?? null),
            gcbTescilNo: $this->metin($ham['gtB_GCB_TESCILNO'] ?? null),
            gcbTarihi: $this->tarih($ham['gtB_FIILI_IHRACAT_TARIHI'] ?? null),
            erpDurumu: $this->metin($ham['erpStatus'] ?? null),
            yazdirildi: $this->bayrak($ham['isPrinted'] ?? null),
        );
    }

    /** `YYYY-MM-DD[T…]` → `YYYY-MM-DD`; geçersiz takvim günü null. */
    private function tarih(mixed $deger): ?string
    {
        $metin = $this->metin($deger);
        if ($metin === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T[\d:.]+)?$/D', $metin, $p) !== 1) {
            return null;
        }

        return checkdate((int) $p[2], (int) $p[3], (int) $p[1]) ? "{$p[1]}-{$p[2]}-{$p[3]}" : null;
    }

    /** `YYYY-MM-DDTHH:MM:SS` → `HH:MM:SS` */
    private function saat(mixed $deger): ?string
    {
        $metin = $this->metin($deger);

        return $metin !== null && preg_match('/T(\d{2}:\d{2}:\d{2})/', $metin, $p) === 1 ? $p[1] : null;
    }

    /**
     * Protel tutarı JSON sayısıdır (`1786.0`, `401.98`); ondalık, istemcinin
     * verdiği yanıt metninden okunur, float'a hiç çevrilmez (PRT-R8). Tırnaklı
     * metin (`"0"`) tutar değildir (PRT-R12). 4 basamağa yuvarlanır (yarım
     * sıfırdan uzağa), sondaki sıfırlar atılır. Saklama sözleşmesi
     * `numeric(20,4)`: en çok 16 tam basamak — yuvarlamadan SONRA denetlenir;
     * aşan değer null: veri hatası, toplu yazıma ulaşmaz (PRT-R5).
     */
    private function tutar(mixed $deger): ?string
    {
        $metin = match (true) {
            is_int($deger) => (string) $deger,
            $deger instanceof JsonSayisi => $this->duzOndalik($deger->metin),
            default => null,
        };

        if ($metin === null) {
            return null;
        }

        // bcadd sonucu ölçeğe KESER: yarım eklemek yuvarlamadır
        $yuvarlak = bcadd($metin, str_starts_with($metin, '-') ? '-0.00005' : '0.00005', 4);
        $yuvarlak = rtrim(rtrim($yuvarlak, '0'), '.');
        $yuvarlak = $yuvarlak === '-0' ? '0' : $yuvarlak;

        return strlen(explode('.', ltrim($yuvarlak, '-'))[0]) <= 16 ? $yuvarlak : null;
    }

    /**
     * JSON sayı belirtecini (`-1.5e3`, `12.30`, `4E-2`) float'a uğratmadan düz
     * ondalığa çevirir (PRT-R13). Üs, virgülü kaydırmaktır: basamaklar metin
     * olarak kaydırılır (`bcpow` yok, dev üs bellek tüketmez). Virgülün yeri
     * üs ile katsayının basamakları BİRLİKTE belirler (`0.000…1e10000` = 1):
     * yer, üs tam sayıya çevrilmeden bcmath ile hesaplanır, ancak sınır
     * içindeyse tam sayı olur (PRT-R15). 16'dan çok tam basamaklı değer
     * saklamaya sığmaz (yuvarlama büyütür, küçültmez): null. 10 ondalık
     * basamaktan küçük değer 4 basamakta sıfırdır: `0`.
     */
    private function duzOndalik(string $sayi): ?string
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?)(\d+))?$/D', $sayi, $p) !== 1) {
            return null;
        }

        $basamaklar = $p[2].($p[3] ?? '');
        $anlamli = ltrim($basamaklar, '0');
        if ($anlamli === '') {
            return '0';
        }

        // değer = 0.<anlamli> × 10^nokta; nokta = tam basamak − baştaki sıfırlar + üs
        $us = (($p[4] ?? '') === '-' ? '-' : '').(ltrim($p[5] ?? '', '0') ?: '0');
        $nokta = bcadd((string) (strlen($p[2]) - (strlen($basamaklar) - strlen($anlamli))), $us, 0);

        if (bccomp($nokta, '16', 0) > 0) {
            return null;
        }

        if (bccomp($nokta, '-10', 0) < 0) {
            return '0';
        }

        $nokta = (int) $nokta;

        $duz = match (true) {
            $nokta <= 0 => '0.'.str_repeat('0', -$nokta).$anlamli,
            $nokta >= strlen($anlamli) => $anlamli.str_repeat('0', $nokta - strlen($anlamli)),
            default => substr($anlamli, 0, $nokta).'.'.substr($anlamli, $nokta),
        };

        return $p[1].$duz;
    }

    /** Protel bayrakları 0/1 tam sayıdır */
    private function bayrak(mixed $deger): ?bool
    {
        return match ($deger) {
            1, true => true,
            0, false => false,
            default => null,
        };
    }

    private function kisalt(?string $deger, int $sinir): ?string
    {
        return $deger === null ? null : mb_substr($deger, 0, $sinir);
    }

    private function doluMetin(mixed $deger): bool
    {
        return is_string($deger) && trim($deger) !== '';
    }

    private function fazlaUzun(mixed $deger, int $sinir): bool
    {
        $metin = $this->metin($deger);

        return $metin !== null && mb_strlen($metin) > $sinir;
    }

    private function metin(mixed $deger): ?string
    {
        if (is_int($deger)) {
            return (string) $deger;
        }

        return is_string($deger) && trim($deger) !== '' ? trim($deger) : null;
    }
}
