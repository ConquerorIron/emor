<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ERP'den tanım aktarımının ortak akışı (Projeler, Firmalar).
 *
 * ERP satırları önce önizlenir: her satırın aktarımda ne olacağı (durum)
 * hesaplanır. Sonra yalnız seçilen GUID'ler, ERP'nin O ANKİ verisiyle
 * uygulanır (istemcinin gönderdiği değerlere güvenilmez). ERP'de artık
 * olmayan kayıt silinmez.
 *
 * Alt sınıf ERP'yi okur (erpSatirlari), durumu hesaplar (planla) ve
 * uygulanabilir satırı yazar (uygula). Önizleme satırında en az sira (ERP
 * sırası, satır anahtarı), guid, durum, neden ve kayit_id (eşleşen kayıt;
 * yalnız sunucu içi) bulunur.
 */
abstract class ErpAktarimi
{
    /** ERP'de var, uygulamada yok: eklenir */
    public const YENI = 'yeni';

    /** Aynı GUID'li kaydın bilgileri ERP'de değişmiş: güncellenir */
    public const GUNCELLENECEK = 'guncellenecek';

    /** Elle açılmış kayıt ERP kaydına bağlanır */
    public const ESLESECEK = 'eslesecek';

    /** Değişiklik yok */
    public const AYNI = 'ayni';

    /** Benzersiz bir değer uygulamada başka bir kayıtta: aktarılamaz */
    public const CAKISMA = 'cakisma';

    /** ERP satırı aktarılamaz (neden alanında) */
    public const GECERSIZ = 'gecersiz';

    /** Uygulanan durum => sonuç sayacı */
    private const SAYACLAR = [
        self::YENI => 'eklenen',
        self::GUNCELLENECEK => 'guncellenen',
        self::ESLESECEK => 'eslesen',
    ];

    /**
     * ERP'den okunan ham satırlar.
     *
     * @return list<array<string, string|null>>
     */
    abstract protected function erpSatirlari(): array;

    /**
     * @param  list<array<string, string|null>>  $erpSatirlari
     * @return list<array<string, mixed>>
     */
    abstract protected function planla(array $erpSatirlari): array;

    /**
     * Yalnız YENI, GUNCELLENECEK ve ESLESECEK satırlar için çağrılır.
     *
     * @param  array<string, mixed>  $satir
     */
    abstract protected function uygula(array $satir): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function onizle(): array
    {
        return $this->planla($this->erpSatirlari());
    }

    /**
     * @param  list<string>  $guidler
     * @return array{eklenen: int, guncellenen: int, eslesen: int, atlanan: int}
     */
    public function aktar(array $guidler): array
    {
        // ERP okuması işlemin dışında: PostgreSQL işlemi dış sorgu boyunca açık kalmaz
        $erpSatirlari = $this->erpSatirlari();
        $secili = array_flip(array_map(mb_strtolower(...), $guidler));

        return DB::transaction(function () use ($erpSatirlari, $secili): array {
            $sonuc = ['eklenen' => 0, 'guncellenen' => 0, 'eslesen' => 0, 'atlanan' => 0];

            foreach ($this->planla($erpSatirlari) as $satir) {
                if (! isset($secili[$satir['guid']]) || ! array_key_exists($satir['durum'], self::SAYACLAR)) {
                    continue;
                }

                $this->uygula($satir);
                $sonuc[self::SAYACLAR[$satir['durum']]]++;
            }

            // Seçildiği hâlde uygulanmayanlar: bu arada güncel hâle gelmiş, artık geçersiz ya da ERP'den kalkmış
            $sonuc['atlanan'] = count($secili) - $sonuc['eklenen'] - $sonuc['guncellenen'] - $sonuc['eslesen'];

            return $sonuc;
        });
    }

    /** ERP GUID'i karşılaştırılabilir biçimde: süslü parantez ve boşluk atılır, küçük harf. */
    protected static function guid(?string $deger): string
    {
        return mb_strtolower(trim((string) $deger, " \t\n\r\0\x0B{}"));
    }

    protected static function gecerliGuid(string $guid): bool
    {
        return Str::isUuid($guid);
    }

    /** Kırpılmış değer; boşsa null. */
    protected static function bosIseNull(?string $deger): ?string
    {
        $deger = trim((string) $deger);

        return $deger === '' ? null : $deger;
    }
}
