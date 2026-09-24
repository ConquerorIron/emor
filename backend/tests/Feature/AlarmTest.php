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
use App\Services\MailAyarServisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
            ->assertJsonPath('data.2.parametreler', ['gun' => 2, 'saat' => '09:00'])
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
