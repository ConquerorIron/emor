<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * İzibiz'den gelen/giden e-Fatura listesini okur (EFAT-07). Yalnız GET yapar:
 * okundu / ERP okundu bayraklarını değiştiren uçlar (`erp-read-flag`,
 * `portal-read-flag`) ÇAĞRILMAZ — ERP yeni faturaları `erpReadFlag` ile alıyor.
 *
 * Sözleşme (docs/izibiz/api-notlari.md): `GET /v1/einvoices/{inbox|outbox}`,
 * `startDate`/`endDate` dahil, `page` 0'dan, `data.pageable.totalElements`.
 */
final class IzibizFaturaKaynagi
{
    public const TARIH_BELGE = 'DOCUMENT';

    public const TARIH_ULASMA = 'DELIVERY';

    public function __construct(
        private readonly IzibizIstemcisi $istemci,
    ) {}

    public function oku(
        EntegratorBaglanti $tanim,
        FaturaYonu $yon,
        CarbonImmutable $baslangic,
        CarbonImmutable $bitis,
        string $tarihTuru = self::TARIH_BELGE,
    ): FaturaOkumaSonucu {
        $this->araligiDogrula($baslangic, $bitis, $tarihTuru);

        $basladi = CarbonImmutable::now();
        $azamiSayfa = (int) config('entegrator.izibiz.azami_sayfa');
        $sayfaBoyutu = (int) config('entegrator.izibiz.sayfa_boyutu');

        $sayfa = 0;
        $toplamSayfa = 0;
        $beklenenAdet = 0;
        /** @var array<int, EntegratorFatura> $faturalar kaynak ID'sine göre tekil */
        $faturalar = [];
        $hataliKayitlar = [];
        $hataliAdet = 0;
        $eksikNedeni = null;

        do {
            if ($sayfa >= $azamiSayfa) {
                $eksikNedeni = FaturaOkumaSonucu::EKSIK_SAYFA_SINIRI;
                break;
            }

            $govde = $this->istemci->getJson($tanim, '/v1/einvoices/'.$yon->izibizKutusu(), [
                'dateType' => $tarihTuru,
                'startDate' => $baslangic->toDateString(),
                'endDate' => $bitis->toDateString(),
                'page' => $sayfa,
                'pageSize' => $sayfaBoyutu,
                // Kararlı sıra: yeni eklenen kayıtlar sona düşer
                'sort' => 'asc',
                'sortProperty' => 'id',
            ]);

            [$kayitlar, $sayfaToplami, $adet] = $this->sayfayiAc($govde);

            if ($sayfa === 0) {
                $toplamSayfa = $sayfaToplami;
                $beklenenAdet = $adet;
            }

            foreach ($kayitlar as $ham) {
                $sonuc = $this->cevir($yon, $ham);

                if ($sonuc instanceof EntegratorFatura) {
                    $faturalar[$sonuc->kaynakId] ??= $sonuc;
                } else {
                    $hataliKayitlar[] = $sonuc;
                    $hataliAdet++;
                }
            }

            $sayfa++;
        } while ($sayfa < $toplamSayfa);

        if ($eksikNedeni === null && $hataliKayitlar !== []) {
            $eksikNedeni = FaturaOkumaSonucu::EKSIK_VERI_HATASI;
        }

        // Sayfalar arasında kayıt eklenip silinirse ya da aynı kayıt iki sayfada
        // görünürse okunan tekil adet beklenenle tutmaz: kesin sonuç sayılmaz
        if ($eksikNedeni === null && count($faturalar) + $hataliAdet !== $beklenenAdet) {
            $eksikNedeni = FaturaOkumaSonucu::EKSIK_SAYIM_UYUSMUYOR;
        }

        return new FaturaOkumaSonucu(
            yon: $yon,
            tarihTuru: $tarihTuru,
            baslangic: $baslangic,
            bitis: $bitis,
            faturalar: array_values($faturalar),
            beklenenAdet: $beklenenAdet,
            okunanSayfa: $sayfa,
            hataliKayitlar: $hataliKayitlar,
            eksikNedeni: $eksikNedeni,
            basladi: $basladi,
            bitti: CarbonImmutable::now(),
        );
    }

    private function araligiDogrula(CarbonImmutable $baslangic, CarbonImmutable $bitis, string $tarihTuru): void
    {
        if (! in_array($tarihTuru, [self::TARIH_BELGE, self::TARIH_ULASMA], true)) {
            throw new InvalidArgumentException("Geçersiz tarih türü: {$tarihTuru}");
        }

        if ($bitis->lt($baslangic->startOfDay())) {
            throw new InvalidArgumentException('Bitiş tarihi başlangıçtan önce olamaz.');
        }

        $azamiGun = (int) config('entegrator.izibiz.azami_gun');

        if ($baslangic->startOfDay()->diffInDays($bitis->startOfDay()) + 1 > $azamiGun) {
            throw new InvalidArgumentException("Tarih aralığı en çok {$azamiGun} gün olabilir.");
        }
    }

