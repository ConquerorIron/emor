<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * İzibiz e-Fatura özetlerini PostgreSQL'e senkronlar (EFAT-10, faz 1).
 *
 * - Aralık takvim aylarına bölünür; her ay ayrı çalışma kaydıdır.
 * - İzibiz okunurken veritabanı transaction'ı açık tutulmaz; kayıtlar
 *   okuma bitince kısa transaction'larla upsert edilir.
 * - Aynı tanım + yön için eşzamanlı senkron kilitle engellenir (zamanlanmış
 *   ile manuel çakışmaz).
 * - Eksik okunan aralığın okunan kayıtları yine yazılır (gerçek faturalar),
 *   ama çalışma `eksik` işaretlenir; yokluk çıkarımı yapılmaz.
 * - İzibiz bayrakları (ERP okundu) yalnız okunur, hiç değiştirilmez.
 */
final class EFaturaSenkronServisi
{
    private const YAZMA_PARTISI = 500;

    public function __construct(
        private readonly IzibizFaturaKaynagi $kaynak,
    ) {}

    /**
     * @param  int  $kilitBekleme  kilit doluysa en çok beklenecek süre (sn); 0 = beklemeden atla
     * @param  int  $kilitSuresi  kilidin ömrü (sn) — süreç öldürülürse bu kadar sonra kendiliğinden düşer
     * @return list<EFaturaSenkronCalismasi> ay başına bir çalışma; kilit alınamazsa boş
     */
    public function senkronEt(
        EntegratorBaglanti $tanim,
        FaturaYonu $yon,
        CarbonImmutable $baslangic,
        CarbonImmutable $bitis,
        string $tetikleyen,
        string $tarihTuru = IzibizFaturaKaynagi::TARIH_BELGE,
        ?int $kullaniciId = null,
        int $kilitBekleme = 0,
        int $kilitSuresi = 3600,
    ): array {
        $kilit = Cache::lock("efatura-senkron:{$tanim->id}:{$yon->value}", $kilitSuresi);

        try {
            $alindi = $kilitBekleme > 0 ? $kilit->block($kilitBekleme) : $kilit->get();
        } catch (LockTimeoutException) {
            $alindi = false;
        }

        if (! $alindi) {
            return [];
        }

        try {
            $calismalar = [];

            foreach ($this->aylaraBol($baslangic, $bitis) as [$ayBasi, $aySonu]) {
                $calismalar[] = $this->aralikSenkronEt($tanim, $yon, $ayBasi, $aySonu, $tetikleyen, $tarihTuru, $kullaniciId);
            }

            return $calismalar;
        } finally {
            $kilit->release();
        }
    }

