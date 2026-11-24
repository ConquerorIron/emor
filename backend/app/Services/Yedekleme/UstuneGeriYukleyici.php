<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\GeriYukleme;
use App\Models\User;
use App\Models\Yedek;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Üstüne geri yükleme (README §6.4, §4.5-B, §7.1–§7.6; BY-028, BY-029).
 *
 * Durum makinesi işlem durumu DOSYASINDADIR (veritabanı değişir): her adım
 * işi yapılmadan önce yazılır (UstuneGeriYuklemeAdimi). Kurtarma bağlamı
 * (yedek, hedef ve önceki veritabanı adı, isteyen) İLK yazımda doludur.
 *
 * 1. Ön denetim: servis çalıştırıcısı, onay metni = canlı veritabanı adı,
 *    imzalı + tamamlanmış yedek, manifest imzası ve dosya özeti dosyadan
 *    yeniden, şema uyumu (yedeğin TAM migration listesi kodda olmalı),
 *    APP_KEY parmak izi (YF-13), manifestten bilinen retler — veritabanı
 *    özellikleri, araç / sunucu ana sürümü, bağlanma yetkilerindeki rollerin
 *    varlığı (YF-14) —, canlının kimliğine bağlı özellikleri (YF-45), disk,
 *    adların boşluğu, uygulama bakım modunda değil. Bunlar hiçbir şeye
 *    dokunmadan bilinir: kesinti başlamadan reddedilir.
 * 2. Bakım modu (BakimModu), kuyruk servisi durur (KuyrukServisi);
 *    zamanlayıcı ve işçi açık işlemi dosyadan da görür (AcikGeriYuklemeKorumasi).
 *    Yazıcı bariyeri (BY-028-R1): canlının özgün bağlanma yetkileri durum
 *    dosyasına yazılır, CONNECT yalnız servis rolüne bırakılır, bu süreç
 *    dışındaki oturumlar sonlandırılır ve bitmeleri beklenir. Başlamış web
 *    isteği / zamanlanmış komut artık öncesi yedekten sonra yazamaz.
 * 3. Geri yükleme öncesi yedek — bakım modundan ve bariyerden SONRA (P3);
 *    anlık görüntü bağlantısı servis rolüyle, manifesti ÖZGÜN yetkileri taşır.
 * 4. `erp_geri_*`'ye manifestteki özelliklerle (bağlanma yetkileri dahil —
 *    BY-026-R1) geri yükle, manifestle karşılaştır.
 * 5. Migration ayrı süreçte, yalnız yeni veritabanında; sağlık denetimi.
 *    Ayarların dosya kopyası tazelenir; önceki tarafın onay özeti
 *    (OnayBilgisi) bu noktada alınır.
 * 6. Canlıyı yeni bağlantılara kapat, mevcutları kopar.
 * 7. Adları tek işlemde değiştir (canlı → `erp_onceki_*`, yeni → canlı).
 * 8. `erp_onceki_*`'e özgün yetkiler geri konur (bağlantıya kapalı kalır);
 *    dizini klasörden kur; `geri_yuklemeler`'i uzlaştır (kaydı olmayan
 *    bekleyen kopya, kapanmamış eski işlem — YF-42); geçmiş kaydını dosyadan yeniden yaz; ayar /
 *    kullanıcı / rol farkını hesapla.
 * 9. "Onay bekliyor": bakım modu sürer. İşleyici burada DÖNER (tek
 *    çalıştırıcı kilidi: dönmezse onay isteği hiç işlenemezdi).
 * 10. Onay: farkı olan her ayar grubu için yöneticinin seçimi zorunlu;
 *     `guncel` seçilenler veritabanına yazılır (AyarSecimi), kuyruk başlar,
 *     bakım modundan çıkılır. Geri dön: adlar eski hâline (geri yüklenen
 *     kopya `erp_geri_*` adıyla, silinmeden), canlıya özgün yetkiler, açılır.
 *
 * Hata kuralı: 7. adımdan önceki hatada canlı yerindedir — yeni veritabanı
 * silinir, servisler açılır (geri sarma). 7. adımdan sonra hiçbir hata yolu
 * uygulamayı açmaz; karar yöneticinindir (onay / geri dön / `yedek:acil`).
 * Yer değiştirmenin yapılıp yapılmadığı şüpheliyse adlara bakılır; adlar
 * da tutarsızsa hiçbir şey yapılmaz.
 */
final class UstuneGeriYukleyici
{
    private const BEKLENMEYEN_HATA = 'GERI_YUKLEME_BEKLENMEYEN_HATA';