    /**
     * @param  array<string, mixed>  $govde
     * @return array{0: list<mixed>, 1: int, 2: int}
     */
    private function sayfayiAc(array $govde): array
    {
        $veri = $govde['data'] ?? null;
        $kayitlar = is_array($veri) ? ($veri['contents'] ?? null) : null;
        $sayfalama = is_array($veri) ? ($veri['pageable'] ?? null) : null;

        if (! is_array($kayitlar) || ! array_is_list($kayitlar) || ! is_array($sayfalama)
            || ! is_int($sayfalama['totalPages'] ?? null) || ! is_int($sayfalama['totalElements'] ?? null)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        return [$kayitlar, $sayfalama['totalPages'], $sayfalama['totalElements']];
    }

    /**
     * Ham kaydı ortak biçime çevirir; zorunlu alanı eksik/bozuk kayıt sessizce
     * düşürülmez, hata olarak döner.
     *
     * @return EntegratorFatura|array{kaynak_id: int|null, alanlar: list<string>}
     */
    private function cevir(FaturaYonu $yon, mixed $ham): EntegratorFatura|array
    {
        if (! is_array($ham)) {
            return ['kaynak_id' => null, 'alanlar' => ['kayit']];
        }

        $kaynakId = is_int($ham['id'] ?? null) && $ham['id'] > 0 ? $ham['id'] : null;
        $tutar = $this->tutar($ham['amount'] ?? null);
        $belgeTarihi = $ham['issueDate'] ?? null;
        $gonderici = is_array($ham['accountingSupplier'] ?? null) ? $ham['accountingSupplier'] : [];
        $alici = is_array($ham['accountingCustomer'] ?? null) ? $ham['accountingCustomer'] : [];
        $durum = is_array($ham['documentStatus'] ?? null) ? $ham['documentStatus'] : [];
        $zarf = is_array($ham['envelope'] ?? null) ? $ham['envelope'] : [];
        $vergiTutari = $this->tutar($ham['taxAmount'] ?? null);

        $hatalar = array_keys(array_filter([
            'id' => $kaynakId === null,
            'uuid' => ! $this->doluMetin($ham['uuid'] ?? null) || $this->fazlaUzun($ham['uuid'] ?? null, 36),
            'documentNo' => ! $this->doluMetin($ham['documentNo'] ?? null) || $this->fazlaUzun($ham['documentNo'] ?? null, 64),
            'issueDate' => ! is_string($belgeTarihi)
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $belgeTarihi) !== 1
                || ! checkdate((int) substr((string) $belgeTarihi, 5, 2), (int) substr((string) $belgeTarihi, 8, 2), (int) substr((string) $belgeTarihi, 0, 4)),
            'currency' => ! $this->doluMetin($ham['currency'] ?? null) || $this->fazlaUzun($ham['currency'] ?? null, 3),
            'amount' => $tutar === null,
            'taxAmount' => ($ham['taxAmount'] ?? null) !== null && $vergiTutari === null,
            'issueTime' => $this->fazlaUzun($ham['issueTime'] ?? null, 32),
            'invoiceType' => $this->fazlaUzun($ham['invoiceType'] ?? $ham['documentType'] ?? $ham['subType'] ?? null, 64),
            'profile' => $this->fazlaUzun($ham['profile'] ?? null, 64),
            'supplierSSN' => $this->fazlaUzun($gonderici['identifier'] ?? $ham['supplierSSN'] ?? null, 32),
            'customerSSN' => $this->fazlaUzun($alici['identifier'] ?? $ham['customerSSN'] ?? null, 32),
            'documentStatus' => $this->fazlaUzun($durum['value'] ?? $ham['invoiceStatus'] ?? $ham['statusCodeDesc'] ?? null, 64),
            'lineCount' => is_int($ham['lineCount'] ?? null) && ($ham['lineCount'] < 0 || $ham['lineCount'] > 4294967295),
            'gibStatusCode' => is_int($zarf['gibStatusCode'] ?? null) && ($zarf['gibStatusCode'] < -2147483648 || $zarf['gibStatusCode'] > 2147483647),
        ]));

        if ($hatalar !== []) {
            return ['kaynak_id' => $kaynakId, 'alanlar' => $hatalar];
        }

        return new EntegratorFatura(
            yon: $yon,
            kaynakId: $kaynakId,
            ettn: mb_strtolower(trim((string) $ham['uuid'])),
            belgeNo: trim((string) $ham['documentNo']),
            belgeTarihi: $belgeTarihi,
            belgeSaati: $this->metin($ham['issueTime'] ?? null),
            olusturmaZamani: $this->metin($ham['createDate'] ?? null),
            faturaTipi: $this->metin($ham['invoiceType'] ?? $ham['documentType'] ?? $ham['subType'] ?? null),
            senaryo: $this->metin($ham['profile'] ?? null),
            paraBirimi: trim((string) $ham['currency']),
            tutar: $tutar,
            vergiTutari: $vergiTutari,
            satirSayisi: is_int($ham['lineCount'] ?? null) ? $ham['lineCount'] : null,
            gondericiVkn: $this->metin($gonderici['identifier'] ?? $ham['supplierSSN'] ?? null),
            gondericiUnvan: $this->metin($gonderici['name'] ?? $ham['supplierName'] ?? null),
            aliciVkn: $this->metin($alici['identifier'] ?? $ham['customerSSN'] ?? null),
            aliciUnvan: $this->metin($alici['name'] ?? $ham['customerName'] ?? null),
            durum: $this->metin($durum['value'] ?? $ham['invoiceStatus'] ?? $ham['statusCodeDesc'] ?? null),
            durumAciklamasi: $this->metin($durum['label'] ?? $ham['statusDesc'] ?? null),
            gibDurumKodu: is_int($zarf['gibStatusCode'] ?? null) ? $zarf['gibStatusCode'] : null,
            gibDurumAciklamasi: $this->metin($zarf['gibStatusDescription'] ?? null),
            erpOkundu: is_bool($ham['erpReadFlag'] ?? null) ? $ham['erpReadFlag'] : null,
            okundu: is_bool($ham['readStatus'] ?? null) ? $ham['readStatus'] : null,
            yanitAciklamasi: $this->metin($ham['responseDescription'] ?? null),
            gondericiAdSoyad: $this->kisi($gonderici['person'] ?? null),
            aliciAdSoyad: $this->kisi($alici['person'] ?? null),
            gondericiEtiketi: $this->metin($gonderici['alias'] ?? $ham['supplierAlias'] ?? null),
            aliciEtiketi: $this->metin($alici['alias'] ?? $ham['customerAlias'] ?? null),
            irsaliyeNo: $this->liste($ham['despatchReference'] ?? null),
            siparisNo: $this->liste($ham['orderReference'] ?? null),
            siparisTarihi: $this->tarih($ham['orderReferenceDate'] ?? null),
            gtbRefNo: $this->metin($ham['gtbRefNo'] ?? null),
            gcbTescilNo: $this->metin($ham['gtbRegistrationNo'] ?? null),
            gcbTarihi: $this->kisalt($this->metin($ham['gtbExportDate'] ?? null), 32),
            portalNotu: $this->liste($ham['note'] ?? null),
            teslimRef: $this->metin($ham['deliveryRef'] ?? null),
            hariciAktarim: is_bool($ham['externalTransferFlag'] ?? null) ? $ham['externalTransferFlag'] : null,
            mailDurumu: $this->kisalt($this->metin($ham['mailStatus'] ?? null), 32),
        );
    }

