<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\ErpBelgeArsivi;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Gelen faturanın vergi istisna kodunu entegratörden gelen UBL'den okur (kullanıcı
 * kararı 2026-09-24: entegratördeki bilgi esas). İzibiz liste yanıtında bu alan
 * yok; kod yalnız UBL'de (`cac:TaxCategory/cbc:TaxExemptionReasonCode`).
 *
 * Önce ERP havuzu: ERP entegratörden çektiği UBL'in aynısını TOHOM_E_FATURA.XML_KODU'nda
 * saklar; havuzdaki faturanın kodu oradan okunur, İzibiz'e gidilmez (kullanıcı
 * kararı 2026-09-24). Havuzda olmayan fatura ERP'ye çekmesi için süre tanındıktan
 * (istisna_erp_bekleme_saat) sonra İzibiz'den okunur. ERP'ye ulaşılamazsa bu
 * çalışmada doğrudan İzibiz'e gidilir.
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

    /** ERP sorgusu başına fatura: kodlu XML'ler (~320 KB) bellekte birlikte durur */
    private const ERP_PARTI = 50;

    /** ZIP içindeki tek UBL için üst sınır (en büyük gerçek UBL ~1,5 MB; 2026-09-24 ölçümü) */
    private const AZAMI_UBL_BAYT = 20 * 1024 * 1024;

    public function __construct(
        private readonly IzibizIstemcisi $istemci,
        private readonly ErpBelgeArsivi $erp,
    ) {}

    /**
     * @return array{erp_okunan: int, erp_kodlu: int, istek: int, okunan: int, kodlu: int, hatali: int}
     */
    public function tazele(EntegratorBaglanti $tanim, int $partiBoyutu, int $azamiIstek): array
    {
        $partiBoyutu = max(1, min($partiBoyutu, EntegratorBaglanti::ENCOK_SAYFA_BOYUTU));
        $sonuc = ['erp_okunan' => 0, 'erp_kodlu' => 0, 'istek' => 0, 'okunan' => 0, 'kodlu' => 0, 'hatali' => 0];
        $erpOkundu = $this->erpdenOku($tanim, $sonuc);
        $bekleme = CarbonImmutable::now()->subHours((int) config('efatura.istisna_erp_bekleme_saat'));

        /** @var list<array{id: int, kaynak_id: int, ettn: string}> $adaylar */
        $adaylar = $this->izibizAdaylari($tanim)
            // Havuzda henüz olmayan yeni fatura: ERP çekince kodu ondan okunur
            ->when($erpOkundu, fn (Builder $q) => $q->where('created_at', '<', $bekleme))
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
     * Havuzdaki adayların kodu ERP'deki UBL'den; İzibiz'de okunamamış (hata
     * sayacı dolmuş) fatura da buradan okunabilir. Kodu olmayan XML ağdan
     * gelmez (etiket SQL'de aranır).
     *
     * @param  array{erp_okunan: int, erp_kodlu: int, istek: int, okunan: int, kodlu: int, hatali: int}  $sonuc
     * @return bool ERP okunabildi mi
     */
    private function erpdenOku(EntegratorBaglanti $tanim, array &$sonuc): bool
    {
        // ERP'deki XML'i okunamayan fatura günde bir denenir (her 15 dakikada ~320 KB okunmaz)
        $adaylar = $this->adaylar($tanim)
            ->where(fn (Builder $q) => $q->whereNull('izibiz_ubl_son_deneme')
                ->orWhere('izibiz_ubl_son_deneme', '<', CarbonImmutable::now()->subDay()))
            ->pluck('ettn', 'id');

        try {
            foreach ($adaylar->chunk(self::ERP_PARTI) as $parti) {
                $xmller = $this->erp->istisnaXmlleri(array_values($parti->all()));
                $simdi = CarbonImmutable::now();

                foreach ($parti as $id => $ettn) {
                    $anahtar = mb_strtolower($ettn);

                    // Havuzda yok: ERP çekene kadar bekler, sonra İzibiz'den
                    if (! array_key_exists($anahtar, $xmller)) {
                        continue;
                    }

                    $kod = null;
                    if ($xmller[$anahtar] !== null) {
                        [$xmlEttn, $kod] = $this->ublOku($xmller[$anahtar]);

                        // Okunamayan ya da başka faturanın XML'i: İzibiz'e kalır
                        if ($xmlEttn !== $anahtar) {
                            Log::warning('ERP arşivindeki UBL okunamadı', ['ettn' => $ettn]);
                            EFatura::query()->whereKey($id)->update(['izibiz_ubl_son_deneme' => $simdi]);

                            continue;
                        }
                    }

                    EFatura::query()->whereKey($id)->update([
                        'izibiz_istisna_kodu' => $kod,
                        'izibiz_ubl_okundu' => $simdi,
                    ]);
                    $sonuc['erp_okunan']++;
                    $sonuc['erp_kodlu'] += $kod !== null ? 1 : 0;
                }
            }
        } catch (Throwable $e) {
            Log::warning('İstisna kodu ERP arşivinden okunamadı; İzibiz\'den okunacak', ['hata' => $e->getMessage()]);

            return false;
        }

        return true;
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
            ->where(fn (Builder $q) => $q->whereIn('fatura_tipi', self::ISTISNA_TIPLERI)
                ->orWhere('vergi_tutari', 0));
    }

    /**
     * @return Builder<EFatura>
     */
    private function izibizAdaylari(EntegratorBaglanti $tanim): Builder
    {
        return $this->adaylar($tanim)
            ->where('izibiz_ubl_hata', '<', self::AZAMI_HATA)
            ->where(fn (Builder $q) => $q->whereNull('izibiz_ubl_son_deneme')
                ->orWhere('izibiz_ubl_son_deneme', '<', CarbonImmutable::now()->subDay()));
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
                $boyut = $zip->statIndex($i)['size'] ?? 0;
                $xml = $boyut <= self::AZAMI_UBL_BAYT ? (string) $zip->getFromIndex($i) : '';
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
        // DOCTYPE'lı belge reddedilir: UBL'de yoktur, varlık tanımı taşıyabilir
        if (! @$belge->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $belge->doctype !== null) {
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