    private function aralikSenkronEt(
        EntegratorBaglanti $tanim,
        FaturaYonu $yon,
        CarbonImmutable $baslangic,
        CarbonImmutable $bitis,
        string $tetikleyen,
        string $tarihTuru,
        ?int $kullaniciId,
    ): EFaturaSenkronCalismasi {
        $calisma = EFaturaSenkronCalismasi::query()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'yon' => $yon->value,
            'tarih_turu' => $tarihTuru,
            'baslangic' => $baslangic->toDateString(),
            'bitis' => $bitis->toDateString(),
            'tetikleyen' => $tetikleyen,
            'kullanici_id' => $kullaniciId,
            'durum' => EFaturaSenkronCalismasi::DURUM_CALISIYOR,
            'basladi' => CarbonImmutable::now(),
        ]);

        try {
            $sonuc = $this->kaynak->oku($tanim, $yon, $baslangic, $bitis, $tarihTuru);
        } catch (EntegratorHatasi $hata) {
            $calisma->update([
                'durum' => EFaturaSenkronCalismasi::DURUM_BASARISIZ,
                'hata_kodu' => $hata->kod,
                'hata_mesaji' => Str::limit($hata->getMessage(), 500),
                'bitti' => CarbonImmutable::now(),
            ]);

            return $calisma;
        } catch (Throwable $hata) {
            // Beklenmeyen hata çalışmayı `calisiyor`da bırakmasın (art arda
            // hata sayımına ve alarma girsin). Mesaj veri taşıyabileceği için
            // yalnız sınıf adı saklanır; istisna yukarıda raporlanır.
            $calisma->update([
                'durum' => EFaturaSenkronCalismasi::DURUM_BASARISIZ,
                'hata_kodu' => 'BEKLENMEYEN_HATA',
                'hata_mesaji' => $hata::class,
                'bitti' => CarbonImmutable::now(),
            ]);

            throw $hata;
        }

        try {
            [$yeni, $guncellenen] = $this->yaz($tanim, $sonuc->faturalar);
        } catch (Throwable $hata) {
            // Sürücü iletisinde SQL parametreleri ve fatura verisi olabilir.
            // Yazma sırasındaki her hata çalışmayı kapatmalı; yalnız sınıf
            // adı saklanır ve dışarıya sabit bir hata kodu verilir.
            $calisma->update([
                'durum' => EFaturaSenkronCalismasi::DURUM_BASARISIZ,
                'beklenen_adet' => $sonuc->beklenenAdet,
                'okunan_adet' => count($sonuc->faturalar),
                'hata_kodu' => 'YAZMA_HATASI',
                'hata_mesaji' => $hata::class,
                'bitti' => CarbonImmutable::now(),
            ]);

            throw new RuntimeException('e-Fatura özetleri yazılamadı: YAZMA_HATASI');
        }

        $calisma->update([
            'durum' => $sonuc->tam() ? EFaturaSenkronCalismasi::DURUM_TAM : EFaturaSenkronCalismasi::DURUM_EKSIK,
            'beklenen_adet' => $sonuc->beklenenAdet,
            'okunan_adet' => count($sonuc->faturalar),
            'yeni_adet' => $yeni,
            'guncellenen_adet' => $guncellenen,
            'hatali_adet' => count($sonuc->hataliKayitlar),
            'eksik_nedeni' => $sonuc->eksikNedeni,
            'bitti' => CarbonImmutable::now(),
        ]);

        return $calisma;
    }

    /**
     * @param  list<EntegratorFatura>  $faturalar
     * @return array{0: int, 1: int} [yeni, güncellenen]
     */
    private function yaz(EntegratorBaglanti $tanim, array $faturalar): array
    {
        $yeni = 0;
        $guncellenen = 0;
        $simdi = CarbonImmutable::now();

        foreach (array_chunk($faturalar, self::YAZMA_PARTISI) as $parti) {
            DB::transaction(function () use ($tanim, $parti, $simdi, &$yeni, &$guncellenen): void {
                $yon = $parti[0]->yon->value;
                $mevcut = EFatura::query()
                    ->where('entegrator_baglanti_id', $tanim->id)
                    ->where('yon', $yon)
                    ->whereIn('kaynak_id', array_map(fn (EntegratorFatura $f) => $f->kaynakId, $parti))
                    ->count();

                $satirlar = array_map(fn (EntegratorFatura $f) => $this->satir($tanim, $f, $simdi), $parti);

                // ilk_gorulme ve created_at güncellenmez: kaydın ilk görüldüğü an korunur
                EFatura::query()->upsert(
                    $satirlar,
                    ['entegrator_baglanti_id', 'yon', 'kaynak_id'],
                    array_values(array_diff(array_keys($satirlar[0]), [
                        'entegrator_baglanti_id', 'yon', 'kaynak_id', 'ilk_gorulme', 'created_at',
                    ])),
                );

                $yeni += count($parti) - $mevcut;
                $guncellenen += $mevcut;
            });
        }

        return [$yeni, $guncellenen];
    }

    /**
     * @return array<string, mixed>
     */
    private function satir(EntegratorBaglanti $tanim, EntegratorFatura $f, CarbonImmutable $simdi): array
    {
        return [
            'entegrator_baglanti_id' => $tanim->id,
            'yon' => $f->yon->value,
            'kaynak_id' => $f->kaynakId,
            'ettn' => $f->ettn,
            'belge_no' => $f->belgeNo,
            'belge_tarihi' => $f->belgeTarihi,
            'belge_saati' => $f->belgeSaati,
            'olusturma_zamani' => $this->yerelZaman($f->olusturmaZamani),
            'fatura_tipi' => $f->faturaTipi,
            'senaryo' => $f->senaryo,
            'para_birimi' => $f->paraBirimi,
            'tutar' => $f->tutar,
            'vergi_tutari' => $f->vergiTutari,
            'satir_sayisi' => $f->satirSayisi,
            'gonderici_vkn' => $f->gondericiVkn,
            'gonderici_unvan' => $f->gondericiUnvan,
            'alici_vkn' => $f->aliciVkn,
            'alici_unvan' => $f->aliciUnvan,
            'durum' => $f->durum,
            'durum_aciklamasi' => $f->durumAciklamasi,
            'gib_durum_kodu' => $f->gibDurumKodu,
            'gib_durum_aciklamasi' => $f->gibDurumAciklamasi,
            'erp_okundu' => $f->erpOkundu,
            'okundu' => $f->okundu,
            'yanit_aciklamasi' => $f->yanitAciklamasi,
            'ilk_gorulme' => $simdi,
            'son_gorulme' => $simdi,
            'created_at' => $simdi,
            'updated_at' => $simdi,
        ];
    }

    /**
     * İzibiz zamanı ekisiz İstanbul saatidir; UTC'ye çevrilir. Bozuksa null
     * (özet alanı; faturanın kendisini geçersiz kılmaz).
     */
    private function yerelZaman(?string $deger): ?CarbonImmutable
    {
        if ($deger === null || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $deger) !== 1) {
            return null;
        }

        try {
            $zaman = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s', $deger, (string) config('entegrator.izibiz.saat_dilimi'));
        } catch (Throwable) {
            return null;
        }

        return $zaman instanceof CarbonImmutable && $zaman->format('Y-m-d\TH:i:s') === $deger
            ? $zaman->utc()
            : null;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function aylaraBol(CarbonImmutable $baslangic, CarbonImmutable $bitis): array
    {
        $parcalar = [];
        $imlec = $baslangic->startOfDay();
        $son = $bitis->startOfDay();

        while ($imlec->lte($son)) {
            $aySonu = $imlec->endOfMonth()->startOfDay();
            $parcalar[] = [$imlec, $aySonu->lt($son) ? $aySonu : $son];
            $imlec = $aySonu->addDay();
        }

        return $parcalar;
    }
}
