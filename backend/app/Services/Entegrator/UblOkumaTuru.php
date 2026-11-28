<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bir UBL okuma turunun hata kararları; İzibiz ve Protel ortak (C-2…C-5, D8).
 * Hangi hatanın "faturaya özgü" olduğunu sağlayıcı servisi izin listesiyle
 * söyler; gerisi burada:
 *
 * - Faturaya özgü hata (izinli durum, okunamayan / gelmeyen belge, yazılamayan
 *   veri) faturayı "okunamadı" işaretler. Turda henüz hiç fatura okunmadıysa
 *   işaret bekletilir: art arda DEVRE_KESICI_ESIGI fatura böyle kalırsa hata
 *   sistemik sayılır — tur kesilir, bekleyenler sayaç artmadan ertelenir
 *   (bir gün sonra yeniden; kuyruğun başı kilitlenmez). İlk okunan faturayla
 *   (ya da tur sonunda) bekleyenler "okunamadı" yazılır.
 * - İsteğin faturaya özgü olmayan hatası (erişim, 5xx, okunamayan yanıt, başka
 *   4xx) işlenen faturaları ERTELER (sayaç artmaz) ve turu keser.
 * - Giriş (token) ve kimlik hatası hiçbir faturaya dokunmadan turu keser.
 */
final class UblOkumaTuru
{
    private const DEVRE_KESICI_ESIGI = 3;

    /** Turu kesen ama faturayı erteleyen kodlar; giriş ve kimlik hataları dışarıda */
    private const ERTELENEN_KODLAR = [EntegratorHatasi::ERISILEMEDI, EntegratorHatasi::YANIT_GECERSIZ, EntegratorHatasi::HATA];

    /** @var list<int> Turda henüz okuma yokken okunamayan faturalar */
    private array $bekleyenler = [];

    private bool $okunanVar = false;

    /** @var array{istek: int, okunan: int, hatali: int} */
    private array $sonuc = ['istek' => 0, 'okunan' => 0, 'hatali' => 0];

    public function __construct(
        private readonly EFaturaUblKaydi $kayit,
    ) {}

    public function istekYapildi(): void
    {
        $this->sonuc['istek']++;
    }

    public function istekSayisi(): int
    {
        return $this->sonuc['istek'];
    }

    /** Veri yazılamazsa (zehirli kayıt) fatura okunamadı sayılır, tur sürer (C-4). */
    public function okundu(int $faturaId, UblVerisi $veri): void
    {
        try {
            $this->kayit->yaz($faturaId, $veri);
        } catch (Throwable $hata) {
            Log::warning('UBL verisi yazılamadı', ['fatura_id' => $faturaId, 'hata' => $hata::class]);
            $this->okunamadi([$faturaId]);

            return;
        }

        $this->sonuc['okunan']++;
        $this->okunanVar = true;
        $this->bekleyenleriYaz();
    }

    /**
     * @param  list<int>  $faturaIdleri
     */
    public function okunamadi(array $faturaIdleri): void
    {
        $this->sonuc['hatali'] += count($faturaIdleri);

        if ($this->okunanVar) {
            $this->kayit->hataliIsaretle($faturaIdleri);

            return;
        }

        array_push($this->bekleyenler, ...$faturaIdleri);

        if (count($this->bekleyenler) >= self::DEVRE_KESICI_ESIGI) {
            Log::warning('UBL okuması kesildi: hiçbir fatura okunmadan art arda okunamayan fatura (sistemik sayıldı, sayaç artmadan ertelendi)', ['fatura_idleri' => $this->bekleyenler]);
            // Sayaç artmaz (kalıcı bırakılmaz) ama ertelenir: sorun bu faturalara
            // özgüyse sonraki tur kuyruğun başında aynı faturalarda takılmaz (C-5)
            $this->kayit->ertele($this->bekleyenler);
            $this->bekleyenler = [];

            throw EntegratorHatasi::ublTuruKesildi();
        }
    }

    /**
     * İsteğin faturaya özgü olmayan hatası: tur kesilir. Erişim ve sağlayıcı
     * hatasında işlenen faturalar ertelenir; giriş / kimlik hatasında hiçbir
     * faturaya dokunulmaz. Bekleyen işaretler yazılmaz.
     *
     * @param  list<int>  $faturaIdleri
     */
    public function durdur(EntegratorHatasi $hata, array $faturaIdleri): never
    {
        if (! $hata->girisHatasi && in_array($hata->kod, self::ERTELENEN_KODLAR, true)) {
            $this->kayit->ertele($faturaIdleri);
        }

        throw $hata;
    }

    /**
     * @return array{istek: int, okunan: int, hatali: int}
     */
    public function bitir(): array
    {
        $this->bekleyenleriYaz();

        return $this->sonuc;
    }

    private function bekleyenleriYaz(): void
    {
        if ($this->bekleyenler !== []) {
            $this->kayit->hataliIsaretle($this->bekleyenler);
            $this->bekleyenler = [];
        }
    }
}
