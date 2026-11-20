<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Saklama kurallarına göre temizlik (BY-010; README §6.2). `--onizleme`
 * silmeden "bu kurallarla şunlar silinecek" listesini yazar. Gerçek
 * çalıştırma modül kapalıyken hiçbir şey silmez ve yedekleme kilidini alır.
 */
#[Signature('yedek:temizle {--onizleme : Silmeden yalnız listele}')]
#[Description('Yedekleri saklama kurallarına göre temizler')]
final class YedekTemizle extends Command
{
    public function handle(SaklamaPolitikasi $saklama, YedeklemeYapilandirmasi $yapilandirma): int
    {
        if ($this->option('onizleme')) {
            $hesap = $saklama->hesapla();
            $this->yaz($hesap['silinecek'], $hesap['temkin']);

            return self::SUCCESS;
        }

        if (! $yapilandirma->aktif()) {
            return self::SUCCESS;
        }

        $kilit = $yapilandirma->islemDurumu()->kilit();

        try {
            if (! $kilit->bekleyerekAl((float) $yapilandirma->sureSiniri('kilit_bekleme'))) {
                $this->warn('Başka bir yedekleme işlemi sürüyor; temizlik atlandı.');

                return self::SUCCESS;
            }

            $sonuc = $saklama->uygula();
        } catch (Throwable $hata) {
            $this->error('Temizlik çalışmadı: '.$hata->getMessage());

            return self::FAILURE;
        } finally {
            $kilit->birak();
        }

        $this->line(sprintf('Temizlik: %d bağ kaldırıldı, %d dosya silindi%s', $sonuc['bag'], $sonuc['dosya'],
            $sonuc['atlanan_hedefler'] === [] ? '' : '; erişilemeyen hedefler atlandı: '.implode(', ', $sonuc['atlanan_hedefler'])));
        foreach ($sonuc['bilgi'] as $bilgi) {
            $this->line('Bilgi: '.$bilgi);
        }

        return $sonuc['atlanan_hedefler'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<SilmeKarari>  $kararlar
     */
    private function yaz(array $kararlar, ?string $temkin): void
    {
        if ($temkin !== null) {
            $this->warn("Hiçbir şey silinmeyecek ({$temkin}).");

            return;
        }

        if ($kararlar === []) {
            $this->line('Silinecek yedek yok.');

            return;
        }

        $this->table(['Tür', 'Yedek', 'Hedef', 'Plan', 'Neden'], array_map(fn (SilmeKarari $k): array => [
            $k->tur, $k->yedekId, $k->hedefId ?? 'yedek klasörü', $k->planId ?? '-', $k->neden,
        ], $kararlar));
    }
}
