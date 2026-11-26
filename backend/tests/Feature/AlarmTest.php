<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AlarmBildirimiGonder;
use App\Mail\AlarmMaili;
use App\Models\AlarmBildirimi;
use App\Models\AlarmKurali;
use App\Models\AlarmOlayi;
use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Models\MailAyari;
use App\Models\User;
use App\Services\Alarm\AlarmDegerlendirici;
use App\Services\EntegratorBaglantiServisi;
use App\Services\MailAyarServisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** e-Fatura alarmları (EFAT-13): değerlendirme, tekrar etmeme, gönderim, yönetim ucu. */
final class AlarmTest extends TestCase
{
    use RefreshDatabase;

    private function tanim(string $ortam = 'test'): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->aktif()->create(['ortam' => $ortam]);
    }

    /**
     * @param  array<string, int|string>  $parametreler
     */
    private function kural(string $tur, array $parametreler = [], bool $aktif = true): AlarmKurali
    {
        return AlarmKurali::query()->create([
            'tur' => $tur,
            'aktif' => $aktif,
            'alicilar' => ['muhasebe@ornek.test'],
            'parametreler' => [...AlarmKurali::varsayilanParametreler($tur), ...$parametreler],
        ]);
    }

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function calisma(EntegratorBaglanti $tanim, string $yon, array $alanlar = []): void
    {
        EFaturaSenkronCalismasi::query()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'yon' => $yon,
            'tarih_turu' => 'DELIVERY',
            'baslangic' => now('Europe/Istanbul')->subDay()->toDateString(),
            'bitis' => now('Europe/Istanbul')->toDateString(),
            'tetikleyen' => 'zamanlanmis',
            'durum' => 'tam',
            'basladi' => now()->subMinute(),
            'bitti' => now(),
            ...$alanlar,
        ]);
    }

    private function guncelVeri(EntegratorBaglanti $tanim): void
    {
        $this->calisma($tanim, 'gelen');
        $this->calisma($tanim, 'giden');
    }

    /** @return list<AlarmBildirimi> */
    private function degerlendir(): array
    {
        return app(AlarmDegerlendirici::class)->calistir();
    }

    private function mailAyari(?string $yonlendirme = 'test-kutusu@ornek.test'): void
    {
        MailAyari::query()->create([
            'anahtar' => MailAyari::ANAHTAR,
            'sunucu' => 'smtp.ornek.test',
            'port' => 587,
            'sifreleme' => MailAyari::SIFRELEME_TLS,
            'kullanici_adi' => 'gonderen@ornek.test',
            'sifre' => 'kayitli-sifre',
            'gonderen_adres' => 'gonderen@ornek.test',
            'gonderen_ad' => 'eMOR ERP',
            'yonlendirme_adresi' => $yonlendirme,
        ]);
    }

    private function gonder(AlarmBildirimi $bildirim): void
    {
        (new AlarmBildirimiGonder($bildirim->id))->handle(app(MailAyarServisi::class));
    }

    // --- Senkron arızası -------------------------------------------------------

    public function test_art_arda_hata_esigi_asilinca_tek_olay_ve_tek_bildirim_uretilir(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['basladi' => now()->subHour(), 'bitti' => now()->subHour()]);
        foreach ([30, 20, 10] as $dakika) {
            $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI', 'basladi' => now()->subMinutes($dakika), 'bitti' => now()->subMinutes($dakika)]);
        }

        $ilk = $this->degerlendir();
        $ikinci = $this->degerlendir();

        $this->assertCount(1, $ilk);
        $this->assertSame(AlarmBildirimi::TUR_ACILDI, $ilk[0]->tur);
        $this->assertSame(['gelen', 3], [$ilk[0]->ayrinti['yon'], $ilk[0]->ayrinti['ardisik_hata']]);
        $this->assertSame([], $ikinci);
        $this->assertSame(1, AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->count());
    }

    public function test_ariza_duzelince_cozuldu_bildirimi_yeniden_bozulunca_yeni_olay_acilir(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'eksik', 'eksik_nedeni' => 'SAYFA_SINIRI']);
        $this->degerlendir();

        $this->travel(15)->minutes();
        $this->calisma($tanim, 'gelen');
        [$cozuldu] = $this->degerlendir();

        $this->travel(15)->minutes();
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI']);
        [$yeniden] = $this->degerlendir();

        $this->assertSame(AlarmBildirimi::TUR_COZULDU, $cozuldu->tur);
        $this->assertSame(AlarmBildirimi::TUR_ACILDI, $yeniden->tur);
        $this->assertNotSame($cozuldu->alarm_olayi_id, $yeniden->alarm_olayi_id);
        $this->assertSame(1, AlarmOlayi::query()->where('durum', AlarmOlayi::COZULDU)->count());
        $this->assertSame(1, AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->count());
    }

    public function test_veri_esik_saatten_uzun_guncellenmezse_ariza_sayilir(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['gecikme_saat' => 2]);
        $this->calisma($tanim, 'gelen', ['basladi' => now()->subHours(3), 'bitti' => now()->subHours(3)]);
        $this->calisma($tanim, 'giden', ['basladi' => now()->subMinutes(119), 'bitti' => now()->subMinutes(119)]);

        $bildirimler = $this->degerlendir();

        $this->assertCount(1, $bildirimler);
        $this->assertSame('gelen', $bildirimler[0]->ayrinti['yon']);
    }

    public function test_senkron_bilerek_kapaliyken_ya_da_kural_pasifken_alarm_uretilmez(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $this->tanim();
        $kural = $this->kural(AlarmKurali::SENKRON_ARIZASI);
        config(['entegrator.izibiz.senkron_aktif' => false]);

        $this->assertSame([], $this->degerlendir());

        config(['entegrator.izibiz.senkron_aktif' => true]);
        $kural->update(['aktif' => false]);

        $this->assertSame([], $this->degerlendir());
        $this->assertSame(0, AlarmOlayi::query()->count());
    }

    // --- Günlük özet -----------------------------------------------------------

    public function test_gunluk_ozet_ayarlanan_saatten_sonra_onceki_gunun_ilk_gorulen_faturalarini_bir_kez_ozetler(): void
    {
        // 08:00 İstanbul = 05:00 UTC
        $this->travelTo('2026-09-23 04:59:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::GUNLUK_OZET, ['saat' => '08:00']);
        $fatura = fn (array $alanlar) => EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, ...$alanlar]);
        // 22 Eylül İstanbul günü = 21 Eylül 21:00 UTC – 22 Eylül 21:00 UTC
        $fatura(['ilk_gorulme' => '2026-09-21 21:00:00', 'tutar' => '100.0000']);
        $fatura(['ilk_gorulme' => '2026-09-22 20:59:59', 'tutar' => '50.5000']);
        $fatura(['ilk_gorulme' => '2026-09-22 21:00:00', 'tutar' => '999.0000']);
        $fatura(['ilk_gorulme' => '2026-09-22 10:00:00', 'yon' => 'giden', 'para_birimi' => 'USD', 'tutar' => '10.0000']);

        $this->assertSame([], $this->degerlendir());

        $this->travelTo('2026-09-23 05:00:00');
        [$ozet] = $this->degerlendir();

        $this->assertSame('gunluk_ozet:'.$tanim->id.':2026-09-22', $ozet->anahtar);
        $this->assertSame(2, $ozet->ayrinti['yonler']['gelen']['adet']);
        $this->assertSame('150.50', $ozet->ayrinti['yonler']['gelen']['para_birimleri'][0]['tutar']);
        $this->assertSame('USD', $ozet->ayrinti['yonler']['giden']['para_birimleri'][0]['para_birimi']);
        $this->assertSame([], $this->degerlendir());
    }

    // --- ERP okumadı -----------------------------------------------------------

    public function test_erp_okumadi_veri_guncel_degilse_hicbir_sey_uretmez(): void
    {
        $this->travelTo('2026-09-23 07:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::ERP_OKUMADI);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-01 10:00:00', 'belge_tarihi' => '2026-09-01']);

        $this->assertSame([], $this->degerlendir());
        $this->assertSame(0, AlarmBildirimi::query()->count());
    }

    public function test_erp_okumadi_esigi_gecen_faturalar_icin_olay_acar_ertesi_gun_yenisi_yoksa_mail_uretmez_okununca_cozer(): void
    {
        $this->travelTo('2026-09-23 07:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::ERP_OKUMADI, ['gun' => 2, 'saat' => '09:00']);
        $this->guncelVeri($tanim);
        $eski = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-20 10:00:00', 'belge_tarihi' => '2026-09-20']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-22 10:00:00', 'belge_tarihi' => '2026-09-22']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => true, 'olusturma_zamani' => '2026-09-10 10:00:00', 'belge_tarihi' => '2026-09-10']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'yon' => 'giden', 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-10 10:00:00', 'belge_tarihi' => '2026-09-10']);

        [$bildirim] = $this->degerlendir();

        $this->assertSame(AlarmBildirimi::BEKLIYOR, $bildirim->durum);
        $this->assertSame(1, $bildirim->ayrinti['yeni_adet']);
        $this->assertSame(['fatura:'.$eski->id], AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->pluck('anahtar')->all());
        $this->assertSame([], $this->degerlendir());

        $this->travelTo('2026-09-24 11:00:00');
        $this->guncelVeri($tanim);
        $this->faturalarTazelendi($tanim);
        $this->degerlendir();
        $ertesiGun = AlarmBildirimi::query()->where('anahtar', 'erp_okumadi:'.$tanim->id.':2026-09-24')->firstOrFail();
        // 22 Eylül 10:00 faturası da artık 2 günü geçti (eşik 22 Eylül 11:00)
        $this->assertSame(AlarmBildirimi::BEKLIYOR, $ertesiGun->durum);
        // jsonb anahtar sırasını korumaz: alan alan karşılaştırılır
        $this->assertSame([1, 2], [$ertesiGun->ayrinti['yeni_adet'], $ertesiGun->ayrinti['acik_adet']]);

        $this->travelTo('2026-09-25 07:00:00');
        $this->guncelVeri($tanim);
        $this->faturalarTazelendi($tanim);
        $eski->update(['erp_okundu' => true]);
        $this->degerlendir();
        $ucuncuGun = AlarmBildirimi::query()->where('anahtar', 'erp_okumadi:'.$tanim->id.':2026-09-25')->firstOrFail();

        $this->assertSame(AlarmBildirimi::ATLANDI, $ucuncuGun->durum);
        $this->assertSame('YENI_OLAY_YOK', $ucuncuGun->hata_kodu);
        $this->assertSame(1, $ucuncuGun->ayrinti['cozulen_adet']);
        $this->assertSame(1, AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->count());
    }

    public function test_erp_okumadi_gizlenen_fatura_icin_olay_acmaz_acik_olayi_kapanir(): void
    {
        $this->travelTo('2026-09-23 07:00:00');
        $tanim = $this->tanim();
        $kural = $this->kural(AlarmKurali::ERP_OKUMADI, ['gun' => 2, 'saat' => '09:00']);
        $this->guncelVeri($tanim);
        $alanlar = ['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-10 10:00:00', 'belge_tarihi' => '2026-09-10', 'son_gorulme' => now()];
        // Bize ait değil (yanlış posta kutusu), gizlendi
        EFatura::factory()->create([...$alanlar, 'gizlenme_zamani' => now()->subDay()]);
        // Olayı açıkken gizlendi
        $sonradanGizlenen = EFatura::factory()->create([...$alanlar, 'gizlenme_zamani' => now()]);
        AlarmOlayi::query()->create(['alarm_kurali_id' => $kural->id, 'entegrator_baglanti_id' => $tanim->id, 'anahtar' => 'fatura:'.$sonradanGizlenen->id, 'durum' => AlarmOlayi::ACIK, 'acildi' => now()->subDays(5)]);

        $this->assertSame([], $this->degerlendir());
        $bildirim = AlarmBildirimi::query()->sole();

        $this->assertSame([0, 0, 1], [$bildirim->ayrinti['yeni_adet'], $bildirim->ayrinti['acik_adet'], $bildirim->ayrinti['cozulen_adet']]);
        $this->assertSame(0, AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->count());
    }

    /** Gece DOCUMENT senkronu faturaları yeniden okudu (son_gorulme şimdi). */
    private function faturalarTazelendi(EntegratorBaglanti $tanim): void
    {
        EFatura::query()->where('entegrator_baglanti_id', $tanim->id)->update(['son_gorulme' => now()]);
    }

    public function test_erp_okumadi_yeniden_okunmamis_eski_satirla_olay_acmaz_ve_acik_olayi_kapatmaz(): void
    {
        $this->travelTo('2026-09-23 07:00:00');
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::ERP_OKUMADI, ['gun' => 2, 'saat' => '09:00']);
        $this->guncelVeri($tanim);
        // Çalışma düzeyi güncel; ama bu satır 3 gündür yeniden okunmadı (bayrağı bayat)
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => false, 'olusturma_zamani' => '2026-09-10 10:00:00', 'belge_tarihi' => '2026-09-10', 'son_gorulme' => now()->subDays(3)]);
        // Açık olayın faturası ERP'de okunmuş görünüyor ama bu bilgi de bayat
        $bayatOkunmus = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'erp_okundu' => true, 'olusturma_zamani' => '2026-09-10 10:00:00', 'belge_tarihi' => '2026-09-10', 'son_gorulme' => now()->subDays(3)]);
        $kural = AlarmKurali::query()->where('tur', AlarmKurali::ERP_OKUMADI)->firstOrFail();
        AlarmOlayi::query()->create(['alarm_kurali_id' => $kural->id, 'entegrator_baglanti_id' => $tanim->id, 'anahtar' => 'fatura:'.$bayatOkunmus->id, 'durum' => AlarmOlayi::ACIK, 'acildi' => now()->subDays(5)]);

        // Gönderilecek bildirim yok; günün değerlendirme izi "atlandı" kaydı
        $this->assertSame([], $this->degerlendir());
        $bildirim = AlarmBildirimi::query()->sole();

        $this->assertSame([AlarmBildirimi::ATLANDI, 0, 0], [$bildirim->durum, $bildirim->ayrinti['yeni_adet'], $bildirim->ayrinti['cozulen_adet']]);
        $this->assertSame(['fatura:'.$bayatOkunmus->id], AlarmOlayi::query()->where('durum', AlarmOlayi::ACIK)->pluck('anahtar')->all());
    }

    public function test_erp_okundu_bilinmiyorsa_taze_satir_acik_olayi_kapatmaz(): void
    {
        $this->travelTo('2026-09-23 07:00:00');
        $tanim = $this->tanim();
        $kural = $this->kural(AlarmKurali::ERP_OKUMADI, ['gun' => 2, 'saat' => '09:00']);
        $this->guncelVeri($tanim);
        $fatura = EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'erp_okundu' => null,
            'olusturma_zamani' => '2026-09-10 10:00:00',
            'son_gorulme' => now(),
        ]);
        $olay = AlarmOlayi::query()->create([
            'alarm_kurali_id' => $kural->id,
            'entegrator_baglanti_id' => $tanim->id,
            'anahtar' => 'fatura:'.$fatura->id,
            'durum' => AlarmOlayi::ACIK,
            'acildi' => now()->subDay(),
        ]);

        $this->degerlendir();

        $this->assertSame(AlarmOlayi::ACIK, $olay->refresh()->durum);
    }

    public function test_gunluk_ozet_ilk_tarama_gununu_belirtir(): void
    {
        $this->travelTo('2026-09-23 06:00:00');
        $tanim = $this->tanim('canli');
        $this->kural(AlarmKurali::GUNLUK_OZET);
        $this->calisma($tanim, 'gelen', ['tetikleyen' => 'ilk_tarama', 'basladi' => '2026-09-22 09:00:00', 'bitti' => '2026-09-22 09:02:00']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ilk_gorulme' => '2026-09-22 09:01:00']);

        [$ozet] = $this->degerlendir();
        $html = (new AlarmMaili($ozet, AlarmKurali::GUNLUK_OZET, 'canli'))->render();

        $this->assertTrue($ozet->ayrinti['ilk_tarama']);
        $this->assertStringContainsString('Bu gün ilk tarama yapıldı', $html);
    }

    // --- Gönderim --------------------------------------------------------------

    public function test_acilisi_gonderilmemis_sorunun_cozuldu_maili_gitmez(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Mail::fake();
        $this->mailAyari();
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz']);
        [$acildi] = $this->degerlendir();
        $acildi->update(['durum' => AlarmBildirimi::ATLANDI, 'hata_kodu' => 'KURAL_PASIF']);
        $this->travel(15)->minutes();
        $this->calisma($tanim, 'gelen');
        [$cozuldu] = $this->degerlendir();

        $this->gonder($cozuldu);

        Mail::assertNothingSent();
        $this->assertSame('ACILIS_GONDERILMEDI', $cozuldu->refresh()->hata_kodu);
    }

    public function test_ayni_bildirimi_baska_worker_gonderirken_ikinci_is_gondermez(): void
    {
        Mail::fake();
        $this->mailAyari();
        $bildirim = $this->bekleyenOzet($this->tanim());
        Cache::lock("alarm-bildirim-gonder:{$bildirim->id}", 90)->get();

        $this->gonder($bildirim);

        Mail::assertNothingSent();
        $this->assertSame(AlarmBildirimi::BEKLIYOR, $bildirim->refresh()->durum);
    }

    public function test_bildirim_yonlendirme_adresine_test_etiketiyle_gonderilir_ve_gonderildi_isaretlenir(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Mail::fake();
        $this->mailAyari();
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI']);
        [$bildirim] = $this->degerlendir();

        $this->gonder($bildirim);

        Mail::assertSent(AlarmMaili::class, fn (AlarmMaili $mail): bool => $mail->hasTo('test-kutusu@ornek.test')
            && ! $mail->hasTo('muhasebe@ornek.test')
            && $mail->hasSubject('[TEST] eMOR ERP — Gelen e-Fatura senkronu çalışmıyor'));
        $bildirim->refresh();
        $this->assertSame(AlarmBildirimi::GONDERILDI, $bildirim->durum);
        $this->assertSame(1, $bildirim->deneme);
        $this->assertNotNull($bildirim->gonderildi);
    }

    public function test_alarm_mailinde_fatura_unvani_yok_yalniz_sayilar_ve_ekran_baglantisi_var(): void
    {
        $this->travelTo('2026-09-23 06:00:00');
        $tanim = $this->tanim('canli');
        $this->kural(AlarmKurali::GUNLUK_OZET);
        EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'gonderici_unvan' => '<script>alert(1)</script> Gizli Tedarikçi',
            'ilk_gorulme' => '2026-09-22 10:00:00',
            'tutar' => '1250.5000',
        ]);
        [$bildirim] = $this->degerlendir();

        $html = (new AlarmMaili($bildirim, AlarmKurali::GUNLUK_OZET, 'canli'))->render();

        $this->assertStringContainsString('Gelen: 1 fatura — 1.250,50 TRY', $html);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/').'/efatura/gelen', $html);
        $this->assertStringNotContainsString('Gizli Tedarikçi', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_ozet_maili_buyuk_tutari_kurus_kaybetmeden_gosterir(): void
    {
        $bildirim = new AlarmBildirimi([
            'tur' => AlarmBildirimi::TUR_OZET,
            'ayrinti' => [
                'gun' => '2026-09-22',
                'yonler' => [
                    'gelen' => ['adet' => 1, 'guncel' => true, 'para_birimleri' => [
                        ['para_birimi' => 'TRY', 'adet' => 1, 'tutar' => '1234567890123456.78'],
                    ]],
                ],
            ],
        ]);

        $html = (new AlarmMaili($bildirim, AlarmKurali::GUNLUK_OZET, 'canli'))->render();

        $this->assertStringContainsString('1.234.567.890.123.456,78 TRY', $html);
    }

    public function test_test_hesabinda_yonlendirme_yoksa_gercek_aliciya_gitmez_atlandi_olur(): void
    {
        Mail::fake();
        $this->mailAyari(null);
        $bildirim = $this->bekleyenOzet($this->tanim('test'));

        $this->gonder($bildirim);

        Mail::assertNothingSent();
        $this->assertSame(['atlandi', 'TEST_YONLENDIRME_YOK'], [$bildirim->refresh()->durum, $bildirim->hata_kodu]);
    }

    public function test_kural_gonderimden_once_pasife_alindiysa_atlanir(): void
    {
        Mail::fake();
        $this->mailAyari();
        $bildirim = $this->bekleyenOzet($this->tanim());
        AlarmKurali::query()->update(['aktif' => false]);

        $this->gonder($bildirim);

        Mail::assertNothingSent();
        $this->assertSame('KURAL_PASIF', $bildirim->refresh()->hata_kodu);
    }

    public function test_olay_gonderimden_once_kapandiysa_acilis_maili_gitmez(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Mail::fake();
        $this->mailAyari();
        $tanim = $this->tanim();
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz']);
        [$bildirim] = $this->degerlendir();
        AlarmOlayi::query()->update(['durum' => AlarmOlayi::COZULDU]);

        $this->gonder($bildirim);

        Mail::assertNothingSent();
        $this->assertSame('OLAY_KAPANDI', $bildirim->refresh()->hata_kodu);
    }

    public function test_smtp_hatasi_yeniden_denemeye_birakilir_son_denemede_basarisiz_olur(): void
    {
        // Kapalı yerel port: dış ağa çıkmadan gerçek bağlantı hatası
        MailAyari::query()->create([
            'anahtar' => MailAyari::ANAHTAR,
            'sunucu' => '127.0.0.1',
            'port' => 1,
            'sifreleme' => MailAyari::SIFRELEME_YOK,
            'kullanici_adi' => null,
            'sifre' => null,
            'gonderen_adres' => 'gonderen@ornek.test',
            'gonderen_ad' => 'eMOR ERP',
        ]);
        $bildirim = $this->bekleyenOzet($this->tanim('canli'));
        $is = new AlarmBildirimiGonder($bildirim->id);

        try {
            $is->handle(app(MailAyarServisi::class));
            $this->fail('SMTP hatası yeniden denenmek üzere fırlatılmalıydı.');
        } catch (RuntimeException) {
        }

        $this->assertSame(['bekliyor', 'SMTP_HATASI'], [$bildirim->refresh()->durum, $bildirim->hata_kodu]);

        $is->failed(new RuntimeException('451 geçici hata'));

        $this->assertSame(AlarmBildirimi::BASARISIZ, $bildirim->refresh()->durum);
        $this->assertSame('451 geçici hata', $bildirim->hata_mesaji);
    }

    public function test_smtp_tanimi_yoksa_denenmeden_basarisiz_olur(): void
    {
        Mail::fake();
        $bildirim = $this->bekleyenOzet($this->tanim('canli'));

        $this->gonder($bildirim);

        $this->assertSame([AlarmBildirimi::BASARISIZ, 'MAIL_AYARI_EKSIK'], [$bildirim->refresh()->durum, $bildirim->hata_kodu]);
    }

    private function bekleyenOzet(EntegratorBaglanti $tanim): AlarmBildirimi
    {
        $this->travelTo('2026-09-23 06:00:00');
        $this->kural(AlarmKurali::GUNLUK_OZET);
        [$bildirim] = $this->degerlendir();

        return $bildirim;
    }

    // --- Komut -----------------------------------------------------------------

    public function test_komut_yeni_ve_takili_kalmis_bekleyen_bildirimleri_kuyruga_verir(): void
    {
        $this->travelTo('2026-09-23 06:00:00');
        Queue::fake([AlarmBildirimiGonder::class]);
        $tanim = $this->tanim();
        $kural = $this->kural(AlarmKurali::GUNLUK_OZET);
        $yetim = AlarmBildirimi::query()->create([
            'alarm_kurali_id' => $kural->id,
            'entegrator_baglanti_id' => $tanim->id,
            'tur' => 'ozet',
            'anahtar' => 'gunluk_ozet:'.$tanim->id.':2026-09-20',
            'durum' => 'bekliyor',
            'ayrinti' => [],
        ]);
        $yetim->forceFill(['updated_at' => now()->subHour()])->save();

        $this->artisan('efatura:alarmlar')->assertSuccessful();

        Queue::assertPushed(AlarmBildirimiGonder::class, 2);
        Queue::assertPushed(AlarmBildirimiGonder::class, fn (AlarmBildirimiGonder $is) => $is->bildirimId === $yetim->id);
    }

    // --- Yönetim ucu -----------------------------------------------------------

    public function test_alarm_kurallari_yalniz_sistem_yoneticisine_acik(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/ayarlar/alarm-kurallari')
            ->assertForbidden();
    }

    public function test_kurallar_ilk_okumada_kapali_ve_varsayilan_esiklerle_olusur(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/ayarlar/alarm-kurallari')
            ->assertOk()
            ->assertJsonPath('data.0.tur', 'senkron_arizasi')
            ->assertJsonPath('data.0.aktif', false)
            ->assertJsonPath('data.0.parametreler', ['ardisik_hata' => 3, 'gecikme_saat' => 2])
            ->assertJsonPath('data.0.alicilar_cc', [])
            ->assertJsonPath('data.0.alicilar_bcc', [])
            ->assertJsonPath('data.2.parametreler', ['gun' => 2, 'saat' => '09:00'])
            ->assertJsonPath('data.3.parametreler.islendi_sayilanlar', 'emor_islendi,emor_elle_islendi,sap_islendi')
            ->assertJsonPath('bildirimler', []);
    }

    public function test_kural_guncellenir_alicilar_tekillesir_bilinmeyen_parametre_saklanmaz(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/alarm-kurallari/erp_okumadi', [
                'aktif' => true,
                'alicilar' => ['Muhasebe@Ornek.test', 'muhasebe@ornek.test ', 'finans@ornek.test'],
                'parametreler' => ['gun' => 3, 'saat' => '10:30', 'fazla' => 'x'],
            ])
            ->assertOk()
            ->assertJsonPath('data.alicilar', ['muhasebe@ornek.test', 'finans@ornek.test']);

        $kural = AlarmKurali::query()->where('tur', 'erp_okumadi')->firstOrFail();
        $this->assertTrue($kural->aktif);
        $this->assertSame(['gun' => 3, 'saat' => '10:30'], $kural->parametreler);
    }

    /**
     * İşlenmemiş fatura hatırlatması (EK-10): saatten sonra, yalnız entegratöre
     * N günden önce alınmış ve işlenmemiş faturaları seçili portallarda sayar
     * (işlenmiş, RED, gizlenen ve yeni fatura sayılmaz); sonraki hatırlatma
     * `aralik_gun` gün sonra. Bekleyen yoksa mail gitmez, ertesi gün yeniden bakılır.
     */
    public function test_islenmemis_hatirlatmasi_esigi_gecen_islenecekleri_portal_bazinda_aralikla_bildirir(): void
    {
        $this->travelTo('2026-11-25 05:00:00'); // 08:00 İstanbul
        $izibiz = $this->tanim('canli');
        $protel = EntegratorBaglanti::factory()->protel()->canli()->aktif()->create();
        $kural = $this->kural(AlarmKurali::ISLENMEMIS_HATIRLATMA, ['gun' => 3, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => 'izibiz,protel']);
        // Alınma zamanı belge gününün öğleni (İstanbul 12:00 = 09:00 UTC)
        $f = fn (EntegratorBaglanti $t, string $tarih, array $alanlar = []) => EFatura::factory()->create(['entegrator_baglanti_id' => $t->id, 'belge_tarihi' => $tarih, 'olusturma_zamani' => "{$tarih} 09:00:00", 'emor_durumu' => 'yok', ...$alanlar]);
        $f($izibiz, '2026-11-10');
        $f($izibiz, '2026-11-22');
        $f($izibiz, '2026-11-23'); // eşikten yeni
        $f($izibiz, '2026-11-01', ['emor_durumu' => 'islendi']);
        $f($izibiz, '2026-11-01', ['durum' => 'REJECTED']);
        $f($izibiz, '2026-11-01', ['gizlenme_zamani' => '2026-11-02 08:00:00']);
        $f($protel, '2026-11-05', ['durum' => 'Received', 'erp_okundu' => null]);
        $logo = app(EntegratorBaglantiServisi::class)->statikTanim('logo');
        $f($logo, '2026-11-01', ['kaynak_id' => null, 'belge_anahtari' => 'x', 'durum' => 'Alındı']); // portal seçili değil

        $this->assertSame([], $this->degerlendir()); // saatten önce

        $this->travelTo('2026-11-25 06:30:00'); // 09:30 İstanbul
        [$bildirim] = $this->degerlendir();
        $this->assertSame(AlarmBildirimi::BEKLIYOR, $bildirim->durum);
        $this->assertSame([
            'esik_gun' => 3,
            'kesim' => '2026-11-22',
            'toplam' => 3,
            'portallar' => [
                ['saglayici' => 'protel', 'adet' => 1, 'en_eski' => '2026-11-05'],
                ['saglayici' => 'izibiz', 'adet' => 2, 'en_eski' => '2026-11-10'],
            ],
            'islendi_sayilanlar' => ['emor_islendi', 'emor_elle_islendi', 'sap_islendi'],
        ], $bildirim->ayrinti);
        $bildirim->update(['durum' => AlarmBildirimi::GONDERILDI]);

        // Aynı gün ve iki gün sonra yeni hatırlatma yok; üçüncü gün var
        $this->assertSame([], $this->degerlendir());
        $this->travelTo('2026-11-27 07:00:00');
        $this->assertSame([], $this->degerlendir());
        $this->travelTo('2026-11-28 07:00:00');
        $this->assertCount(1, $this->degerlendir());

        // Bekleyen kalmadıysa mail gitmez (atlandı), ertesi gün yeniden bakılır
        EFatura::query()->update(['emor_durumu' => 'islendi']);
        $this->travelTo('2026-12-01 07:00:00');
        $this->assertSame([], $this->degerlendir());
        $this->assertSame('ISLENECEK_YOK', AlarmBildirimi::query()->where('alarm_kurali_id', $kural->id)->latest('id')->value('hata_kodu'));
        $this->travelTo('2026-12-02 07:00:00');
        $this->degerlendir();
        $this->assertSame(2, AlarmBildirimi::query()->where('alarm_kurali_id', $kural->id)->where('durum', AlarmBildirimi::ATLANDI)->count());
    }

    /**
     * Eşik alınma GÜNÜNE (İstanbul) göre: bugün 26.11, gun=3 → 23.11 23:59
     * İstanbul'da alınan sayılır, 24.11 00:00'da alınan sayılmaz — belge
     * tarihi ne olursa olsun. Alınma zamanı olmayan statik kaynakta (e-Arşiv)
     * belge tarihine bakılır.
     */
    public function test_islenmemis_hatirlatmasi_esigi_istanbul_takvimiyle_alinma_gunune_gore_sayar(): void
    {
        $this->travelTo('2026-11-26 06:30:00'); // 09:30 İstanbul
        $izibiz = $this->tanim('canli');
        $earsiv = app(EntegratorBaglantiServisi::class)->statikTanim('earsiv');
        $this->kural(AlarmKurali::ISLENMEMIS_HATIRLATMA, ['gun' => 3, 'portallar' => 'izibiz,earsiv']);
        $f = fn (EntegratorBaglanti $t, array $alanlar) => EFatura::factory()->create(['entegrator_baglanti_id' => $t->id, 'emor_durumu' => null, ...$alanlar]);
        $f($izibiz, ['belge_tarihi' => '2026-11-24', 'olusturma_zamani' => '2026-11-23 20:59:00']); // 23.11 23:59 İstanbul
        $f($izibiz, ['belge_tarihi' => '2026-11-01', 'olusturma_zamani' => '2026-11-23 21:00:00']); // 24.11 00:00 İstanbul
        $f($earsiv, ['kaynak_id' => null, 'belge_anahtari' => 'a', 'belge_tarihi' => '2026-11-23', 'olusturma_zamani' => null, 'durum' => null]);
        $f($earsiv, ['kaynak_id' => null, 'belge_anahtari' => 'b', 'belge_tarihi' => '2026-11-24', 'olusturma_zamani' => null, 'durum' => null]);

        [$bildirim] = $this->degerlendir();

        $this->assertSame('2026-11-23', $bildirim->ayrinti['kesim']);
        $this->assertSame([
            ['saglayici' => 'izibiz', 'adet' => 1, 'en_eski' => '2026-11-23'],
            ['saglayici' => 'earsiv', 'adet' => 1, 'en_eski' => '2026-11-23'],
        ], $bildirim->ayrinti['portallar']);
    }

    /**
     * "İşlendi sayılanlar" ekrandan seçilir; seçilen her durum faturayı
     * sayımdan çıkarır, RED her durumda çıkar. Adetler durum başına farklı
     * tutulur ki hangi durumun çıktığı toplamdan anlaşılsın.
     *
     * @param  string|null  $secim  null: parametre kayıtlı değil (eski kural) → varsayılan
     */
    #[DataProvider('islendiSecimleri')]
    public function test_islenmemis_hatirlatmasi_secilen_islendi_durumlarini_saymaz(?string $secim, int $beklenen): void
    {
        $this->travelTo('2026-11-26 06:30:00');
        $izibiz = $this->tanim('canli');
        $kural = $this->kural(AlarmKurali::ISLENMEMIS_HATIRLATMA, ['portallar' => 'izibiz']);
        $parametreler = $kural->parametreler;
        unset($parametreler['islendi_sayilanlar']);
        $kural->update(['parametreler' => $secim === null ? $parametreler : [...$parametreler, 'islendi_sayilanlar' => $secim]]);
        $f = fn (int $adet, array $alanlar) => EFatura::factory()->count($adet)->create(['entegrator_baglanti_id' => $izibiz->id, 'olusturma_zamani' => '2026-11-10 09:00:00', 'belge_tarihi' => '2026-11-10', ...$alanlar]);
        $f(1, ['emor_durumu' => 'islendi']);
        $f(2, ['emor_durumu' => 'elle_islendi']);
        $f(3, ['emor_durumu' => 'havuzda']);
        $f(4, ['emor_durumu' => 'yok', 'sap_islendi' => true]);
        $f(3, ['emor_durumu' => null, 'sap_islendi' => null]);
        $f(2, ['emor_durumu' => 'yok', 'sap_islendi' => false]);
        $f(1, ['emor_durumu' => null, 'durum' => 'REJECTED']);

        [$bildirim] = $this->degerlendir();

        $this->assertSame($beklenen, $bildirim->ayrinti['toplam']);
    }

    /**
     * @return array<string, array{string|null, int}>
     */
    public static function islendiSecimleri(): array
    {
        return [
            'eMOR işlendi' => ['emor_islendi', 14],
            'eMOR elle işlendi' => ['emor_elle_islendi', 13],
            'eMOR havuzda' => ['emor_havuzda', 12],
            'SAP işlendi' => ['sap_islendi', 11],
            'kayıtsız: varsayılan (havuz sayılır)' => [null, 8],
            'hepsi' => ['emor_islendi,emor_elle_islendi,emor_havuzda,sap_islendi', 5],
        ];
    }

    public function test_islenmemis_hatirlatma_mailinde_portal_sayilari_var_fatura_bilgisi_yok(): void
    {
        $bildirim = new AlarmBildirimi([
            'tur' => AlarmBildirimi::TUR_OZET,
            'ayrinti' => [
                'esik_gun' => 3,
                'kesim' => '2026-11-22',
                'toplam' => 3,
                'portallar' => [['saglayici' => 'protel', 'adet' => 1, 'en_eski' => '2026-11-05'], ['saglayici' => 'izibiz', 'adet' => 2, 'en_eski' => '2026-11-10']],
                'islendi_sayilanlar' => ['emor_havuzda', 'sap_islendi'],
            ],
        ]);

        $mail = new AlarmMaili($bildirim, AlarmKurali::ISLENMEMIS_HATIRLATMA, 'canli');
        $html = $mail->render();

        $this->assertSame('eMOR ERP — 3 günden eski 3 fatura henüz işlenmedi', $mail->envelope()->subject);
        $this->assertStringContainsString('Portal Plus (Protel): 1 fatura (en eskisi 05.11.2026)', $html);
        $this->assertStringContainsString('İzibiz: 2 fatura (en eskisi 10.11.2026)', $html);
        $this->assertStringContainsString('Entegratöre alınma tarihi 22.11.2026 ve öncesi', $html);
        $this->assertStringContainsString('(eMOR: Havuzda, SAP: İşlendi (elle))', $html);
        $this->assertStringContainsString(rtrim((string) config('app.url'), '/').'/efatura/pano', $html);
    }

    public function test_alarm_maili_kopya_ve_gizli_alicilara_da_gonderilir(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Mail::fake();
        $this->mailAyari(null);
        $tanim = $this->tanim('canli');
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1])
            ->update(['alicilar_cc' => ['mudur@ornek.test'], 'alicilar_bcc' => ['arsiv@ornek.test']]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI']);
        [$bildirim] = $this->degerlendir();

        $this->gonder($bildirim);

        Mail::assertSent(AlarmMaili::class, fn (AlarmMaili $mail): bool => $mail->hasTo('muhasebe@ornek.test')
            && $mail->hasCc('mudur@ornek.test')
            && $mail->hasBcc('arsiv@ornek.test'));
    }

    public function test_yonlendirmede_kopya_ve_gizli_alicilara_mail_gitmez(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Mail::fake();
        $this->mailAyari();
        $tanim = $this->tanim('canli');
        $this->kural(AlarmKurali::SENKRON_ARIZASI, ['ardisik_hata' => 1])
            ->update(['alicilar_cc' => ['mudur@ornek.test'], 'alicilar_bcc' => ['arsiv@ornek.test']]);
        $this->calisma($tanim, 'giden');
        $this->calisma($tanim, 'gelen', ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI']);
        [$bildirim] = $this->degerlendir();

        $this->gonder($bildirim);

        Mail::assertSent(AlarmMaili::class, fn (AlarmMaili $mail): bool => $mail->hasTo('test-kutusu@ornek.test')
            && $mail->cc === [] && $mail->bcc === []
            && ! $mail->hasTo('muhasebe@ornek.test'));
    }

    public function test_kural_kopya_gizli_alicilar_ve_islendi_secenekleriyle_kaydedilir_gonderilmeyen_kopya_korunur(): void
    {
        $yonetici = User::factory()->yonetici()->create();

        $this->actingAs($yonetici)
            ->putJson('/api/v1/ayarlar/alarm-kurallari/islenmemis_hatirlatma', [
                'aktif' => true,
                'alicilar' => ['muhasebe@ornek.test'],
                'alicilar_cc' => ['Mudur@Ornek.test', 'mudur@ornek.test'],
                'alicilar_bcc' => ['arsiv@ornek.test'],
                'parametreler' => ['gun' => 5, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => 'izibiz', 'islendi_sayilanlar' => 'emor_havuzda,sap_islendi'],
            ])
            ->assertOk()
            ->assertJsonPath('data.alicilar_cc', ['mudur@ornek.test'])
            ->assertJsonPath('data.alicilar_bcc', ['arsiv@ornek.test'])
            ->assertJsonPath('data.parametreler.islendi_sayilanlar', 'emor_havuzda,sap_islendi');
        $this->actingAs($yonetici)
            ->putJson('/api/v1/ayarlar/alarm-kurallari/islenmemis_hatirlatma', [
                'aktif' => true,
                'alicilar' => ['muhasebe@ornek.test'],
                'parametreler' => ['gun' => 5, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => 'izibiz', 'islendi_sayilanlar' => 'sap_islendi'],
            ])
            ->assertOk();

        $kural = AlarmKurali::query()->where('tur', 'islenmemis_hatirlatma')->firstOrFail();
        $this->assertSame(['mudur@ornek.test'], $kural->alicilar_cc);
        $this->assertSame(['arsiv@ornek.test'], $kural->alicilar_bcc);
        $this->assertSame('sap_islendi', $kural->parametreler['islendi_sayilanlar']);
    }

    #[DataProvider('gecersizIslendiSecimleri')]
    public function test_islenmemis_hatirlatmasi_gecersiz_islendi_secimiyle_kaydedilmez(string $secim): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/alarm-kurallari/islenmemis_hatirlatma', [
                'aktif' => false,
                'alicilar' => [],
                'parametreler' => ['gun' => 3, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => 'izibiz', 'islendi_sayilanlar' => $secim],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parametreler.islendi_sayilanlar' => 'İşlendi sayılacak en az bir durum seçilmeli.'], 'hatalar');

        $this->assertDatabaseMissing('alarm_kurallari', ['tur' => 'islenmemis_hatirlatma']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gecersizIslendiSecimleri(): array
    {
        return [
            'boş' => [''],
            'bilinmeyen durum' => ['emor_islendi,emor_red'],
            'sonda virgül' => ['emor_islendi,'],
            'yanlış ayraç' => ['emor_islendi;sap_islendi'],
        ];
    }

    public function test_gecersiz_kopya_ya_da_gizli_alici_adresiyle_kaydedilmez(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/alarm-kurallari/gunluk_ozet', [
                'aktif' => true,
                'alicilar' => ['muhasebe@ornek.test'],
                'alicilar_cc' => ['mudur-ornek.test'],
                'alicilar_bcc' => ['arsiv@'],
                'parametreler' => ['saat' => '08:00'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'alicilar_cc.0' => 'Geçerli bir e-posta adresi girin.',
                'alicilar_bcc.0' => 'Geçerli bir e-posta adresi girin.',
            ], 'hatalar');

        $this->assertDatabaseMissing('alarm_kurallari', ['tur' => 'gunluk_ozet']);
    }

    public function test_islenmemis_hatirlatmasi_portalsiz_kaydedilmez(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/alarm-kurallari/islenmemis_hatirlatma', [
                'aktif' => true,
                'alicilar' => ['muhasebe@ornek.test'],
                'parametreler' => ['gun' => 3, 'aralik_gun' => 3, 'saat' => '09:00', 'portallar' => ''],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parametreler.portallar' => 'En az bir portal seçilmeli.'], 'hatalar');
    }

    public function test_aktif_kural_alicisiz_ve_hatali_saatle_kaydedilmez(): void
    {
        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson('/api/v1/ayarlar/alarm-kurallari/gunluk_ozet', [
                'aktif' => true,
                'alicilar' => [],
                'parametreler' => ['saat' => '25:00'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'alicilar' => 'Aktif kural için en az bir alıcı gerekir.',
                'parametreler.saat' => 'Saat SS:DD biçiminde olmalı (ör. 08:30).',
            ], 'hatalar');
    }
}