    /** Önceki özetteki (OnayBilgisi) bekleyen kopya kayıtlarının anahtarı (YF-42) */
    private const KOPYA_KAYITLARI = 'bekleyen_kopya_kayitlari';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly PgAraclari $pg,
        private readonly GeriYuklemeVeritabani $vt,
        private readonly GeriYukleyici $geriYukleyici,
        private readonly YedekAlici $yedekAlici,
        private readonly YeniVeritabanindaMigration $migration,
        private readonly BakimModu $bakim,
        private readonly KuyrukServisi $kuyruk,
        private readonly OnayBilgisi $onayBilgisi,
        private readonly DizinKurucu $dizin,
        private readonly ManifestDeposu $manifestler,
        private readonly YedekDenetimi $denetim,
        private readonly YedekBildirimcisi $bildirimci,
        private readonly AyarKopyasi $ayarKopyasi,
        private readonly AyarSecimi $ayarSecimi,
    ) {}

    /**
     * İşlemi başlatır ve onay adımına (ya da hataya) kadar yürütür.
     *
     * @param  YedekKilidi  $kilit  Durum klasörünün tutulan kilidi
     * @param  string  $islemKimligi  İşlemin kimliği (isteğin kimliği; ekran onunla yoklar)
     * @return IslemKaydi Son durum: onay_bekliyor, basarisiz (geri sarıldı) ya da yer değiştirmeden sonra hatalı
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi İşlem başlamadı: erişim anahtarının süresi dolmuş, açık işlem var ya da durum okunamadı (durum dosyasına dokunulmadı)
     */
    public function baslat(YedekKilidi $kilit, Yedek $yedek, string $onayMetni, int $kullaniciId, string $islemKimligi, string $anahtarOzeti, CarbonImmutable $anahtarBitis): IslemKaydi
    {
        $this->kilitDenetle($kilit);

        // Dolmuş anahtarla başlayan işlemin ilerlemesi, onayı ve geri dönüşü
        // ekrandan yapılamazdı (YF-02): ilk yazımdan önce reddedilir
        if (! $anahtarBitis->isFuture()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU, 'Erişim anahtarının süresi dolmuş; üstüne geri yükleme başlamadı. Yeniden isteyin.');
        }

        $durum = $this->yapilandirma->islemDurumu();

        try {
            $mevcut = $durum->oku();
        } catch (IslemDurumuBozuk) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_OKUNAMADI, 'İşlem durumu okunamadı; geri yükleme başlamadı.');
        }

        if ($mevcut !== null && $mevcut->tur === IslemTuru::GeriYuklemeUstune && $mevcut->acik) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ACIK_GERI_YUKLEME, 'Açık bir üstüne geri yükleme var; yenisi başlamadı.');
        }

        if ($mevcut?->kimlik === $islemKimligi) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, 'Bu işlem zaten başlatılmış.');
        }

        $zaman = CarbonImmutable::now('UTC');
        // İlk yazım kurtarma bağlamının tamamını taşır (README §6.4, BY-028)
        $kayit = new IslemKaydi(
            kimlik: $islemKimligi,
            tur: IslemTuru::GeriYuklemeUstune,
            adim: UstuneGeriYuklemeAdimi::OnDenetim->value,
            acik: false,
            baslangic: $zaman,
            sonGuncelleme: $zaman,
            hata: null,
            erisimAnahtariOzeti: $anahtarOzeti,
            erisimAnahtariBitis: $anahtarBitis,
            yedekKimligi: $yedek->kimlik,
            hedefVeritabani: VeritabaniAdi::kopya($zaman),
            oncekiVeritabani: VeritabaniAdi::onceki($zaman),
            isteyenKullaniciId: $kullaniciId,
        );
        $durum->yaz($kayit, $kilit);

        try {
            [$manifest, $dosya, $gecici] = $this->onDenetim($yedek, $onayMetni, $kayit);
        } catch (Throwable $hata) {
            $bilgi = $this->hataBilgisi($hata, self::BEKLENMEYEN_HATA);
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Basarisiz, false, $bilgi);
            $this->denetle('geri_yukle_ustune', IslemSonucu::Basarisiz, $kayit, ['kod' => $bilgi['kod']], $bilgi['mesaj']);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["{$bilgi['kod']}: {$bilgi['mesaj']}", 'Ön denetimde durdu; hiçbir şey değişmedi.']);

            return $kayit;
        }

        try {
            $geri = GeriYukleme::query()->create([
                'kimlik' => $kayit->kimlik,
                'yedek_id' => $yedek->id,
                'tur' => GeriYuklemeTuru::Ustune,
                'hedef_veritabani' => $kayit->hedefVeritabani,
                'onceki_veritabani' => $kayit->oncekiVeritabani,
                'isteyen_kullanici_id' => $kullaniciId,
                'durum' => GeriYuklemeDurumu::Calisiyor,
                'adimlar' => [],
                'basladi' => $zaman,
            ]);
            $this->bildir('geri_yukleme_basladi', $kayit, ["{$yedek->dosya_adi} → {$this->canli()}"]);

            return $this->ileri($kilit, $kayit, $manifest, $dosya, $geri);
        } finally {
            if ($gecici !== null) {
                $this->geriYukleyici->geciciSil($gecici);
            }
        }
    }

    /**
     * Yarıda kalmış işlemi (süreç öldü) sürdürür ya da geri sarar (README
     * §6.4 "Yarıda kalan işlem"). Kilidi kendisi alır; alamazsa (işlem
     * yürüyor, `yedek:acil` çalışıyor) bir şey yapmaz. Hatayla durmuş ve
     * kararı yöneticiye bırakılmış işleme dokunmaz.
     *
     * @return bool Bir şey yapıldı mı
     */
    public function yaridaKalaniIsle(): bool
    {
        $durum = $this->yapilandirma->islemDurumu();

        try {
            if (! $this->surdurulecekMi($durum->oku())) {
                return false;
            }
        } catch (IslemDurumuBozuk $hata) {
            Log::channel('yedekleme')->error('Üstüne geri yükleme: işlem durumu okunamadı; sürdürülmedi', ['hata' => $hata->getMessage()]);

            return false;
        }

        $kilit = $durum->kilit();
        if (! $kilit->al()) {
            return false;
        }

        try {
            // Kilit alınırken durum değişmiş olabilir: kilit altında yeniden okunur
            $kayit = $durum->oku();
            if (! $this->surdurulecekMi($kayit)) {
                return false;
            }

            /** @var IslemKaydi $kayit */
            $adim = UstuneGeriYuklemeAdimi::from($kayit->adim);
            $yarida = ['kod' => GeriYuklemeHatasi::YARIDA_KALDI, 'mesaj' => 'Süreç '.$adim->value.' adımında durdu.'];
            Log::channel('yedekleme')->warning('Üstüne geri yükleme yarıda kalmış; sürdürülüyor', ['islem' => $kayit->kimlik, 'adim' => $adim->value]);

            match (true) {
                $adim === UstuneGeriYuklemeAdimi::OnDenetim => $this->onDenetimdeKalaniKapat($kilit, $kayit, $yarida),
                $adim->yerDegistirmedenOnceMi() => $this->geriSar($kilit, $kayit, $kayit->hata ?? $yarida),
                $adim === UstuneGeriYuklemeAdimi::YerDegistirme => $this->adlaraGoreSurdur($kilit, $kayit, $kayit->hata ?? $yarida),
                $adim === UstuneGeriYuklemeAdimi::Onaylaniyor => $this->onaylaTamamla($kilit, $kayit),
                $adim === UstuneGeriYuklemeAdimi::GeriDonuluyor => $this->geriDonusuTamamla($kilit, $kayit),
                default => $this->yerDegistirmedenSonra($kilit, $kayit, $adim),
            };

            return true;
        } catch (Throwable $hata) {
            report($hata);

            return false;
        } finally {
            $kilit->birak();
        }
    }

    /**
     * Yöneticinin onayı (README §6.4 10. adım, §5.3): farkı olan her ayar
     * grubu için seçim zorunludur (varsayılan yok); seçim durum dosyasına
     * yazılır, `guncel` seçilen gruplar sistem açılmadan geri yüklenen
     * veritabanına yazılır (yazılamazsa onay durur, uygulama kapalı kalır,
     * karar yine yöneticinin). Sonra kuyruk başlar, bakım modundan çıkılır,
     * işlem kapanır, bildirim doğrudan gider. Veritabanına yazılanlar (geçmiş,
     * denetim) en iyi çabadır: veritabanına erişilemese de "geri yüklenen"
     * seçimleriyle onay tamamlanır (`yedek:acil` — BY-029).
     *
     * @param  array<string, string>  $ayarSecimleri  grup => guncel | geri_yuklenen (AyarSecimi)
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi ISLEM_DURUMU_UYGUN_DEGIL: kimlik tutmuyor ya da yer değiştirme yapılmamış; AYAR_SECIMI_EKSIK: farkı olan grupta seçim yok (hiçbir şey değişmedi)
     */
    public function onayla(YedekKilidi $kilit, string $islemKimligi, array $ayarSecimleri = []): IslemKaydi
    {
        $this->kilitDenetle($kilit);
        [$kayit, $adim] = $this->kararVerilecekIslem($islemKimligi);

        if (! $adim->yerDegistirmedenSonraMi() || $adim === UstuneGeriYuklemeAdimi::GeriDonuluyor) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, 'Onay yalnız yer değiştirmeden sonra verilir (şu anki adım: '.$adim->value.').');
        }

        // Onay başlamışsa (kuyruk / bakım adımında durmuş) seçim zaten uygulanmıştır
        if ($adim !== UstuneGeriYuklemeAdimi::Onaylaniyor) {
            $eksik = array_values(array_diff($this->secimGerekenGruplar($kayit->kimlik), array_keys($ayarSecimleri)));
            if (! AyarSecimi::gecerliMi($ayarSecimleri) || $eksik !== []) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_SECIMI_EKSIK, 'Farkı olan her ayar grubu için "güncel" ya da "geri yüklenen" seçilmeli (eksik: '.(implode(', ', $eksik) ?: 'geçersiz seçim').'); onay verilmedi.');
            }

            $kayit = $this->yaz($kilit, $kayit, $adim, true, $kayit->hata, ayarSecimleri: $ayarSecimleri);

            try {
                $uyarilar = $this->ayarSecimi->uygula($ayarSecimleri);
            } catch (Throwable $hata) {
                $bilgi = $this->hataBilgisi($hata, GeriYuklemeHatasi::AYAR_SECIMI_UYGULANAMADI);
                $kayit = $this->yaz($kilit, $kayit, $adim, true, $bilgi);
                $this->denetle('geri_yukle_ustune_ayar_secimi', IslemSonucu::Basarisiz, $kayit, ['kod' => $bilgi['kod'], 'ayar_secimleri' => $ayarSecimleri], $bilgi['mesaj']);
                $this->bildir('geri_yukleme_basarisiz', $kayit, ["Onay tamamlanamadı: {$bilgi['kod']}: {$bilgi['mesaj']}", 'Uygulama bakım modunda; seçimi değiştirip yeniden onaylayın ya da geri dönün.'], ':ayar');

                return $kayit;
            }

            $this->denetle('geri_yukle_ustune_ayar_secimi', IslemSonucu::Basarili, $kayit, ['ayar_secimleri' => $ayarSecimleri, 'uyarilar' => $uyarilar]);
            if ($uyarilar !== []) {
                $this->bildir('geri_yukleme_onay', $kayit, $uyarilar, ':ayar');
            }
        }

        return $this->onaylaTamamla($kilit, $kayit);
    }

    /**
     * Onay / geri dönüş kararının kanıtını tüketir (BY-030-R1): nonce işlem
     * durumuna yazılır; aynı kanıt ikinci kez kullanılamaz. Yalnız süresi
     * geçmiş nonce'lar düşer (onların kanıtı zaten reddedilir). Süresi
     * geçmemiş tüketilmiş kanıt asla unutulmaz (BY-030-R2, G18): liste
     * süresi geçmemişlerle doluysa yeni karar açık kodla reddedilir — kanıt
     * en çok KANIT_OMRU_SN yaşar, birkaç dakika sonra yer açılır.
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi KARAR_KANITI_KULLANILDI; KARAR_KAPASITESI_DOLU; işlem bu kimlikle açık değil
     */
    public function kanitTuket(YedekKilidi $kilit, string $islemKimligi, string $nonce, int $sonGecerlilik): void
    {
        $this->kilitDenetle($kilit);
        [$kayit, $adim] = $this->kararVerilecekIslem($islemKimligi);

        $simdi = CarbonImmutable::now()->getTimestamp();
        $kanitlar = array_filter($kayit->kullanilanKanitlar, fn (int $son): bool => $son >= $simdi);
        if (isset($kanitlar[$nonce])) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARAR_KANITI_KULLANILDI, 'Bu karar daha önce işlendi; yeniden uygulanmadı.');
        }

        if (count($kanitlar) >= IslemKaydi::EN_COK_KANIT) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARAR_KAPASITESI_DOLU, 'Kısa sürede çok fazla karar işlendi; bu karar uygulanmadı. Birkaç dakika sonra yeniden deneyin.');
        }

        $kanitlar[$nonce] = $sonGecerlilik;
        asort($kanitlar);

        $this->yaz($kilit, $kayit, $adim, $kayit->acik, $kayit->hata, kullanilanKanitlar: $kanitlar);
    }

    /**
     * "Geri dön" (README §6.4 10. adım): yer değiştirme yapıldıysa adlar eski
     * hâline çevrilir (geri yüklenen veritabanı kendi `erp_geri_*` adıyla
     * silinmeden kalır), eski veritabanıyla açılır. Yer değiştirme
     * yapılmadıysa yarıda kalmış işlem geri sarılır.
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi ISLEM_DURUMU_UYGUN_DEGIL
     */
    public function geriDon(YedekKilidi $kilit, string $islemKimligi): IslemKaydi
    {
        $this->kilitDenetle($kilit);
        [$kayit, $adim] = $this->kararVerilecekIslem($islemKimligi);
        $neden = ['kod' => 'GERI_YUKLEME_YONETICI_GERI_DONDU', 'mesaj' => 'Yönetici geri dönmeyi seçti.'];

        return match (true) {
            $adim === UstuneGeriYuklemeAdimi::Onaylaniyor => throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, 'Onay verilmiş; geri dönüş yeni bir üstüne geri yükleme olarak yapılır (README §6.4).'),
            $adim->yerDegistirmedenOnceMi() => $this->geriSar($kilit, $kayit, $kayit->hata ?? $neden),
            $adim === UstuneGeriYuklemeAdimi::YerDegistirme => $this->adlaraGoreGeriDon($kilit, $kayit, $kayit->hata ?? $neden),
            default => $this->geriDonusuTamamla($kilit, $kayit),
        };
    }

    // ---------------------------------------------------------------- akış

    /**
     * 2–7. adımlar. 7'den önceki her hata geri sarar.
     */
    private function ileri(YedekKilidi $kilit, IslemKaydi $kayit, YedekManifesti $manifest, string $dosya, GeriYukleme $geri): IslemKaydi
    {
        $hedef = (string) $kayit->hedefVeritabani;
        $adim = function (UstuneGeriYuklemeAdimi $a) use (&$kayit, $kilit, $geri): void {
            $kayit = $this->yaz($kilit, $kayit, $a, true);
            $geri->adimEkle($a, 'basladi');
        };

        try {
            $adim(UstuneGeriYuklemeAdimi::BakimModu);
            $this->bakim->gir($kayit->kimlik);
            $this->kuyruk->durdur();

            // Yazıcı bariyeri (BY-028-R1). Özgün yetkiler bariyerden ÖNCE durum
            // dosyasına: süreç ölürse geri sarma / geri dönüş onları geri koyar
            $adim(UstuneGeriYuklemeAdimi::YaziciBariyeri);
            $ozgun = $this->pgIle(fn () => $this->vt->yetkileriOku($this->canli()));
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::YaziciBariyeri, true, bariyer: ['baglanma_yetkileri' => $ozgun]);
            $haric = $this->kendiCanliOturumlari();
            $this->pgIle(fn () => $this->vt->bariyerKur($this->canli(), $this->servisRolu(), $haric));

            // Bakım modundan ve bariyerden SONRA: kabul edilmiş her yazı bu yedekte (P3).
            // Anlık görüntü bağlantısı servis rolüyle (CONNECT yalnız onda); manifest
            // daraltılmış değil ÖZGÜN yetkileri taşır — o yedekten geri dönüş doğru açılsın
            $adim(UstuneGeriYuklemeAdimi::OncesiYedek);
            $oncesi = $this->vt->servisRoluyle(
                $this->servisRolu(),
                (string) $this->yapilandirma->parolaDosyasi(),
                fn (): Yedek => $this->yedekAlici->al($kilit, YedekKaynagi::GeriYuklemeOncesi, 'Üstüne geri yükleme öncesi', $kayit->isteyenKullaniciId, [], $kayit->kimlik, ['baglanma_yetkileri' => $ozgun]),
            );
            if ($oncesi->durum !== YedekDurumu::Tamam) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ONCESI_YEDEK, 'Geri yükleme öncesi yedek alınamadı ('.($oncesi->hata_kodu ?? 'bilinmiyor').'); canlıya dokunulmadı.');
            }
            $geri->update(['oncesi_yedek_id' => $oncesi->id]);

            $adim(UstuneGeriYuklemeAdimi::Veritabani);
            $ozellikler = $manifest->veritabaniOzellikleri ?? $this->pgIle(fn () => $this->vt->kaynakOzellikleri());
            $ayarlar = GeriYuklemeVeritabani::ozellikleriDenetle($ozellikler);
            $this->pgIle(function () use ($hedef, $ozellikler, $ayarlar): void {
                if ($this->vt->varMi($hedef)) {
                    throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_VAR, "{$hedef} adlı veritabanı zaten var; ona dokunulmadı.");
                }
                $this->vt->olustur($hedef, $ozellikler);
                $this->vt->ayarla($hedef, $ayarlar);
                // Yedeğin bağlanma yetkileri (BY-026-R1): ad değişince yeni canlının yetkileri olur
                if ($ozellikler['baglanma_yetkileri'] !== null) {
                    $this->vt->yetkileriUygula($hedef, $ozellikler['baglanma_yetkileri']);
                }
            });

            $adim(UstuneGeriYuklemeAdimi::PgRestore);
            $this->pgIle(function () use ($hedef, $dosya, $manifest): void {
                $this->vt->geriYukle($hedef, $dosya);
                $this->geriYukleyici->karsilastir($manifest, $this->vt->icerik($hedef));
            });

            // Ayrı süreç, bütün veritabanı ayarı yeni veritabanını gösterir
            $adim(UstuneGeriYuklemeAdimi::Migration);
            $this->migration->calistir($hedef);

            $adim(UstuneGeriYuklemeAdimi::Dogrulama);
            $this->pgIle(fn () => $this->saglikDenetle($hedef));

            // "Güncel" ayar seçiminin kaynağı (README §5.3): dosya kopyası donmuş canlıdan
            // tazelenir; yazılamazsa yer değiştirmeden önce durulur
            $this->ayarKopyasi->guncelDegilseYaz();
            // Onay ekranının "önceki" yanı: bağlantılar kesilmeden önce, donmuş canlıdan.
            // Bekleyen kopyaların kayıtları da: geri yüklenen veritabanında olmayanlar
            // 8. adımda bundan geri kurulur (YF-42)
            $this->onayBilgisi->oncekiYaz($kayit->kimlik, [...$this->onayBilgisi->ozet(), self::KOPYA_KAYITLARI => $this->bekleyenKopyaKayitlari()]);

            // Buradan yer değiştirme bitene kadar canlıya (Eloquent) dokunulmaz
            $adim(UstuneGeriYuklemeAdimi::BaglantiKapatma);
        } catch (Throwable $hata) {
            return $this->geriSar($kilit, $kayit, $this->hataBilgisi($hata, $this->adimHataKodu($kayit)));
        }

        try {
            $this->canliBaglantilariniBirak();
            $this->pgIle(fn () => $this->vt->baglantilariKapat($this->canli()));

            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::YerDegistirme, true);
            $this->pgIle(fn () => $this->vt->yerDegistir([$this->canli(), (string) $kayit->oncekiVeritabani], [$hedef, $this->canli()]));
        } catch (Throwable $hata) {
            // Yer değiştirme tek işlemdir ama hata yanıtı kaybolmuş olabilir: adlara bakılır
            return $this->adlaraGoreSurdur($kilit, $kayit, $this->hataBilgisi($hata, $this->adimHataKodu($kayit)));
        }

        $this->canliBaglantilariniBirak();

        return $this->yerDegistirmedenSonra($kilit, $kayit, UstuneGeriYuklemeAdimi::DizinKurma);
    }

    /**
     * 8–9. adımlar. Hata uygulamayı AÇMAZ: adım hatayla kalır, karar
     * yöneticinindir.
     */
    private function yerDegistirmedenSonra(YedekKilidi $kilit, IslemKaydi $kayit, UstuneGeriYuklemeAdimi $baslangic): IslemKaydi
    {
        try {
            if ($baslangic === UstuneGeriYuklemeAdimi::DizinKurma) {
                $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::DizinKurma, true);
                // Eski veritabanına bariyerden önceki yetkileri (bağlantıya kapalı kalır)
                $this->pgIle(fn () => $this->ozgunYetkileriGeriKoy($kayit, (string) $kayit->oncekiVeritabani));
                // Geri yüklenen veritabanındaki dizin yedeğin anındadır (§7.3)
                $this->dizin->tara($kilit);
                $uzlastirma = $this->kayitlariUzlastir($kayit);
            }

            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::AyarUzlastirma, true);
            // Eski veritabanındaki kayıt yer değiştirmeyle gitti: dosyadan yeniden yazılır (§7.3)
            $this->gecmisiYenidenYaz($kayit, GeriYuklemeDurumu::OnayBekliyor);
            $this->onayBilgisi->bilgiYaz($kayit->kimlik, $this->onayBilgisi->karsilastir($this->onayBilgisi->oncekiOku($kayit->kimlik), $this->onayBilgisi->ozet()));
        } catch (Throwable $hata) {
            $bilgi = $this->hataBilgisi($hata, $this->adimHataKodu($kayit));
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::from($kayit->adim), true, $bilgi);
            $this->denetle('geri_yukle_ustune', IslemSonucu::Basarisiz, $kayit, ['kod' => $bilgi['kod'], 'adim' => $kayit->adim], $bilgi['mesaj']);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["{$bilgi['kod']}: {$bilgi['mesaj']}", 'Yer değiştirme yapıldı; uygulama bakım modunda. Onaylayın ya da geri dönün (ekran ya da yedek:acil).']);

            return $kayit;
        }

        $uzlastirma ??= [];
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::OnayBekliyor, true);
        $this->denetle('geri_yukle_ustune', IslemSonucu::Basarili, $kayit, ['adim' => $kayit->adim, ...$uzlastirma]);
        $this->bildir('geri_yukleme_onay', $kayit, [
            "{$this->canli()} geri yüklendi; eski veritabanı {$kayit->oncekiVeritabani} adıyla bekliyor. Onaylayın ya da geri dönün.",
            ...(($uzlastirma['kaydi_olmayan_kopyalar'] ?? []) === [] ? [] : ['Sunucuda kaydı olmayan geri yükleme kopyası var (kaynağı bilinmiyor; elle inceleyin): '.implode(', ', $uzlastirma['kaydi_olmayan_kopyalar'])]),
        ]);

        return $kayit;
    }

    /**
     * Geri sarma (7. adımdan önce): yeni veritabanı silinir, canlı yeniden
     * bağlantıya açılır, kuyruk başlar, bakım modundan çıkılır. Adlar yer
     * değiştirmenin yapıldığını gösteriyorsa (önceki ad var) HİÇBİR ŞEY
     * yapılmaz. Kendi hatası işlemi açık bırakır (çalıştırıcı yeniden dener).
     *
     * @param  array{kod: string, mesaj: string}  $hata  Geri sarmaya yol açan hata
     */
    private function geriSar(YedekKilidi $kilit, IslemKaydi $kayit, array $hata): IslemKaydi
    {
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriSariliyor, true, $hata);
        $canli = $this->canli();
        $hedef = (string) $kayit->hedefVeritabani;
        $silindi = false;

        try {
            $this->pgIle(function () use ($canli, $hedef, $kayit, &$silindi): void {
                $mevcut = $this->vt->mevcutlar([$canli, $hedef, (string) $kayit->oncekiVeritabani]);
                if (in_array($kayit->oncekiVeritabani, $mevcut, true) || ! in_array($canli, $mevcut, true)) {
                    throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ADLAR_TUTARSIZ, 'Adlar yer değiştirmenin yapıldığını gösteriyor; geri sarılmadı.');
                }

                if (in_array($hedef, $mevcut, true)) {
                    $this->vt->dusur($hedef);
                    $silindi = true;
                }
                // Yazıcı bariyeri kurulduysa özgün yetkiler aynen geri (BY-028-R1)
                $this->ozgunYetkileriGeriKoy($kayit, $canli);
                $this->vt->baglantiyaAc($canli);
            });
            $this->kuyruguBaslat($kayit);
            $this->bakim->cik($kayit->kimlik);
        } catch (Throwable $ikinci) {
            $bilgi = $this->hataBilgisi($ikinci, GeriYuklemeHatasi::VERITABANI_SILINEMEDI);
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriSariliyor, true, [
                'kod' => $bilgi['kod'],
                'mesaj' => Str::limit("Geri sarma tamamlanamadı: {$bilgi['mesaj']} (ilk hata: {$hata['kod']})", 500),
            ]);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["{$hata['kod']}: {$hata['mesaj']}", "Geri sarma tamamlanamadı ({$bilgi['kod']}); uygulama bakım modunda kalabilir."]);

            return $kayit;
        }

        $this->sessiz(fn () => GeriYukleme::query()->where('kimlik', $kayit->kimlik)->update([
            'durum' => GeriYuklemeDurumu::Basarisiz->value,
            'hata_kodu' => $hata['kod'],
            'hata_mesaji' => $hata['mesaj'],
            'bitti' => CarbonImmutable::now(),
            'veritabani_silinme_zamani' => $silindi ? CarbonImmutable::now() : null,
        ]));
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Basarisiz, false, $hata);
        $this->denetle('geri_yukle_ustune', IslemSonucu::Basarisiz, $kayit, ['kod' => $hata['kod'], 'geri_sarildi' => true], $hata['mesaj']);
        $this->bildir('geri_yukleme_basarisiz', $kayit, ["{$hata['kod']}: {$hata['mesaj']}", 'Canlı veritabanına dokunulmadı; uygulama yeniden açıldı.']);

        return $kayit;
    }

    /**
     * @param  array{kod: string, mesaj: string}  $hata
     */
    private function adlaraGoreSurdur(YedekKilidi $kilit, IslemKaydi $kayit, array $hata): IslemKaydi
    {
        return match ($this->adlar($kayit)) {
            'yer_degisti' => $this->yerDegistirmedenSonra($kilit, $kayit, UstuneGeriYuklemeAdimi::DizinKurma),
            'yerinde' => $this->geriSar($kilit, $kayit, $hata),
            'okunamadi' => $this->adlarOkunamadi($kilit, $kayit, $hata),
            default => $this->adlarTutarsiz($kilit, $kayit),
        };
    }

    /**
     * @param  array{kod: string, mesaj: string}  $hata
     */
    private function adlaraGoreGeriDon(YedekKilidi $kilit, IslemKaydi $kayit, array $hata): IslemKaydi
    {
        return match ($this->adlar($kayit)) {
            'yer_degisti' => $this->geriDonusuTamamla($kilit, $kayit),
            'yerinde' => $this->geriSar($kilit, $kayit, $hata),
            'okunamadi' => $this->adlarOkunamadi($kilit, $kayit, $hata),
            default => $this->adlarTutarsiz($kilit, $kayit),
        };
    }

    private function onaylaTamamla(YedekKilidi $kilit, IslemKaydi $kayit): IslemKaydi
    {
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Onaylaniyor, true);
        // 8. adım hatayla durmuş olabilir: eski veritabanının yetkileri ve dizin en iyi çabayla
        $this->sessiz(fn () => $this->pgIle(fn () => $this->ozgunYetkileriGeriKoy($kayit, (string) $kayit->oncekiVeritabani)));
        $this->sessiz(fn () => $this->dizin->tara($kilit));
        $this->sessiz(fn () => $this->kayitlariUzlastir($kayit));
        $this->sessiz(fn () => $this->gecmisiYenidenYaz($kayit, GeriYuklemeDurumu::Tamam));

        try {
            $this->kuyruguBaslat($kayit);
            if (! $this->bakim->cik($kayit->kimlik)) {
                Log::channel('yedekleme')->warning('Bakım modu bu işlemin değil; kaldırılmadı', ['islem' => $kayit->kimlik]);
            }
        } catch (Throwable $hata) {
            $bilgi = $this->hataBilgisi($hata, GeriYuklemeHatasi::BAKIM_MODU);
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Onaylaniyor, true, $bilgi);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["Onay tamamlanamadı: {$bilgi['kod']}: {$bilgi['mesaj']}"]);

            return $kayit;
        }

        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Onaylandi, false);
        $this->denetle('geri_yukle_ustune_onay', IslemSonucu::Basarili, $kayit, ['ayar_secimleri' => $kayit->ayarSecimleri]);
        $this->bildir('geri_yukleme_bitti', $kayit, ["{$this->canli()} geri yüklendi ve onaylandı; uygulama açıldı. Eski veritabanı {$kayit->oncekiVeritabani} adıyla (bağlantıya kapalı) bekliyor."]);

        return $kayit;
    }

    private function geriDonusuTamamla(YedekKilidi $kilit, IslemKaydi $kayit): IslemKaydi
    {
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriDonuluyor, true);
        $canli = $this->canli();
        $hedef = (string) $kayit->hedefVeritabani;
        $onceki = (string) $kayit->oncekiVeritabani;

        try {
            $durum = $this->adlar($kayit);
            if ($durum === 'tutarsiz') {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ADLAR_TUTARSIZ, 'Veritabanı adları işlem durumuyla tutmuyor; geri dönülmedi.');
            }
            if ($durum === 'okunamadi') {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ADLAR_OKUNAMADI, 'Veritabanı adları okunamadı (PostgreSQL erişilemiyor); geri dönüş çalıştırıcıda yeniden denenecek.');
            }

            if ($durum === 'yer_degisti') {
                $this->canliBaglantilariniBirak();
                $this->pgIle(function () use ($canli, $hedef, $onceki): void {
                    $this->vt->baglantilariKapat($canli);
                    $this->vt->yerDegistir([$canli, $hedef], [$onceki, $canli]);
                });
            }

            // Eski veritabanı `erp_onceki_*` iken bağlantıya kapalıydı; özgün yetkileri geri
            $this->pgIle(function () use ($kayit, $canli): void {
                $this->ozgunYetkileriGeriKoy($kayit, $canli);
                $this->vt->baglantiyaAc($canli);
            });
        } catch (Throwable $hata) {
            $this->canliBaglantilariniBirak();
            $bilgi = $this->hataBilgisi($hata, GeriYuklemeHatasi::YER_DEGISTIRILEMEDI);
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriDonuluyor, true, $bilgi);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["Geri dönüş tamamlanamadı: {$bilgi['kod']}: {$bilgi['mesaj']}"]);

            return $kayit;
        }

        $this->canliBaglantilariniBirak();
        $this->sessiz(fn () => $this->dizin->tara($kilit));
        $this->sessiz(fn () => $this->gecmisiYenidenYaz($kayit, GeriYuklemeDurumu::GeriAlindi));

        try {
            $this->kuyruguBaslat($kayit);
            $this->bakim->cik($kayit->kimlik);
        } catch (Throwable $hata) {
            $bilgi = $this->hataBilgisi($hata, GeriYuklemeHatasi::BAKIM_MODU);
            $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriDonuluyor, true, $bilgi);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["Geri dönüş tamamlanamadı: {$bilgi['kod']}: {$bilgi['mesaj']}"]);

            return $kayit;
        }

        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::GeriDonuldu, false);
        $this->denetle('geri_yukle_ustune_geri_don', IslemSonucu::Basarili, $kayit);
        $this->bildir('geri_yukleme_bitti', $kayit, ["Geri dönüldü: {$canli} yeniden eski veritabanı; geri yüklenen kopya {$hedef} adıyla (bağlantıya kapalı) bekliyor."]);

        return $kayit;
    }

    // ---------------------------------------------------------- yardımcılar

    /**
     * @return array{0: YedekManifesti, 1: string, 2: string|null} [manifest, dosya, silinecek geçici klasör]
     *
     * @throws GeriYuklemeHatasi
     */
    private function onDenetim(Yedek $yedek, string $onayMetni, IslemKaydi $kayit): array
    {
        if (! $this->yapilandirma->servisCalistiricisi()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::SERVIS_GEREKLI, 'Üstüne geri yükleme servis hesabının çalıştırıcısı olmadan yapılmaz (README §5.2).');
        }

        // Yazıcı bariyeri CONNECT'i servis rolüne bırakır: aynı rol olursa bariyer olmaz (BY-028-R1)
        $rol = $this->yapilandirma->rol();
        if ($rol === null || $rol === $this->pg->baglanti()->uygulamaRolu || $this->yapilandirma->parolaDosyasi() === null) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::SERVIS_ROLU_GEREKLI, 'Üstüne geri yükleme, uygulama rolünden ayrı bir servis rolü (YEDEKLEME_ROL) ve onun parola dosyası (YEDEKLEME_PAROLA_DOSYASI) olmadan yapılmaz: yazıcı bariyeri canlıya bağlanmayı yalnız servis rolüne bırakır.');
        }

        if (! hash_equals($this->canli(), $onayMetni)) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ONAY_METNI_TUTMUYOR, 'Onay metni canlı veritabanının adıyla aynı değil.');
        }

        if (! $yedek->geriYuklenebilirMi()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::YEDEK_GERI_YUKLENEMEZ, 'Yalnız bu sistemin imzaladığı, tamamlanmış yedek geri yüklenir.');
        }

        if ($yedek->ice_alindi) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::SEMA_BILINMIYOR, 'İçe alınmış eski yedeğin migration listesi yok; şema uyumu denetlenemediği için üstüne geri yüklenmez (farklı adla yüklenebilir).');
        }

        if ($this->bakim->aktifMi()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Uygulama zaten bakım modunda; geri yükleme başlamadı.');
        }

        if (config('app.maintenance.driver', 'file') !== 'file') {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BAKIM_MODU, 'Bakım modu sürücüsü dosya değil; üstüne geri yükleme dosya sürücüsünü ister.');
        }

        try {
            $this->yapilandirma->kuyrukServisi();
        } catch (YapilandirmaHatasi $hata) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::YAPILANDIRMA, $hata->getMessage());
        }

        // İmza veritabanındaki bayraktan değil manifest dosyasından yeniden doğrulanır
        [$manifest, $dosya, $gecici] = $this->geriYukleyici->dosyaHazirla($yedek);

        try {
            if ($manifest->iceAlindi || ! $manifest->semasiBiliniyor()) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::SEMA_BILINMIYOR, 'Yedeğin manifestinde migration listesi yok; şema uyumu denetlenemez.');
            }

            // Yedeğin TAM listesi (README §4.5-B): yedekte olup kodda olmayan varsa başlamaz
            $eksik = array_values(array_diff((array) $manifest->migrationlar, $this->koddakiMigrationlar()));
            if ($eksik !== []) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::SEMA_UYUMSUZ, 'Yedekte olup kodda bulunmayan migration var (kod geri alınmış ya da yedek başka bir daldan): '.implode(', ', array_slice($eksik, 0, 5)).(count($eksik) > 5 ? ' …' : ''));
            }

            // Şifreli alanlar (ERP bağlantı parolaları, mail, entegratör) bugünkü
            // anahtarlarla çözülemezse uygulama açılır ama kimse giremez (YF-13)
            if (! AppKeyParmakIzi::bilinenMi($manifest->appKeyParmakIzi)) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::APP_KEY_UYUMSUZ, 'Yedek bugünkü APP_KEY ve APP_PREVIOUS_KEYS dışındaki bir anahtarla alınmış; şifreli alanlar çözülemez. Yedeği alan anahtarı APP_PREVIOUS_KEYS\'e ekleyin (README §7.5).');
            }

            // İmzalı manifestten bilinen retler kesintiden (bakım modu, bariyer, öncesi yedek) önce (YF-14)
            if ($manifest->veritabaniOzellikleri !== null) {
                GeriYuklemeVeritabani::ozellikleriDenetle($manifest->veritabaniOzellikleri);
            }

            $this->pgIle(function () use ($kayit, $manifest): void {
                $mevcut = $this->vt->mevcutlar([$this->canli(), (string) $kayit->hedefVeritabani, (string) $kayit->oncekiVeritabani]);
                if (! in_array($this->canli(), $mevcut, true)) {
                    throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ADLAR_TUTARSIZ, 'Canlı veritabanı bulunamadı.');
                }
                if (count($mevcut) > 1) {
                    throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_VAR, 'Hedef ya da önceki veritabanı adı zaten var; geri yükleme başlamadı.');
                }

                $this->vt->surumDenetle($manifest->pgDumpSurumu);
                // Manifest yetkisindeki rol artık yoksa 4. adımda düşülürdü: yalnız çözümlenir
                $acl = $manifest->veritabaniOzellikleri['baglanma_yetkileri'] ?? null;
                if ($acl !== null) {
                    $this->vt->yetkileriCoz($acl);
                }
                // Canlının kimliğine bağlı özellikleri ad değiştirmeyle kaybolurdu (YF-45)
                $this->vt->kimligeBagliOzellikleriDenetle($this->canli());
                // Yeni veritabanı (≈ canlı) + geri yükleme öncesi yedek (≈ yedek dosyası) + pay
                $this->vt->diskDenetle($manifest->boyut);
            });
        } catch (Throwable $hata) {
            if ($gecici !== null) {
                $this->geriYukleyici->geciciSil($gecici);
            }

            throw $hata;
        }

        return [$manifest, $dosya, $gecici];
    }

    /**
     * Migration sonrası sağlık: kodun bütün migration'ları uygulanmış, bütün
     * tabloların sahibi uygulama rolü (okur, yazar, tablo açar — kesif.md §4).
     *
     * @throws GeriYuklemeHatasi
     */
    private function saglikDenetle(string $hedef): void
    {
        $icerik = $this->vt->icerik($hedef);
        $eksik = array_diff($this->koddakiMigrationlar(), $icerik['migrationlar']);

        if ($eksik !== []) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::MIGRATION, 'Migration sonrası yeni veritabanında uygulanmamış migration kaldı: '.implode(', ', array_slice(array_values($eksik), 0, 5)));
        }

        if ($icerik['sahipler'] === [] || array_diff($icerik['sahipler'], [$this->pg->baglanti()->uygulamaRolu]) !== []) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, 'Yeni veritabanındaki tabloların sahibi uygulama rolü değil.');
        }
    }

    /**
     * Kodun migration'ları (artisan migrate'in baktığı yollar).
     *
     * @return list<string>
     */
    private function koddakiMigrationlar(): array
    {
        $adlar = [];
        foreach ([...app('migrator')->paths(), database_path('migrations')] as $yol) {
            foreach (glob(rtrim((string) $yol, '/').'/*.php') ?: [] as $dosya) {
                $adlar[] = basename($dosya, '.php');
            }
        }

        return array_values(array_unique($adlar));
    }

    /**
     * Adlar hangi tarafı gösteriyor: `yer_degisti` (önceki ad var, hedef yok),
     * `yerinde` (hedef var, önceki yok), `tutarsiz` (diğer her durum),
     * `okunamadi` (sorgu başarısız: PostgreSQL o an erişilemiyor — "yok"
     * değil "bilinmiyor"; YF-41, D6).
     *
     * @return 'yer_degisti'|'yerinde'|'tutarsiz'|'okunamadi'
     */
    private function adlar(IslemKaydi $kayit): string
    {
        try {
            $mevcut = $this->pgIle(fn () => $this->vt->mevcutlar([$this->canli(), (string) $kayit->hedefVeritabani, (string) $kayit->oncekiVeritabani]));
        } catch (Throwable $hata) {
            report($hata);

            return 'okunamadi';
        }

        $var = fn (?string $ad): bool => in_array($ad, $mevcut, true);

        return match (true) {
            $var($this->canli()) && $var($kayit->oncekiVeritabani) && ! $var($kayit->hedefVeritabani) => 'yer_degisti',
            $var($this->canli()) && $var($kayit->hedefVeritabani) && ! $var($kayit->oncekiVeritabani) => 'yerinde',
            default => 'tutarsiz',
        };
    }

    /**
     * Adlar okunamadı: adım değişmez, kod yeniden denenebilirdir
     * (surdurulecekMi) — çalıştırıcı sonraki turda adlara yeniden bakar.
     *
     * @param  array{kod: string, mesaj: string}  $hata  Adlara bakmayı gerektiren ilk hata
     */
    private function adlarOkunamadi(YedekKilidi $kilit, IslemKaydi $kayit, array $hata): IslemKaydi
    {
        // Yeniden denemede ilk hatanın izi korunur
        $mesaj = $hata['kod'] === GeriYuklemeHatasi::ADLAR_OKUNAMADI
            ? $hata['mesaj']
            : "Veritabanı adları okunamadı (ilk hata: {$hata['kod']}); çalıştırıcı yeniden deneyecek.";
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::from($kayit->adim), true, ['kod' => GeriYuklemeHatasi::ADLAR_OKUNAMADI, 'mesaj' => Str::limit($mesaj, 500)]);
        $this->bildir('geri_yukleme_basarisiz', $kayit, [GeriYuklemeHatasi::ADLAR_OKUNAMADI.': PostgreSQL erişilemiyor; uygulama bakım modunda, çalıştırıcı yeniden deneyecek.']);

        return $kayit;
    }

    private function adlarTutarsiz(YedekKilidi $kilit, IslemKaydi $kayit): IslemKaydi
    {
        $kayit = $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::from($kayit->adim), true, [
            'kod' => GeriYuklemeHatasi::ADLAR_TUTARSIZ,
            'mesaj' => 'Veritabanı adları işlem durumuyla tutmuyor; otomatik karar verilmedi. Adları elle inceleyin.',
        ]);
        $this->bildir('geri_yukleme_basarisiz', $kayit, [GeriYuklemeHatasi::ADLAR_TUTARSIZ.': veritabanı adları elle incelenmeli; uygulama bakım modunda.']);

        return $kayit;
    }

    /**
     * @param  array{kod: string, mesaj: string}  $hata
     */
    private function onDenetimdeKalaniKapat(YedekKilidi $kilit, IslemKaydi $kayit, array $hata): IslemKaydi
    {
        $this->sessiz(fn () => GeriYukleme::query()->where('kimlik', $kayit->kimlik)->update([
            'durum' => GeriYuklemeDurumu::Basarisiz->value, 'hata_kodu' => $hata['kod'], 'hata_mesaji' => $hata['mesaj'], 'bitti' => CarbonImmutable::now(),
        ]));

        return $this->yaz($kilit, $kayit, UstuneGeriYuklemeAdimi::Basarisiz, false, $hata);
    }

    /**
     * Otomatik sürdürülecek mi: açık bir üstüne geri yükleme, bilinen bir
     * adımda, onay beklemiyor ve kararı yöneticiye bırakılmamış (yer
     * değiştirmeden sonraki hata, tutarsız adlar). Adların OKUNAMAMASI geçici
     * bir hatadır: hangi adımda olursa olsun yeniden denenir (YF-41) — karar
     * yine adlara bakılarak verilir, uygulama yalnız geri sarmada açılır.
     */
    private function surdurulecekMi(?IslemKaydi $kayit): bool
    {
        if ($kayit === null || $kayit->tur !== IslemTuru::GeriYuklemeUstune) {
            return false;
        }

        $adim = UstuneGeriYuklemeAdimi::tryFrom($kayit->adim);
        if ($adim === null || $adim->bittiMi() || $adim === UstuneGeriYuklemeAdimi::OnayBekliyor) {
            return false;
        }

        if ($kayit->hata === null || $kayit->hata['kod'] === GeriYuklemeHatasi::ADLAR_OKUNAMADI) {
            return true;
        }

        return $adim->yerDegistirmedenOnceMi() && $kayit->hata['kod'] !== GeriYuklemeHatasi::ADLAR_TUTARSIZ;
    }

    /**
     * @return array{0: IslemKaydi, 1: UstuneGeriYuklemeAdimi}
     *
     * @throws GeriYuklemeHatasi
     */
    private function kararVerilecekIslem(string $islemKimligi): array
    {
        try {
            $kayit = $this->yapilandirma->islemDurumu()->oku();
        } catch (IslemDurumuBozuk) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_OKUNAMADI, 'İşlem durumu okunamadı.');
        }

        if ($kayit === null || $kayit->tur !== IslemTuru::GeriYuklemeUstune || $kayit->kimlik !== $islemKimligi) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, 'Bu kimlikle süren bir üstüne geri yükleme yok.');
        }

        $adim = UstuneGeriYuklemeAdimi::tryFrom($kayit->adim)
            ?? throw new GeriYuklemeHatasi(GeriYuklemeHatasi::BILINMEYEN_ADIM, 'İşlem durumundaki adım tanınmıyor; elle incelenmeli.');

        if ($adim->bittiMi()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, 'İşlem kapanmış ('.$adim->value.').');
        }

        return [$kayit, $adim];
    }

    /**
     * `geri_yuklemeler` satırını işlem durumu dosyasından yazar (README §7.3):
     * yer değiştirmeden sonra eski veritabanının satırı gitmiştir. Geri
     * dönüşte canlı yine eski veritabanıdır, satır yerinde güncellenir.
     *
     * @throws GeriYuklemeHatasi Yedeğin dizin kaydı yok
     */
    private function gecmisiYenidenYaz(IslemKaydi $kayit, GeriYuklemeDurumu $durum): GeriYukleme
    {
        $yedekId = Yedek::query()->where('kimlik', (string) $kayit->yedekKimligi)->value('id')
            ?? throw new GeriYuklemeHatasi(GeriYuklemeHatasi::AYAR_UZLASTIRILAMADI, 'Geri yüklenen yedeğin dizin kaydı bulunamadı (dizin kurulamamış olabilir).');

        $geri = GeriYukleme::query()->firstOrNew(['kimlik' => $kayit->kimlik]);
        $simdi = CarbonImmutable::now('UTC');
        $adimlar = $geri->adimlar ?? [];
        if (! $geri->exists) {
            foreach (UstuneGeriYuklemeAdimi::ILERI_AKIS as $a) {
                if ($a === UstuneGeriYuklemeAdimi::OnayBekliyor) {
                    break;
                }
                $adimlar[] = ['adim' => $a->value, 'durum' => 'tamam', 'zaman' => $kayit->sonGuncelleme->toIso8601String()];
            }
        }
        $adimlar[] = ['adim' => $kayit->adim, 'durum' => $durum->value, 'zaman' => $simdi->toIso8601String()];

        $isteyen = $kayit->isteyenKullaniciId;
        $geri->fill([
            'yedek_id' => $yedekId,
            'oncesi_yedek_id' => $this->oncesiYedekId($kayit->kimlik) ?? $geri->oncesi_yedek_id,
            'tur' => GeriYuklemeTuru::Ustune,
            'hedef_veritabani' => (string) $kayit->hedefVeritabani,
            'onceki_veritabani' => $kayit->oncekiVeritabani,
            // Kullanıcı yedekten sonra açıldıysa geri yüklenen veritabanında yoktur
            'isteyen_kullanici_id' => $isteyen !== null && User::query()->whereKey($isteyen)->exists() ? $isteyen : null,
            'durum' => $durum,
            'hata_kodu' => $kayit->hata['kod'] ?? null,
            'hata_mesaji' => $kayit->hata['mesaj'] ?? null,
            'basladi' => $kayit->baslangic,
            'bitti' => in_array($durum, [GeriYuklemeDurumu::Tamam, GeriYuklemeDurumu::GeriAlindi], true) ? $simdi : null,
        ]);
        $geri->adimlar = $adimlar;
        $geri->save();

        return $geri;
    }

    /** Bu işlemin aldığı geri yükleme öncesi yedek (manifestindeki işlem kimliğiyle) */
    private function oncesiYedekId(string $islemKimligi): ?int
    {
        $klasor = $this->yapilandirma->klasor();
        $adaylar = Yedek::query()->where('kaynak', YedekKaynagi::GeriYuklemeOncesi->value)->orderByDesc('id')->limit(20)->get(['id', 'dosya_adi']);

        foreach ($adaylar as $yedek) {
            if (basename($yedek->dosya_adi) !== $yedek->dosya_adi) {
                continue;
            }

            if ($this->manifestler->oku(ManifestDeposu::jsonYolu($klasor.'/'.$yedek->dosya_adi))->manifest?->geriYuklemeKimligi === $islemKimligi) {
                return $yedek->id;
            }
        }

        return null;
    }

    /**
     * Donmuş canlıdaki bekleyen farklı ad kopyalarının kayıtları (5. adım).
     * Yedek kimliği (manifest UUID'si) taşınır: dizin klasörden yeniden
     * kurulunca satır kimliği değişse de aynı yedeği gösterir (G21).
     *
     * @return list<array{kimlik: string, yedek_kimligi: string, hedef_veritabani: string, isteyen_kullanici_id: int|null, basladi: string|null, bitti: string|null}>
     */
    private function bekleyenKopyaKayitlari(): array
    {
        return GeriYukleme::query()->with('yedek:id,kimlik')
            ->where('tur', GeriYuklemeTuru::Kopya->value)->where('durum', GeriYuklemeDurumu::Tamam->value)->whereNull('veritabani_silinme_zamani')
            ->orderBy('id')->get()
            ->filter(fn (GeriYukleme $g): bool => $g->yedek !== null)
            ->map(fn (GeriYukleme $g): array => [
                'kimlik' => $g->kimlik,
                'yedek_kimligi' => (string) $g->yedek?->kimlik,
                'hedef_veritabani' => $g->hedef_veritabani,
                'isteyen_kullanici_id' => $g->isteyen_kullanici_id,
                'basladi' => $g->basladi?->utc()->toIso8601String(),
                'bitti' => $g->bitti?->utc()->toIso8601String(),
            ])->values()->all();
    }

    /**
     * `geri_yuklemeler`'i geri yüklenen veritabanında uzlaştırır (YF-42; README
     * §7.3). Dizin kurulduktan sonra çağrılır; yeniden çalıştırmak aynı sonucu verir.
     *
     * - Sunucuda duran ama kaydı olmayan `erp_geri_*` kopyası (yedekten sonra
     *   açılmış): kaydı donmuş canlının özetinden aynı kimlik, yedek ve
     *   zamanlarla geri kurulur — ekranda görünür, bekleme süresi dolunca
     *   silinir, elle silinebilir. Özette yoksa ya da yedeği dizinde yoksa
     *   kaynağı bilinmez: uydurma kayıt açılmaz (yedek_id zorunlu), yöneticiye bildirilir.
     * - Bu işlemden başka, kapanmamış üstüne geri yükleme satırı (öncesi
     *   yedeğin içinde kalmış eski işlem) `basarisiz` kapatılır.
     *
     * @return array{kurulan_kopya_kayitlari?: list<string>, kaydi_olmayan_kopyalar?: list<string>, kapatilan_islemler?: list<string>}
     */
    private function kayitlariUzlastir(IslemKaydi $kayit): array
    {
        $sonuc = [];
        $kapatilan = GeriYukleme::query()->where('tur', GeriYuklemeTuru::Ustune->value)
            ->whereIn('durum', [GeriYuklemeDurumu::Calisiyor->value, GeriYuklemeDurumu::OnayBekliyor->value])
            ->where('kimlik', '!=', $kayit->kimlik)->pluck('kimlik')->all();
        if ($kapatilan !== []) {
            GeriYukleme::query()->whereIn('kimlik', $kapatilan)->update([
                'durum' => GeriYuklemeDurumu::Basarisiz->value,
                'hata_kodu' => GeriYuklemeHatasi::YARIDA_KALDI,
                'hata_mesaji' => 'Kayıt geri yüklenen veritabanından geldi; işlem o anda sürüyordu ve orada kapanmamıştı.',
                'bitti' => CarbonImmutable::now(),
            ]);
            $sonuc['kapatilan_islemler'] = array_values($kapatilan);
        }

        $adlar = $this->pgIle(fn (): array => $this->vt->kopyaAdlari());
        $kayitli = GeriYukleme::query()->whereIn('hedef_veritabani', $adlar)->whereNull('veritabani_silinme_zamani')->pluck('hedef_veritabani')->all();
        $eksik = array_values(array_diff($adlar, $kayitli));
        if ($eksik === []) {
            return $sonuc;
        }

        $ozet = $this->onayBilgisi->oncekiOku($kayit->kimlik)[self::KOPYA_KAYITLARI] ?? [];
        $bilinen = [];
        foreach (is_array($ozet) ? $ozet : [] as $k) {
            // Dosyadan okunan değer veritabanına yazılmadan önce biçimce doğrulanır (G15)
            if (is_array($k) && is_string($k['hedef_veritabani'] ?? null) && VeritabaniAdi::kopyaMi($k['hedef_veritabani'])
                && is_string($k['kimlik'] ?? null) && Str::isUuid($k['kimlik']) && is_string($k['yedek_kimligi'] ?? null)) {
                $bilinen[$k['hedef_veritabani']] = $k;
            }
        }

        $zaman = fn (mixed $deger): ?CarbonImmutable => is_string($deger) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $deger) === 1 ? CarbonImmutable::parse($deger)->utc() : null;
        foreach ($eksik as $ad) {
            $k = $bilinen[$ad] ?? null;
            $yedekId = $k === null ? null : Yedek::query()->where('kimlik', $k['yedek_kimligi'])->value('id');
            if ($k === null || $yedekId === null || GeriYukleme::query()->where('kimlik', $k['kimlik'])->exists()) {
                $sonuc['kaydi_olmayan_kopyalar'][] = $ad;

                continue;
            }

            $isteyen = is_int($k['isteyen_kullanici_id'] ?? null) && User::query()->whereKey($k['isteyen_kullanici_id'])->exists() ? $k['isteyen_kullanici_id'] : null;
            GeriYukleme::query()->create([
                'kimlik' => $k['kimlik'],
                'yedek_id' => $yedekId,
                'tur' => GeriYuklemeTuru::Kopya,
                'hedef_veritabani' => $ad,
                'isteyen_kullanici_id' => $isteyen,
                'durum' => GeriYuklemeDurumu::Tamam,
                'adimlar' => [],
                'basladi' => $zaman($k['basladi'] ?? null),
                // Bekleme süresi kopyanın açıldığı andan sayılır; bilinmiyorsa şimdiden
                'bitti' => $zaman($k['bitti'] ?? null) ?? CarbonImmutable::now(),
            ]);
            $sonuc['kurulan_kopya_kayitlari'][] = $ad;
        }

        return $sonuc;
    }

    /**
     * Onayda seçim zorunlu gruplar: 8. adımda hesaplanıp yöneticiye gösterilen
     * farklar (onay bilgisi dosyası). O yoksa şimdi hesaplanır; o da
     * yapılamazsa (veritabanı erişilemez) her grup seçim ister.
     *
     * @return list<string>
     */
    private function secimGerekenGruplar(string $islemKimligi): array
    {
        $farkli = fn (array $gruplar): array => array_values(array_map(
            fn (array $g): string => (string) $g['grup'],
            array_filter($gruplar, fn (mixed $g): bool => is_array($g) && in_array($g['grup'] ?? null, AyarKopyasi::GRUPLAR, true) && ($g['farkli'] ?? true) !== false),
        ));

        $bilgi = $this->onayBilgisi->bilgiOku($islemKimligi);
        if (is_array($bilgi['ayar_gruplari'] ?? null)) {
            return $farkli($bilgi['ayar_gruplari']);
        }

        try {
            return $farkli($this->ayarSecimi->karsilastir()['gruplar']);
        } catch (Throwable $hata) {
            report($hata);

            return AyarKopyasi::GRUPLAR;
        }
    }

    /**
     * Yazıcı bariyerinden önceki yetkiler (durum dosyasında). Bariyer hiç
     * kurulmadıysa (kaydı yok) dokunulmaz. pgIle() içinde çağrılır.
     */
    private function ozgunYetkileriGeriKoy(IslemKaydi $kayit, string $ad): void
    {
        if ($kayit->bariyer !== null) {
            $this->vt->yetkileriUygula($ad, $kayit->bariyer['baglanma_yetkileri']);
        }
    }

    /**
     * Bu sürecin canlıdaki açık oturumları: bariyer onları sonlandırmaz
     * (ör. geçmiş kaydını yazan varsayılan bağlantı).
     *
     * @return list<int>
     */
    private function kendiCanliOturumlari(): array
    {
        $pidler = [];
        foreach (DB::getConnections() as $baglanti) {
            if ($baglanti->getDriverName() === 'pgsql' && $baglanti->getDatabaseName() === $this->canli()) {
                $pidler[] = (int) $baglanti->selectOne('SELECT pg_backend_pid() AS pid')->pid;
            }
        }

        return $pidler;
    }

    private function servisRolu(): string
    {
        return (string) $this->yapilandirma->rol();
    }

    /**
     * @param  array{kod: string, mesaj: string}|null  $hata
     * @param  array{baglanma_yetkileri: string|null}|null  $bariyer  Verilmezse önceki kayıttaki
     * @param  array<string, string>|null  $ayarSecimleri  Verilmezse önceki kayıttaki
     * @param  array<string, int>|null  $kullanilanKanitlar  Verilmezse önceki kayıttaki
     */
    private function yaz(YedekKilidi $kilit, IslemKaydi $kayit, UstuneGeriYuklemeAdimi $adim, bool $acik, ?array $hata = null, ?array $bariyer = null, ?array $ayarSecimleri = null, ?array $kullanilanKanitlar = null): IslemKaydi
    {
        $yeni = new IslemKaydi(
            kimlik: $kayit->kimlik,
            tur: $kayit->tur,
            adim: $adim->value,
            acik: $acik,
            baslangic: $kayit->baslangic,
            sonGuncelleme: CarbonImmutable::now('UTC'),
            hata: $hata,
            erisimAnahtariOzeti: $kayit->erisimAnahtariOzeti,
            erisimAnahtariBitis: $kayit->erisimAnahtariBitis,
            yedekKimligi: $kayit->yedekKimligi,
            hedefVeritabani: $kayit->hedefVeritabani,
            oncekiVeritabani: $kayit->oncekiVeritabani,
            isteyenKullaniciId: $kayit->isteyenKullaniciId,
            bariyer: $bariyer ?? $kayit->bariyer,
            ayarSecimleri: $ayarSecimleri ?? $kayit->ayarSecimleri,
            kullanilanKanitlar: $kullanilanKanitlar ?? $kayit->kullanilanKanitlar,
        );
        $this->yapilandirma->islemDurumu()->yaz($yeni, $kilit);

        return $yeni;
    }

    /**
     * psql / pg_restore çağrıları parola dosyasıyla; iç içe çağrılmaz (her
     * blok kendi parola dosyasını hazırlar ve siler).
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    private function pgIle(Closure $is): mixed
    {
        return $this->vt->parolaylaCalis($is);
    }

    /**
     * Bu sürecin CANLI ADA bağlı PostgreSQL bağlantılarını bırakır: canlı
     * bağlantıya kapatılıp koparılırken kendi bağlantısı ad değiştirmeyi
     * bozmasın; yer değiştirince ölü bağlantı yerine (aynı addaki) yeni canlıya
     * bağlanılsın. Başka veritabanına bağlı bağlantılara dokunulmaz: onlar ad
     * değişikliğinden etkilenmez, bırakılırsa açık işlemleri boşuna kaybolur.
     */
    private function canliBaglantilariniBirak(): void
    {
        $canli = $this->canli();

        foreach (DB::getConnections() as $ad => $baglanti) {
            if ($baglanti->getDriverName() === 'pgsql' && $baglanti->getDatabaseName() === $canli) {
                DB::purge($ad);
            }
        }
    }

    private function canli(): string
    {
        return $this->pg->baglanti()->veritabani;
    }

    /** Adımın hatası tanınmayan istisnaysa kullanılacak kod */
    private function adimHataKodu(IslemKaydi $kayit): string
    {
        return match (UstuneGeriYuklemeAdimi::tryFrom($kayit->adim)) {
            UstuneGeriYuklemeAdimi::BakimModu => GeriYuklemeHatasi::BAKIM_MODU,
            UstuneGeriYuklemeAdimi::YaziciBariyeri => GeriYuklemeHatasi::YAZICI_BARIYERI,
            UstuneGeriYuklemeAdimi::OncesiYedek => GeriYuklemeHatasi::ONCESI_YEDEK,
            UstuneGeriYuklemeAdimi::Veritabani => GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI,
            UstuneGeriYuklemeAdimi::PgRestore => GeriYuklemeHatasi::PG_RESTORE,
            UstuneGeriYuklemeAdimi::Migration => GeriYuklemeHatasi::MIGRATION,
            UstuneGeriYuklemeAdimi::Dogrulama => GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI,
            UstuneGeriYuklemeAdimi::BaglantiKapatma => GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI,
            UstuneGeriYuklemeAdimi::YerDegistirme => GeriYuklemeHatasi::YER_DEGISTIRILEMEDI,
            UstuneGeriYuklemeAdimi::DizinKurma => GeriYuklemeHatasi::DIZIN_KURULAMADI,
            default => GeriYuklemeHatasi::AYAR_UZLASTIRILAMADI,
        };
    }

    /**
     * @return array{kod: string, mesaj: string}
     */
    private function hataBilgisi(Throwable $hata, string $varsayilanKod): array
    {
        return match (true) {
            $hata instanceof GeriYuklemeHatasi => ['kod' => $hata->kod, 'mesaj' => Str::limit($hata->getMessage(), 500)],
            $hata instanceof YapilandirmaHatasi, $hata instanceof ImzaAnahtariYok => ['kod' => GeriYuklemeHatasi::YAPILANDIRMA, 'mesaj' => Str::limit($hata->getMessage(), 500)],
            // Beklenmeyen hatanın iletisi veri taşıyabilir: yalnız sınıf adı (D2)
            default => ['kod' => $varsayilanKod, 'mesaj' => 'Beklenmeyen hata: '.$hata::class],
        };
    }

    /**
     * Denetim kaydı: dosyaya her zaman, veritabanına yazılabiliyorsa (YedekDenetimi).
     *
     * @param  array<string, mixed>  $ayrinti
     */
    private function denetle(string $islem, IslemSonucu $sonuc, IslemKaydi $kayit, array $ayrinti = [], ?string $hata = null): void
    {
        $this->sessiz(fn () => $this->denetim->kaydet($islem, $sonuc, null, $kayit->isteyenKullaniciId, null, [
            'islem' => $kayit->kimlik, 'yedek' => $kayit->yedekKimligi, 'hedef' => $kayit->hedefVeritabani, 'onceki' => $kayit->oncekiVeritabani, ...$ayrinti,
        ], $hata));
    }

    /**
     * Doğrudan mail, ayar dosya kopyasından (README §4.8: geri yüklemeden
     * sonra veritabanındaki ayar eskidir). Yineleme anahtarı işlem + adım
     * (+ ek) başına: aynı işlemin farklı aşamadaki hatası bastırılmaz.
     *
     * @param  list<string>  $satirlar
     */
    private function bildir(string $tur, IslemKaydi $kayit, array $satirlar, string $ek = ''): void
    {
        $this->sessiz(fn () => $this->bildirimci->bildir($tur, [__('yedekleme.mail.'.$tur), ...$satirlar], "{$tur}:{$kayit->kimlik}:{$kayit->adim}{$ek}", ayarDosyasindan: true));
    }

    /**
     * Kuyruk servisini başlatır; başlatılamazsa uygulamanın açılmasını
     * engellemez (veri güvende, işler yalnız bekler): günlüğe yazılır,
     * yöneticiye bildirilir.
     */
    private function kuyruguBaslat(IslemKaydi $kayit): void
    {
        try {
            $this->kuyruk->baslat();
        } catch (Throwable $hata) {
            $bilgi = $this->hataBilgisi($hata, GeriYuklemeHatasi::KUYRUK_SERVISI);
            Log::channel('yedekleme')->error('Kuyruk servisi başlatılamadı', ['islem' => $kayit->kimlik, 'kod' => $bilgi['kod']]);
            $this->bildir('geri_yukleme_basarisiz', $kayit, ["Kuyruk servisi başlatılamadı ({$bilgi['mesaj']}); elle başlatın."], ':kuyruk');
        }
    }

    private function sessiz(Closure $is): void
    {
        try {
            $is();
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->warning('Üstüne geri yükleme: en iyi çabayla yapılan adım başarısız', ['hata' => $hata::class]);
        }
    }

    private function kilitDenetle(YedekKilidi $kilit): void
    {
        if (! $kilit->dosyayiTutuyorMu($this->yapilandirma->durumKlasoru().'/kilit')) {
            throw new LogicException('Üstüne geri yükleme yalnız yedekleme kilidini tutan süreç tarafından yürütülür.');
        }
    }
}
