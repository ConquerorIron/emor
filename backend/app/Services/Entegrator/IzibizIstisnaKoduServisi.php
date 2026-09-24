<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Gelen faturanın vergi istisna kodunu İzibiz'deki UBL'inden okur (kullanıcı
 * kararı 2026-09-24: entegratördeki bilgi esas). İzibiz liste yanıtında bu alan
 * yok; kod yalnız UBL'de (`cac:TaxCategory/cbc:TaxExemptionReasonCode`).
 *
 * İzibiz'i yormamak için (kullanıcı kuralı: fatura başına istek yok):
 * - toplu indirme (partide en çok 100 fatura), her çalışmada sınırlı istek;
 * - yalnız istisna olabilecek faturalar (tip ISTISNA/IHRACKAYITLI/OZELMATRAH ya
 *   da vergisi sıfır) ve her fatura BİR kez (UBL değişmez);
 * - İzibiz sorunlu bir fatura yüzünden partiyi 10008 ile reddederse parti
 *   ikiye bölünür (istek bütçesinden düşer); tek faturada da olmazsa o fatura
 *   bir gün sonra yeniden denenir, AZAMI_HATA denemeden sonra bırakılır.
 *
 * Yalnız OKUMA: okundu bayrakları değişmez (test hesabında ölçüldü).
 */
final class IzibizIstisnaKoduServisi
{
    private const ISTISNA_TIPLERI = ['ISTISNA', 'IHRACKAYITLI', 'OZELMATRAH'];

    private const AZAMI_HATA = 3;

    public function __construct(
        private readonly IzibizIstemcisi $istemci,
    ) {}

    /**
     * @return array{istek: int, okunan: int, kodlu: int, hatali: int}
     */
    public function tazele(EntegratorBaglanti $tanim, int $partiBoyutu, int $azamiIstek): array
    {
        $partiBoyutu = max(1, min($partiBoyutu, EntegratorBaglanti::ENCOK_SAYFA_BOYUTU));
        $sonuc = ['istek' => 0, 'okunan' => 0, 'kodlu' => 0, 'hatali' => 0];

        /** @var list<array{id: int, kaynak_id: int, ettn: string}> $adaylar */
        $adaylar = $this->adaylar($tanim)
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
                    $this->hataliIsaretle($parti);
                    $sonuc['hatali']++;
                }

                continue;
            }

            $kodlar = $this->kodlariAyikla($zip);
            $simdi = CarbonImmutable::now();

            foreach ($parti as $fatura) {
                $ettn = mb_strtolower($fatura['ettn']);

                if (! array_key_exists($ettn, $kodlar)) {
                    // Zipte gelmedi: okunamadı say, yeniden denenir
                    $this->hataliIsaretle([$fatura]);
                    $sonuc['hatali']++;

                    continue;
                }

                EFatura::query()->whereKey($fatura['id'])->update([
                    'izibiz_istisna_kodu' => $kodlar[$ettn],
                    'izibiz_ubl_okundu' => $simdi,
                ]);
                $sonuc['okunan']++;
                $sonuc['kodlu'] += $kodlar[$ettn] !== null ? 1 : 0;
            }
        }

        return $sonuc;
    }

    /**
     * @return Builder<EFatura>
     */
    private function adaylar(EntegratorBaglanti $tanim): Builder
    {
        return EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', FaturaYonu::Gelen->value)
            ->whereNull('izibiz_ubl_okundu')
            ->where('izibiz_ubl_hata', '<', self::AZAMI_HATA)
            ->where(fn (Builder $q) => $q->whereNull('izibiz_ubl_son_deneme')
                ->orWhere('izibiz_ubl_son_deneme', '<', CarbonImmutable::now()->subDay()))
            ->where(fn (Builder $q) => $q->whereIn('fatura_tipi', self::ISTISNA_TIPLERI)
                ->orWhere('vergi_tutari', 0));
    }

    /**
     * @param  list<array{id: int, kaynak_id: int, ettn: string}>  $faturalar
     */
    private function hataliIsaretle(array $faturalar): void
    {
        EFatura::query()->whereKey(array_column($faturalar, 'id'))->update(['izibiz_ubl_son_deneme' => CarbonImmutable::now()]);
        EFatura::query()->whereKey(array_column($faturalar, 'id'))->increment('izibiz_ubl_hata');
    }

    /**
     * Zipteki her UBL için küçük harf ETTN => istisna kodları (virgülle, yoksa null).
     *
     * @return array<string, string|null>
     */
    public function kodlariAyikla(string $zipIcerigi): array
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

            $kodlar = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $xml = (string) $zip->getFromIndex($i);
                [$ettn, $kod] = $this->ublOku($xml);

                if ($ettn !== null) {
                    $kodlar[$ettn] = $kod;
                } else {
                    Log::warning('İzibiz UBL okunamadı', ['dosya' => $zip->getNameIndex($i)]);
                }
            }
            $zip->close();

            return $kodlar;
        } finally {
            @unlink($yol);
        }
    }

    /**
     * @return array{0: string|null, 1: string|null} [küçük harf ETTN, virgülle kodlar]
     */
    private function ublOku(string $xml): array
    {
        $belge = new DOMDocument;
        // Dış varlık yüklenmez (XXE); ağ erişimi kapalı
        if (! @$belge->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return [null, null];
        }

        $xpath = new DOMXPath($belge);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        // Belgenin kendi ETTN'i kök altındaki cbc:UUID (referans belgelerinkiler değil)
        $ettn = trim((string) $xpath->evaluate('string(/*/cbc:UUID)'));

        $kodlar = [];
        foreach ($xpath->query('//cac:TaxCategory/cbc:TaxExemptionReasonCode') ?: [] as $dugum) {
            $kod = trim($dugum->textContent);
            if ($kod !== '') {
                $kodlar[$kod] = true;
            }
        }

        return [
            $ettn !== '' ? mb_strtolower($ettn) : null,
            $kodlar === [] ? null : mb_substr(implode(',', array_keys($kodlar)), 0, 100),
        ];
    }
}