    /**
     * İzibiz kişi adını "Ad Soyad" metni olarak verir; kişi değilse
     * "null null" gelir (Postman örneği) — bu durumda null.
     */
    private function kisi(mixed $deger): ?string
    {
        $metin = $this->metin($deger);
        if ($metin === null) {
            return null;
        }

        $parcalar = array_filter(preg_split('/\s+/', $metin) ?: [], fn (string $p): bool => $p !== 'null');

        return $parcalar === [] ? null : implode(' ', $parcalar);
    }

    /** Tek değer ya da liste (ör. birden çok irsaliye) — virgülle birleşir. */
    private function liste(mixed $deger): ?string
    {
        if (is_array($deger)) {
            $ogeler = array_values(array_filter(array_map($this->metin(...), $deger)));

            return $ogeler === [] ? null : implode(', ', $ogeler);
        }

        return $this->metin($deger);
    }

    /** `YYYY-MM-DD[T…]` → `YYYY-MM-DD`; geçersizse null (özet alanı, kaydı bozmaz). */
    private function tarih(mixed $deger): ?string
    {
        $metin = $this->metin($deger);
        if ($metin === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $metin, $p) !== 1) {
            return null;
        }

        return checkdate((int) $p[2], (int) $p[3], (int) $p[1]) ? "{$p[1]}-{$p[2]}-{$p[3]}" : null;
    }

    private function kisalt(?string $deger, int $sinir): ?string
    {
        return $deger === null ? null : mb_substr($deger, 0, $sinir);
    }

    /**
     * İzibiz tutarı Türkçe biçimli metindir (`"5.155.262,20"`): binlik `.`,
     * ondalık `,`. Ondalık metne (`"5155262.20"`) çevrilir; float kullanılmaz.
     * Tanınmayan biçim null döner (veri hatası).
     */
    private function tutar(mixed $deger): ?string
    {
        if (! is_string($deger)) {
            return null;
        }

        $deger = trim($deger);

        if (preg_match('/^(-?)(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d+))?$/', $deger, $parca) !== 1) {
            return null;
        }

        $tam = ltrim(str_replace('.', '', $parca[2]), '0');
        $kesir = $parca[3] ?? '';

        // PostgreSQL numeric(20,4): en çok 16 tam ve 4 kesir basamağı.
        if (strlen($tam) > 16 || strlen($kesir) > 4) {
            return null;
        }

        return $parca[1].($tam === '' ? '0' : $tam).($kesir === '' ? '' : '.'.$kesir);
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
