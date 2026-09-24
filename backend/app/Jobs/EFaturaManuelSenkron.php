<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EFaturaSenkronServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizFaturaKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kullanıcının ekrandan başlattığı e-Fatura senkronu (EFAT-11).
 *
 * - Tanım kimliğiyle kuyruğa girer; iş başladığında tanım artık aktif
 *   değilse (ortam değişti) hiçbir şey yapmaz — yeni hesaba kendiliğinden geçmez.
 * - Aynı tanım için tek istek: kuyruğa alan uç önbellekte bayrak açar
 *   (Cache::add), iş bitince (başarılı ya da değil) bayrak kaldırılır.
 * - Eşzamanlı zamanlanmış senkronla çakışmayı servisin kilidi engeller.
 * - Yeniden denenmez: çalışma kayıtları sonucu zaten gösterir; kullanıcı
 *   isterse tekrar başlatır.
 */
final class EFaturaManuelSenkron implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Aralık config('efatura.manuel_azami_gun') ile sınırlı; kuyruk retry_after bundan uzun olmalı. */
    public int $timeout = 300;

    /**
     * Kilit ömrü işin zaman aşımına bağlı: süreç öldürülürse (finally
     * çalışmaz) zamanlanmış senkron bir saat değil birkaç dakika bekler.
     */
    private const KILIT_SURESI = 330;

    /**
     * @param  string  $baslangic  YYYY-MM-DD (istek doğrulamasından geçmiş)
     * @param  string  $bitis  YYYY-MM-DD
     * @param  string  $istekId  kuyruğa alan isteğin bayrak kimliği
     */
    public function __construct(
        public readonly int $tanimId,
        public readonly string $baslangic,
        public readonly string $bitis,
        public readonly ?int $kullaniciId,
        public readonly string $istekId,
    ) {}

    public function handle(EFaturaSenkronServisi $servis): void
    {
        try {
            $tanim = EntegratorBaglanti::query()->find($this->tanimId);

            if ($tanim === null || ! $tanim->aktif) {
                Log::warning('Elle e-Fatura senkronu atlandı: tanım artık aktif değil', [
                    'entegrator_baglanti_id' => $this->tanimId,
                ]);

                return;
            }

            foreach (FaturaYonu::cases() as $yon) {
                $calismalar = $servis->senkronEt(
                    $tanim,
                    $yon,
                    CarbonImmutable::parse($this->baslangic)->startOfDay(),
                    CarbonImmutable::parse($this->bitis)->startOfDay(),
                    EFaturaSenkronCalismasi::TETIKLEYEN_MANUEL,
                    IzibizFaturaKaynagi::TARIH_BELGE,
                    $this->kullaniciId,
                    (int) config('efatura.manuel_kilit_bekleme'),
                    self::KILIT_SURESI,
                );

                if ($calismalar === []) {
                    // Sessizce kaybolmasın: başka senkron kilidi bırakmadı
                    Log::warning('Elle e-Fatura senkronu atlandı: başka bir senkron sürüyor', [
                        'entegrator_baglanti_id' => $this->tanimId,
                        'yon' => $yon->value,
                        'kullanici_id' => $this->kullaniciId,
                    ]);
                }
            }
        } finally {
            EFaturaDurumServisi::manuelIstegiBitir($this->tanimId, $this->istekId);
        }
    }

    /** Zaman aşımı gibi handle()'ın finally'sine ulaşmayan sonlanmalarda da bayrak kalkar. */
    public function failed(?Throwable $hata): void
    {
        EFaturaDurumServisi::manuelIstegiBitir($this->tanimId, $this->istekId);
    }
}
