<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaSenkronServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizFaturaKaynagi;
use App\Services\EntegratorBaglantiServisi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Aktif entegratör hesabının e-Faturalarını PostgreSQL'e senkronlar (EFAT-10).
 *
 * Kullanım:
 * - Zamanlanmış artımlı:  efatura:senkron --gun=2 --tarih-turu=DELIVERY
 * - Günlük durum tazeleme: efatura:senkron --gun=45
 * - İlk tarama:            efatura:senkron --baslangic=2026-01-01 --tetikleyen=ilk_tarama
 *
 * Tarihler İstanbul takvim günüdür; aralık aylara bölünür.
 */
#[Signature('efatura:senkron
    {--gun= : Bugünden geriye kaç gün (bugün dahil)}
    {--baslangic= : Başlangıç tarihi (YYYY-MM-DD)}
    {--bitis= : Bitiş tarihi (YYYY-MM-DD; varsayılan bugün)}
    {--yon=hepsi : gelen | giden | hepsi}
    {--tarih-turu=DOCUMENT : DOCUMENT (belge tarihi) | DELIVERY (İzibiz\'e ulaşma)}
    {--tetikleyen=zamanlanmis : zamanlanmis | manuel | ilk_tarama}
    {--aralik-denetimi : Zamanlayıcı için: tanımdaki senkron aralığı dolmadıysa çalışmaz}')]
#[Description('Aktif entegratör hesabının e-Faturalarını senkronlar')]
final class EFaturaSenkron extends Command
{
    public function handle(EFaturaSenkronServisi $servis, EntegratorBaglantiServisi $baglantilar): int
    {
        if (! config('entegrator.izibiz.senkron_aktif')) {
            $this->warn('e-Fatura senkronu kapalı (EFATURA_SENKRON_AKTIF=false).');

            return self::SUCCESS;
        }

        $tanim = $baglantilar->aktif();

        if ($tanim === null) {
            $this->warn('Aktif entegratör ortamı yok; senkron atlandı.');

            return self::SUCCESS;
        }

        // Zamanlayıcı dakikada bir çağırır; asıl aralık ekrandan tanımlı (en az 15 dk)
        if ($this->option('aralik-denetimi') && ! $this->araligiDoldu($tanim)) {
            return self::SUCCESS;
        }

        try {
            [$baslangic, $bitis] = $this->aralik();
            $yonler = $this->yonler();
            $tarihTuru = $this->secenek('tarih-turu', [IzibizFaturaKaynagi::TARIH_BELGE, IzibizFaturaKaynagi::TARIH_ULASMA]);
            $tetikleyen = $this->secenek('tetikleyen', [
                EFaturaSenkronCalismasi::TETIKLEYEN_ZAMANLANMIS,
                EFaturaSenkronCalismasi::TETIKLEYEN_MANUEL,
                EFaturaSenkronCalismasi::TETIKLEYEN_ILK_TARAMA,
            ]);
        } catch (InvalidArgumentException $hata) {
            $this->error($hata->getMessage());

            return self::INVALID;
        }

        $basarisizVar = false;

        foreach ($yonler as $yon) {
            $calismalar = $servis->senkronEt($tanim, $yon, $baslangic, $bitis, $tetikleyen, $tarihTuru);

            if ($calismalar === []) {
                $this->warn("{$yon->value}: başka bir senkron sürüyor; atlandı.");
                $basarisizVar = true;

                continue;
            }

            foreach ($calismalar as $c) {
                $basarisizVar = $basarisizVar || $c->durum !== EFaturaSenkronCalismasi::DURUM_TAM;
                $this->line(sprintf(
                    '%s %s..%s [%s] %s — okunan %s/%s, yeni %s, güncellenen %s%s',
                    $yon->value,
                    $c->baslangic->toDateString(),
                    $c->bitis->toDateString(),
                    $tanim->ortam,
                    $c->durum,
                    $c->okunan_adet ?? '-',
                    $c->beklenen_adet ?? '-',
                    $c->yeni_adet ?? '-',
                    $c->guncellenen_adet ?? '-',
                    $c->eksik_nedeni !== null ? " (eksik: {$c->eksik_nedeni})" : ($c->hata_kodu !== null ? " (hata: {$c->hata_kodu})" : ''),
                ));
            }
        }

        return $basarisizVar ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function aralik(): array
    {
        $bugun = CarbonImmutable::now((string) config('entegrator.izibiz.saat_dilimi'))->startOfDay();
        $gun = $this->option('gun');
        $baslangic = $this->option('baslangic');

        if (($gun === null) === ($baslangic === null)) {
            throw new InvalidArgumentException('--gun veya --baslangic seçeneklerinden yalnız biri verilmeli.');
        }

        $bitis = $this->option('bitis') !== null ? $this->tarih((string) $this->option('bitis')) : $bugun;

        if ($gun !== null) {
            if (! ctype_digit((string) $gun) || (int) $gun < 1) {
                throw new InvalidArgumentException('--gun pozitif bir tam sayı olmalı.');
            }

            return [$bitis->subDays((int) $gun - 1), $bitis];
        }

        return [$this->tarih((string) $baslangic), $bitis];
    }

    /**
     * Son zamanlanmış artımlı (DELIVERY) çalışmanın üzerinden tanımdaki aralık
     * geçti mi. Dakikalık zamanlayıcının kaymasını önlemek için 1 dk pay.
     */
    private function araligiDoldu(EntegratorBaglanti $tanim): bool
    {
        $son = EFaturaSenkronCalismasi::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('tetikleyen', EFaturaSenkronCalismasi::TETIKLEYEN_ZAMANLANMIS)
            ->where('tarih_turu', IzibizFaturaKaynagi::TARIH_ULASMA)
            ->max('basladi');

        if ($son === null) {
            return true;
        }

        $aralik = max($tanim->senkron_araligi_dakika, EntegratorBaglanti::ENAZ_SENKRON_ARALIGI);

        return CarbonImmutable::parse($son)->addMinutes($aralik)->subMinute()->lte(CarbonImmutable::now());
    }

    private function tarih(string $deger): CarbonImmutable
    {
        $tarih = preg_match('/^\d{4}-\d{2}-\d{2}$/', $deger) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $deger)
            : false;

        if (! $tarih instanceof CarbonImmutable || $tarih->toDateString() !== $deger) {
            throw new InvalidArgumentException("Geçersiz tarih: {$deger} (YYYY-MM-DD).");
        }

        return $tarih;
    }

    /**
     * @return list<FaturaYonu>
     */
    private function yonler(): array
    {
        $yon = $this->secenek('yon', ['hepsi', 'gelen', 'giden']);

        return $yon === 'hepsi' ? FaturaYonu::cases() : [FaturaYonu::from($yon)];
    }

    /**
     * @param  list<string>  $izinli
     */
    private function secenek(string $ad, array $izinli): string
    {
        $deger = (string) $this->option($ad);

        if (! in_array($deger, $izinli, true)) {
            throw new InvalidArgumentException("--{$ad} şunlardan biri olmalı: ".implode(', ', $izinli));
        }

        return $deger;
    }
}
