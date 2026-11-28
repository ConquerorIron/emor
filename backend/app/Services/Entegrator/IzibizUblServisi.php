<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
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
 * - İzibiz sorunlu bir fatura yüzünden partiyi HTTP 400 + kod 10008 ile
 *   reddederse parti ikiye bölünür (istek bütçesinden düşer); tek faturada da
 *   olmazsa o fatura "okunamadı" sayılır. Başka her hata (başka 400, erişim,
 *   okunamayan zip, giriş) turu durdurur — yeniden deneme ve tur kesme
 *   kararları UblOkumaTuru / EFaturaUblKaydi'de (iki sağlayıcıda ortak).
 *
 * Yalnız OKUMA: okundu bayrakları değişmez (test hesabında ölçüldü).
 */
final class IzibizUblServisi
{
    /** İzibiz'in "partide sorunlu fatura var" kodu: yalnız bunda parti bölünür (C-2) */
    private const SORUNLU_FATURA_KODU = '10008';

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
        $tur = new UblOkumaTuru($this->kayit);

        /** @var list<array{id: int, kaynak_id: int, ettn: string}> $adaylar */
        $adaylar = $this->kayit->adaylar($tanim, $partiBoyutu * $azamiIstek)
            ->get(['id', 'kaynak_id', 'ettn'])
            ->map(fn (EFatura $f): array => ['id' => $f->id, 'kaynak_id' => $f->kaynak_id, 'ettn' => $f->ettn])
            ->all();

        if ($adaylar === []) {
            return $tur->bitir();
        }

        // Giriş hatası döngüden önce: hiçbir fatura işaretlenmeden tur kesilir (C-3)
        $this->istemci->token($tanim);

        // İşlenecek partiler yığını; 10008'de ikiye bölünen parti öne eklenir
        $partiler = array_chunk($adaylar, $partiBoyutu);

        while ($partiler !== [] && $tur->istekSayisi() < $azamiIstek) {
            $parti = array_shift($partiler);
            $idler = array_column($parti, 'id');
            $tur->istekYapildi();

            try {
                $veriler = $this->ayikla($this->istemci->ublIndir($tanim, FaturaYonu::Gelen, array_column($parti, 'kaynak_id')));
            } catch (EntegratorHatasi $hata) {
                if (! $this->sorunluFaturaMi($hata)) {
                    $tur->durdur($hata, $idler);
                }

                if (count($parti) > 1) {
                    $yari = (int) ceil(count($parti) / 2);
                    array_unshift($partiler, array_slice($parti, 0, $yari), array_slice($parti, $yari));
                } else {
                    $tur->okunamadi($idler);
                }

                continue;
            }

            // Önce okunanlar: partinin başındaki eksikler okunabilen faturaları devre kesiciye takmasın
            $eksikler = [];
            foreach ($parti as $fatura) {
                $veri = $veriler[mb_strtolower($fatura['ettn'])] ?? null;

                if ($veri === null) {
                    $eksikler[] = $fatura['id'];
                } else {
                    $tur->okundu($fatura['id'], $veri);
                }
            }

            // Zipte gelmedi ya da okunamadı: bir gün sonra yeniden denenir
            if ($eksikler !== []) {
                $tur->okunamadi($eksikler);
            }
        }

        return $tur->bitir();
    }

    /**
     * Zipteki her okunabilen UBL, küçük harf ETTN => veri. Okunamayan girdi
     * (klasör, boş dosya, bozuk XML) atlanır; zip açılamazsa YANIT_GECERSIZ.
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
                $veri = $this->girdiyiOku($zip, $i);

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

    /** Tek girdinin hatası (zehirli belge) zipin geri kalanını düşürmez (C-4). */
    private function girdiyiOku(ZipArchive $zip, int $sira): ?UblVerisi
    {
        if (($zip->statIndex($sira)['size'] ?? 0) > self::AZAMI_UBL_BAYT) {
            return null;
        }

        try {
            return $this->okuyucu->oku((string) $zip->getFromIndex($sira));
        } catch (Throwable) {
            return null;
        }
    }

    /** Partideki tek bir faturanın sorunu: yalnız HTTP 400 + 10008, giriş adımından değil. */
    private function sorunluFaturaMi(EntegratorHatasi $hata): bool
    {
        return ! $hata->girisHatasi
            && $hata->kod === EntegratorHatasi::HATA
            && $hata->saglayiciHttpDurumu === 400
            && $hata->saglayiciKodu === self::SORUNLU_FATURA_KODU;
    }
}
