<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Gelen İzibiz faturalarının UBL'ini İzibiz'den okur, rapor verilerini
 * (tutarlar, kur, vergi dairesi, vergi kalemleri, istisna kodu) tablolara
 * yazar (UblOkuyucu + EFaturaUblKaydi). XML saklanmaz.
 *
 * Kaynak YALNIZ İzibiz (kullanıcı kararı 2026-11-26): ERP'nin sakladığı UBL
 * kopyasına bakılmaz.
 *
 * İzibiz'i yormamak için (kullanıcı kuralı: fatura başına istek yok):
 * - toplu indirme (partide en çok 100 fatura), her çalışmada sınırlı istek;
 * - her fatura BİR kez (UBL değişmez);
 * - İzibiz sorunlu bir fatura yüzünden partiyi 10008 ile reddederse parti
 *   ikiye bölünür (istek bütçesinden düşer); tek faturada da olmazsa o fatura
 *   bir gün sonra yeniden denenir, AZAMI_HATA denemeden sonra bırakılır.
 *
 * Yalnız OKUMA: okundu bayrakları değişmez (test hesabında ölçüldü).
 */
final class IzibizUblServisi
{
    private const AZAMI_HATA = 3;

    /** ZIP içindeki tek UBL için üst sınır (en büyük gerçek UBL ~1,5 MB; 2026-09-24 ölçümü) */
    private const AZAMI_UBL_BAYT = 20 * 1024 * 1024;

    public function __construct(
        private readonly IzibizIstemcisi $istemci,
        private readonly UblOkuyucu $okuyucu,
        private readonly EFaturaUblKaydi $kayit,
    ) {}

    /**
     * @return array{istek: int, okunan: int, hatali: int}
     */
    public function tazele(EntegratorBaglanti $tanim, int $partiBoyutu, int $azamiIstek): array
    {
        $partiBoyutu = max(1, min($partiBoyutu, EntegratorBaglanti::ENCOK_SAYFA_BOYUTU));
        $sonuc = ['istek' => 0, 'okunan' => 0, 'hatali' => 0];

        /** @var list<array{id: int, kaynak_id: int, ettn: string}> $adaylar */
        $adaylar = EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('ubl_okundu')
            ->where('ubl_hata', '<', self::AZAMI_HATA)
            ->where(fn ($q) => $q->whereNull('ubl_son_deneme')
                ->orWhere('ubl_son_deneme', '<', CarbonImmutable::now()->subDay()))
            ->orderByDesc('id')
            ->limit($partiBoyutu * $azamiIstek)
            ->get(['id', 'kaynak_id', 'ettn'])
            ->map(fn (EFatura $f): array => ['id' => $f->id, 'kaynak_id' => $f->kaynak_id, 'ettn' => $f->ettn])
            ->all();

        // İşlenecek partiler yığını; 10008'de ikiye bölünen parti öne eklenir
        $partiler = array_chunk($adaylar, $partiBoyutu);

        while ($partiler !== [] && $sonuc['istek'] < $azamiIstek) {
            $parti = array_shift($partiler);
            $sonuc['istek']++;

            try {
                $zip = $this->istemci->ublIndir($tanim, FaturaYonu::Gelen, array_column($parti, 'kaynak_id'));
            } catch (EntegratorHatasi $hata) {
                // Erişim/kimlik sorunu: bu çalışma durur, bir sonraki yeniden dener
                if ($hata->kod !== EntegratorHatasi::HATA || $hata->saglayiciHttpDurumu !== 400) {
                    throw $hata;
                }

                if (count($parti) > 1) {
                    $yari = (int) ceil(count($parti) / 2);
                    array_unshift($partiler, array_slice($parti, 0, $yari), array_slice($parti, $yari));
                } else {
                    $this->kayit->hataliIsaretle(array_column($parti, 'id'));
                    $sonuc['hatali']++;
                }

                continue;
            }

            $veriler = $this->ayikla($zip);

            foreach ($parti as $fatura) {
                $veri = $veriler[mb_strtolower($fatura['ettn'])] ?? null;

                // Zipte gelmedi ya da okunamadı: bir gün sonra yeniden denenir
                if ($veri === null) {
                    $this->kayit->hataliIsaretle([$fatura['id']]);
                    $sonuc['hatali']++;

                    continue;
                }

                $this->kayit->yaz($fatura['id'], $veri);
                $sonuc['okunan']++;
            }
        }

        return $sonuc;
    }

    /**
     * Zipteki her okunabilen UBL, küçük harf ETTN => veri.
     *
     * @return array<string, UblVerisi>
     */
    public function ayikla(string $zipIcerigi): array
    {
        $yol = tempnam(sys_get_temp_dir(), 'izibiz-ubl-');
        if ($yol === false) {
            throw new RuntimeException('Geçici dosya açılamadı.');
        }

        try {
            file_put_contents($yol, $zipIcerigi);
            $zip = new ZipArchive;
            if ($zip->open($yol) !== true) {
                throw EntegratorHatasi::yanitGecersiz();
            }

            $veriler = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $boyut = $zip->statIndex($i)['size'] ?? 0;
                $veri = $boyut <= self::AZAMI_UBL_BAYT ? $this->okuyucu->oku((string) $zip->getFromIndex($i)) : null;

                if ($veri !== null) {
                    $veriler[$veri->ettn] = $veri;
                } else {
                    Log::warning('İzibiz UBL okunamadı', ['dosya' => $zip->getNameIndex($i)]);
                }
            }
            $zip->close();

            return $veriler;
        } finally {
            @unlink($yol);
        }
    }
}
