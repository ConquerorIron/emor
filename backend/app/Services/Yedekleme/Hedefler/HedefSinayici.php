<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use Throwable;

/**
 * "Bağlantıyı Sına" (README §4.6): yalnız bağlanmayı değil yaz – oku –
 * doğrula – sil döngüsünü sınar. Her adım ayrı raporlanır; bir adım
 * başarısızsa sonrakiler "atlandı" olur (silme yine denenir: hedefte sınama
 * dosyası kalmasın).
 */
final class HedefSinayici
{
    /**
     * @return list<array{adim: string, basarili: bool|null, kod: string|null, mesaj: string|null}>
     */
    public function sina(HedefBaglayicisi $baglayici): array
    {
        $adimlar = [];
        $ekle = function (string $adim, ?bool $basarili, ?Throwable $hata = null) use (&$adimlar): void {
            $adimlar[] = [
                'adim' => $adim,
                'basarili' => $basarili,
                'kod' => $hata instanceof HedefHatasi ? $hata->kod : ($hata === null ? null : HedefHatasi::ERISILEMEDI),
                'mesaj' => $hata instanceof HedefHatasi ? $hata->getMessage() : null,
            ];
        };

        $klasor = sys_get_temp_dir().'/emor-hedef-sinama-'.bin2hex(random_bytes(6));
        mkdir($klasor, 0700);
        $kaynak = $klasor.'/kaynak';
        $geri = $klasor.'/geri';
        $icerik = random_bytes(4096);
        file_put_contents($kaynak, $icerik);
        $ad = 'erp_sinama_'.bin2hex(random_bytes(6)).'.dump';
        $uzak = null;

        try {
            try {
                $baglayici->baglan();
                $ekle('baglan', true);
            } catch (Throwable $hata) {
                $ekle('baglan', false, $hata);
                foreach (['yaz', 'oku', 'dogrula', 'sil'] as $kalan) {
                    $ekle($kalan, null);
                }

                return $adimlar;
            }

            try {
                $uzak = $baglayici->yaz($kaynak, $ad);
                $ekle('yaz', true);
            } catch (Throwable $hata) {
                $ekle('yaz', false, $hata);
            }

            if ($uzak !== null) {
                try {
                    $baglayici->oku($uzak, $geri);
                    $ekle('oku', true);
                    $ayni = is_file($geri) && hash_equals(hash('sha256', $icerik), (string) hash_file('sha256', $geri));
                    $ekle('dogrula', $ayni, $ayni ? null : new HedefHatasi(HedefHatasi::OKUNAMADI, 'Hedeften okunan dosya yazılanla aynı değil.'));
                } catch (Throwable $hata) {
                    $ekle('oku', false, $hata);
                    $ekle('dogrula', null);
                }

                try {
                    $baglayici->sil($uzak);
                    $ekle('sil', true);
                } catch (Throwable $hata) {
                    $ekle('sil', false, $hata);
                }
            } else {
                foreach (['oku', 'dogrula', 'sil'] as $kalan) {
                    $ekle($kalan, null);
                }
            }

            return $adimlar;
        } finally {
            @unlink($kaynak);
            @unlink($geri);
            @rmdir($klasor);
        }
    }

    /**
     * @param  list<array{basarili: bool|null}>  $adimlar
     */
    public static function basariliMi(array $adimlar): bool
    {
        return array_filter($adimlar, fn (array $a): bool => $a['basarili'] !== true) === [];
    }
}
