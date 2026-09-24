<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\AlarmMaili;
use App\Models\AlarmBildirimi;
use App\Models\AlarmOlayi;
use App\Services\MailAyarServisi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alarm bildirimini mail olarak gönderir (EFAT-13).
 *
 * - Yük yalnız bildirim kimliğidir; içerik gönderim anında kayıttan kurulur
 *   (failed_jobs'a fatura/kişi verisi düşmez).
 * - Göndermeden önce denetlenir: kayıt hâlâ bekliyor mu, kural hâlâ aktif
 *   mi, hesap hâlâ aktif ortam mı, açılış bildiriminin olayı hâlâ açık mı,
 *   alıcı var mı, TEST hesabıysa yönlendirme adresi tanımlı mı. Değilse
 *   `atlandi` + neden kodu.
 * - "gönderildi" yalnız SMTP kabulünden SONRA işaretlenir. Kabul ile işaret
 *   arasında süreç ölürse yeniden deneme maili ikinci kez gönderebilir
 *   (en az bir kez teslim; SMTP ile "tam bir kez" garanti edilemez).
 * - SMTP hatası 6 saat boyunca artan aralıklarla denenir (kısa SMTP
 *   kesintisinde alarm kaybolmasın); tanım eksikliği (şifre yok vb.) denenmez.
 * - Aynı bildirimi iki worker aynı anda göndermesin diye gönderim kilitle
 *   yapılır; kilit alındıktan sonra durum yeniden okunur.
 */
final class AlarmBildirimiGonder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var list<int> son değer sonraki denemelerde tekrarlanır */
    public array $backoff = [60, 300, 900, 1800];

    public int $timeout = 60;

    /** Deneme penceresinden uzun: pencere boyunca aynı bildirim ikinci kez kuyruğa girmez. */
    public int $uniqueFor = 7 * 3600;

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function __construct(
        public readonly int $bildirimId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->bildirimId;
    }

    public function handle(MailAyarServisi $mail): void
    {
        $kilit = Cache::lock("alarm-bildirim-gonder:{$this->bildirimId}", $this->timeout + 30);

        if (! $kilit->get()) {
            // Başka worker şu an gönderiyor; biraz sonra durum yeniden denetlenir
            $this->release(60);

            return;
        }

        try {
            $this->gonder($mail);
        } finally {
            $kilit->release();
        }
    }

    private function gonder(MailAyarServisi $mail): void
    {
        $bildirim = AlarmBildirimi::query()->with(['kural', 'entegratorBaglanti', 'olay'])->find($this->bildirimId);

        if ($bildirim === null || $bildirim->durum !== AlarmBildirimi::BEKLIYOR) {
            return;
        }

        $engel = $this->engel($bildirim, $mail);
        if ($engel !== null) {
            $bildirim->update(['durum' => AlarmBildirimi::ATLANDI, 'hata_kodu' => $engel]);

            return;
        }

        $bildirim->increment('deneme');

        try {
            $mail->gonder(
                $bildirim->kural->alicilar,
                new AlarmMaili($bildirim, $bildirim->kural->tur, $bildirim->entegratorBaglanti->ortam),
            );
        } catch (ValidationException $hata) {
            // SMTP tanımı eksik: tekrar denemek düzeltmez
            $this->isaretle($bildirim, 'MAIL_AYARI_EKSIK', $hata->getMessage());
            $this->fail($hata);

            return;
        } catch (Throwable $hata) {
            $bildirim->update([
                'hata_kodu' => 'SMTP_HATASI',
                'hata_mesaji' => $this->guvenliMesaj($hata),
            ]);

            throw $hata;
        }

        $bildirim->update([
            'durum' => AlarmBildirimi::GONDERILDI,
            'gonderildi' => CarbonImmutable::now(),
            'hata_kodu' => null,
            'hata_mesaji' => null,
        ]);
    }

    public function failed(?Throwable $hata): void
    {
        $bildirim = AlarmBildirimi::query()->find($this->bildirimId);

        if ($bildirim !== null && $bildirim->durum === AlarmBildirimi::BEKLIYOR) {
            $this->isaretle($bildirim, $bildirim->hata_kodu ?? 'GONDERILEMEDI', $hata === null ? null : $this->guvenliMesaj($hata));
        }
    }

    private function engel(AlarmBildirimi $bildirim, MailAyarServisi $mail): ?string
    {
        return match (true) {
            ! $bildirim->kural->aktif => 'KURAL_PASIF',
            ! $bildirim->entegratorBaglanti->aktif => 'ORTAM_DEGISTI',
            $bildirim->tur === AlarmBildirimi::TUR_ACILDI
                && $bildirim->olay !== null
                && $bildirim->olay->durum !== AlarmOlayi::ACIK => 'OLAY_KAPANDI',
            // Açılışı hiç duyurulmamış sorunun "çözüldü" maili kafa karıştırır
            $bildirim->tur === AlarmBildirimi::TUR_COZULDU
                && $bildirim->alarm_olayi_id !== null
                && ! AlarmBildirimi::query()
                    ->where('alarm_olayi_id', $bildirim->alarm_olayi_id)
                    ->where('tur', AlarmBildirimi::TUR_ACILDI)
                    ->where('durum', AlarmBildirimi::GONDERILDI)
                    ->exists() => 'ACILIS_GONDERILMEDI',
            $bildirim->kural->alicilar === [] => 'ALICI_YOK',
            // Test hesabının alarmı gerçek alıcılara çıkmaz
            $bildirim->entegratorBaglanti->ortam === 'test'
                && $mail->ayar()?->yonlendirme_adresi === null => 'TEST_YONLENDIRME_YOK',
            default => null,
        };
    }

    private function isaretle(AlarmBildirimi $bildirim, string $kod, ?string $mesaj): void
    {
        $bildirim->update([
            'durum' => AlarmBildirimi::BASARISIZ,
            'hata_kodu' => $kod,
            'hata_mesaji' => $mesaj,
        ]);
    }

    /** SMTP yanıtı şifre içermez ama uzun olabilir; kısaltılır. */
    private function guvenliMesaj(Throwable $hata): string
    {
        return Str::limit($hata->getMessage(), 300);
    }
}
