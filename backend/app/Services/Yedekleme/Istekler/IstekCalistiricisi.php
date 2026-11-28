<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Models\User;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KalicilikDogrulanamadi;
use App\Services\Yedekleme\KopyaVeritabanlari;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YapilandirmaHatasi;
use App\Services\Yedekleme\YedekAlmaHatasi;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Çalıştırıcının bir turu (`yedek:calistir`; README §5.2, BY-025):
 *
 * 1. Çalıştırıcı kilidi (`<durum klasörü>/calistirici`): aynı anda tek
 *    çalıştırıcı. Alınamazsa tur atlanır. Bu kilit tutulurken `isleniyor/`'da
 *    duran istek, işlerken ölmüş bir sürecindir: `ISTEK_YARIDA_KALDI`.
 * 2. Eski sonuçlar silinir.
 * 3. Bekleyen istekler eskiden yeniye: üstlen (tek adımlık taşıma) → doğrula
 *    (IstekKutusu::oku; isteğe güvenilmez) → işleyici → gönderen (dosyanın
 *    sahibi) ve yetki (veritabanından, şimdi) → yedekleme kilidi (en çok
 *    `kilit_bekleme` sn) → açık üstüne geri yükleme yok → yetki yeniden
 *    (kilit altında) → işle → sonucu yaz. Reddedilen istek `basarisiz` sonuç
 *    (makine okunur kod) alır ve denetim kaydına yazılır.
 * 4. Süresi dolan geri yükleme kopyaları ve yarıda kalmış geri yüklemelerin
 *    artıkları silinir (kilit boşsa; doluysa bir sonraki tur).
 *
 * Yayın sürerken (servis döngüsü yayın kilidini alamadı — G1-8) tur yalnız
 * yayın öncesi yedek isteğini işler: yeni kod diske inip migration çalışırken
 * bellekteki eski kodla iş başlamaz.
 *
 * İsteklerden önce, yarıda kalmış bir üstüne geri yükleme (süreç öldü)
 * sürdürülür ya da geri sarılır (UstuneGeriYukleyici::yaridaKalaniIsle;
 * README §6.4). Kalıcı hatada her 2 saniyede denenmesin diye en çok
 * TEMIZLIK_ARALIGI_SN'de bir (D8); servis başlarken hemen.
 *
 * Yetki eşlemesi her işleyicinin izin()'indedir. Kullanıcısız istek yalnız
 * yayın öncesi yedektir (`deploy.sh`'ın uygulama kullanıcısı yok) ve yalnız
 * yayın kullanıcısının dosyasıysa; kullanıcılı istek o kaynağı kullanamaz ve
 * yalnız web kullanıcısının dosyasıysa işlenir (gondereniDenetle). İstekteki
 * kullanıcı kimliği web'in iddiasıdır: ele geçirilmiş web hesabı başka bir
 * kullanıcı adına istek yazabilir (veritabanına zaten yazabildiği için sınır
 * budur), ama yayın istisnasını taklit edemez (README §5.2). Onay ve geri
 * dönüşün yetkisi erişim anahtarıdır (AnahtarlaYetkilendirilir): kullanıcı
 * tablosuna bakılmaz.
 */
final class IstekCalistiricisi
{
    /** Bir turda en çok bu kadar istek; kalanlar sonraki turda */
    public const TUR_BASINA_EN_COK = 20;

    /** Sonuç dosyaları bu kadar gün saklanır (ekran yoklaması için yeter) */
    public const SONUC_SAKLAMA_GUN = 7;

    /** Açık üstüne geri yükleme varken de işlenen türler: onu bitiren işlemler (BY-029) */
    private const ACIK_GERI_YUKLEMEDE = ['onayla', 'geri_don'];

    /**
     * Kopya temizliği aynı süreçte en çok bu aralıkla (sn) denenir: silme
     * kalıcı bir nedenle (parola, yetki) başarısızsa servis döngüsü her 2
     * saniyede PostgreSQL'e giriş denemesin (D8).
     */
    private const TEMIZLIK_ARALIGI_SN = 600;

    private ?int $sonTemizlik = null;

    private ?int $sonSurdurme = null;

    /** Yarıda kalan isteğin sonucu en son ne zaman yazılamadı (hrtime; YF-38) */
    private ?int $sonYarimHatasi = null;

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly IstekKutusu $kutu,
        private readonly IsleyiciKatalogu $katalog,
        private readonly YedekDenetimi $denetim,
        private readonly KopyaVeritabanlari $kopyalar,
        private readonly UstuneGeriYukleyici $ustune,
    ) {}

    /**
     * @param  bool  $yalnizYayinOncesi  Yayın sürüyor (G1-8): yalnız yayın öncesi yedek isteği işlenir; diğer istekler kuyrukta kalır, yarıda kalan geri yükleme sürdürülmez, kopya temizliği yapılmaz
     * @return int İşlenen istek sayısı; başka çalıştırıcı çalışıyorsa 0
     */
    public function tur(bool $yalnizYayinOncesi = false): int
    {
        $calistiriciKilidi = new YedekKilidi($this->yapilandirma->durumKlasoru().'/calistirici', $this->yapilandirma->kaliciKok());
        if (! $calistiriciKilidi->al()) {
            return 0;
        }

        try {
            $this->yaridaKalanIstekleriSonuclandir();

            if (! $yalnizYayinOncesi) {
                $this->yaridaKalanGeriYuklemeyiSurdur();
            }
            $this->kutu->eskiSonuclariSil(self::SONUC_SAKLAMA_GUN);

            $sayi = 0;
            foreach ($this->kutu->bekleyenler() as $yol) {
                if ($sayi >= self::TUR_BASINA_EN_COK) {
                    break;
                }

                if ($yalnizYayinOncesi && ! $this->yayinOncesiYedekMi($yol)) {
                    continue;
                }

                $ustlenilen = $this->kutu->talepEt($yol);
                if ($ustlenilen !== null) {
                    $this->isle($ustlenilen);
                    $sayi++;
                }
            }

            if (! $yalnizYayinOncesi) {
                $this->kopyalariTemizle();
            }

            return $sayi;
        } finally {
            $calistiriciKilidi->birak();
        }
    }

    /**
     * Bekleyen istek yayın öncesi yedek mi (üstlenmeden, yerinde okunur).
     * Okunamayan / geçersiz istek değil sayılır: yayın bitince normal turda
     * reddedilir. Okuma ile üstlenme arasında dosyayı değiştiren (ele
     * geçirilmiş) web en çok bir isteği yayın sırasında işletebilir; yetki ve
     * gönderen denetimi işlerken yine yapılır (gondereniDenetle).
     */
    private function yayinOncesiYedekMi(string $yol): bool
    {
        try {
            $istek = $this->kutu->oku($yol);
        } catch (Throwable) {
            return false;
        }

        return $istek->tur === 'yedek_al' && $istek->metin('kaynak') === YedekKaynagi::YayinOncesi->value;
    }

    private function isle(string $yol): void
    {
        $kullaniciId = null;
        $baglam = [];
        $sonucBaglami = [];

        try {
            $istek = $this->kutu->oku($yol);
            // Denetim kaydı için: istekte YAZAN kullanıcı (doğrulanmamış olabilir)
            $baglam = ['tur' => $istek->tur, 'istekteki_kullanici' => $istek->kullaniciId, 'gonderen_uid' => $istek->sahipUid];
            $sonucBaglami = $this->sonucBaglami($istek);
            $isleyici = $this->katalog->bul($istek->tur)
                ?? throw new IstekReddi('ISTEK_ISLEYICISI_YOK', 'Bu istek türü henüz çalıştırıcıda yok.');
            // Erken denetim: yetkisiz istek kilit beklemeden reddedilir. Son
            // denetim kilit altında, işleyiciden hemen önce (kilitleIsle)
            $kullaniciId = $this->yetkiDenetle($istek, $isleyici);
            $sonuc = $this->kilitleIsle($istek, $isleyici);
        } catch (IstekReddi $red) {
            $sonuc = IstekSonucu::basarisiz($red->kod, $red->getMessage());
        } catch (Throwable $hata) {
            report($hata);
            // Beklenmeyen hatanın iletisi veri taşıyabilir: yalnız sınıf adı (D2)
            $sonuc = IstekSonucu::basarisiz('ISTEK_BEKLENMEYEN_HATA', $hata::class);
        }

        $this->sonuclandir($yol, $sonuc->baglamla($sonucBaglami), $kullaniciId, $baglam);
    }

    /**
     * Üstüne geri yüklemenin istekleri sonuçta işlemlerini gösterir: bakım
     * modundaki ilerleme ucu, oturum olmadan, reddedilen isteği de erişim
     * anahtarıyla eşleyebilsin (BY-030). Anahtarın kendisi sonuca girmez;
     * özeti 256 bitlik rastgele değerin SHA-256'sıdır.
     *
     * @return array<string, string>
     */
    private function sonucBaglami(Istek $istek): array
    {
        return match ($istek->tur) {
            'ustune_geri_yukle' => ['islem_kimligi' => $istek->kimlik, 'erisim_anahtari_ozeti' => (string) $istek->metin('erisim_anahtari_ozeti')],
            'onayla', 'geri_don' => ['islem_kimligi' => (string) $istek->metin('islem_kimligi')],
            default => [],
        };
    }

    /**
     * Yarıda kalmış üstüne geri yüklemeyi sürdürür / geri sarar. Monoton
     * saatle aralıklı (D1, D8); hatası turu durdurmaz.
     */
    private function yaridaKalanGeriYuklemeyiSurdur(): void
    {
        $simdi = hrtime(true);
        if ($this->sonSurdurme !== null && $simdi - $this->sonSurdurme < self::TEMIZLIK_ARALIGI_SN * 1_000_000_000) {
            return;
        }
        $this->sonSurdurme = $simdi;

        try {
            $this->ustune->yaridaKalaniIsle();
        } catch (Throwable $hata) {
            report($hata);
            Log::channel('yedekleme')->error('Yarıda kalan üstüne geri yükleme sürdürülemedi', ['hata' => $hata::class]);
        }
    }

    /**
     * @return int|null Doğrulanmış kullanıcı (sistem isteğinde null)
     *
     * @throws IstekReddi
     */
    private function yetkiDenetle(Istek $istek, IstekIsleyicisi $isleyici): ?int
    {
        $yayinIstegi = $istek->tur === 'yedek_al' && $istek->metin('kaynak') === YedekKaynagi::YayinOncesi->value;
        $anahtarli = $isleyici instanceof AnahtarlaYetkilendirilir;

        if (! $anahtarli && $istek->kullaniciId === null && ! $yayinIstegi) {
            throw new IstekReddi('ISTEK_KULLANICI_YOK', 'İstekte kullanıcı yok; kullanıcısız istek yalnız yayın öncesi yedek olabilir.');
        }

        if (! $anahtarli && $istek->kullaniciId !== null && $yayinIstegi) {
            throw new IstekReddi('ISTEK_YETKI_YOK', 'Yayın öncesi yedeği yalnız yayın betiği ister.');
        }

        $this->gondereniDenetle($istek, $yayinIstegi);

        // Yetki erişim anahtarıdır; işleyici işlem durumundan doğrular. Veritabanı
        // (kullanıcı tablosu yedeğin anında ya da erişilemez) burada sorulmaz.
        if ($anahtarli || $yayinIstegi) {
            return null;
        }

        $kullanici = User::query()->find($istek->kullaniciId);
        if ($kullanici === null || ! $kullanici->aktif_mi) {
            throw new IstekReddi('ISTEK_YETKI_YOK', 'İsteği yapan kullanıcı yok ya da pasif.');
        }

        $izin = $isleyici->izin();
        $yetkili = $izin === null
            ? $kullanici->sistem_yoneticisi === true
            : in_array($izin->value, $kullanici->izinler(), true);

        if (! $yetkili) {
            throw new IstekReddi('ISTEK_YETKI_YOK', 'Kullanıcının bu işlem için yetkisi yok.');
        }

        return $kullanici->id;
    }

    /**
     * Gönderen, dosyanın çekirdekteki sahibiyle doğrulanır (BY-025-R1): istek
     * içeriği (kullanıcı kimliği, yayın kaynağı) web'in iddiasıdır, sahibi
     * değil. Kullanıcısız yayın öncesi yedek yalnız yayın kullanıcısının,
     * geri kalan her istek yalnız web kullanıcısının dosyasıysa işlenir;
     * tanınmayan sahip reddedilir (denetim kaydına gönderenin uid'siyle).
     *
     * @throws IstekReddi
     */
    private function gondereniDenetle(Istek $istek, bool $yayinIstegi): void
    {
        try {
            $beklenen = $yayinIstegi ? $this->yapilandirma->yayinUid() : $this->yapilandirma->webUid();
        } catch (YapilandirmaHatasi $hata) {
            throw new IstekReddi('YEDEK_YAPILANDIRMA_HATASI', $hata->getMessage());
        }

        if ($beklenen === null || $istek->sahipUid !== $beklenen) {
            throw new IstekReddi('ISTEK_GONDEREN_DOGRULANAMADI', $yayinIstegi
                ? 'Yayın öncesi yedek isteği yayın kullanıcısının dosyası değil; işlenmedi.'
                : 'İstek dosyası web kullanıcısının değil; işlenmedi.');
        }
    }

    /**
     * Yedekleme kilidi altında: açık geri yükleme denetimi ve SON yetki
     * denetimi işleyiciden hemen önce (kilit beklenirken yetkisi alınan
     * kullanıcının işi başlamaz — BY-025-R2).
     *
     * @throws IstekReddi
     */
    private function kilitleIsle(Istek $istek, IstekIsleyicisi $isleyici): IstekSonucu
    {
        $durum = $this->yapilandirma->islemDurumu();
        $kilit = $durum->kilit();

        if (! $kilit->bekleyerekAl((float) $this->yapilandirma->sureSiniri('kilit_bekleme'))) {
            throw new IstekReddi(YedekAlmaHatasi::KILIT_MESGUL, 'Başka bir yedekleme / geri yükleme / yayın işlemi sürüyor; istek işlenmedi.');
        }

        try {
            try {
                $acik = $durum->acikGeriYuklemeVarMi();
            } catch (IslemDurumuBozuk) {
                throw new IstekReddi('YEDEK_ISLEM_DURUMU_OKUNAMADI', 'İşlem durumu okunamadı; istek işlenmedi.');
            }

            if ($acik && ! in_array($istek->tur, self::ACIK_GERI_YUKLEMEDE, true)) {
                throw new IstekReddi(YedekAlmaHatasi::ACIK_GERI_YUKLEME, 'Açık bir üstüne geri yükleme var; istek işlenmedi.');
            }

            $this->yetkiDenetle($istek, $isleyici);

            return $isleyici->isle($istek, $kilit);
        } finally {
            $kilit->birak();
        }
    }

    /**
     * Sonucu yazar, reddi denetim kaydına geçirir, üstlenilen dosyayı siler.
     * Sonuç yazılamazsa dosya `isleniyor/`'da kalır; sonraki tur onu
     * "yarıda kaldı" diye yeniden sonuçlandırmayı dener. Sonuç yerine
     * taşındıysa (yalnız eşitleme doğrulanamadıysa) yazılmış sayılır.
     *
     * @param  int|null  $kullaniciId  Yetkisi doğrulanmış kullanıcı (denetim kaydının sahibi)
     * @param  array{tur?: string, istekteki_kullanici?: int|null, gonderen_uid?: int}  $baglam
     * @param  bool  $denetimSonra  Reddi denetim kaydına yalnız sonuç yazılabildiyse geçir (yarıda kalan isteğin her turda yeniden sonuçlandırılması — YF-38)
     * @return bool Sonuç yazıldı mı
     */
    private function sonuclandir(string $yol, IstekSonucu $sonuc, ?int $kullaniciId, array $baglam, bool $denetimSonra = false): bool
    {
        $kimlik = basename($yol, '.json');
        $denetle = fn () => $this->denetim->kaydet('istek', IslemSonucu::Basarisiz, kullaniciId: $kullaniciId, ayrinti: [
            'istek' => $kimlik, ...$baglam, 'kod' => $sonuc->kod,
        ], hata: $sonuc->mesaj);

        if ($sonuc->durum === IstekSonucu::BASARISIZ && ! $denetimSonra) {
            $denetle();
        }

        try {
            try {
                $this->kutu->sonucYaz($kimlik, $sonuc->durum, $sonuc->kod, $sonuc->mesaj, $sonuc->ek);
            } catch (KalicilikDogrulanamadi) {
                // Sonuç yerinde (okuyana görünür), yalnız klasör eşitlemesi doğrulanamadı.
                // Üstlenilen dosya kalırsa sonraki tur gerçek sonucu "yarıda kaldı" ile
                // ezerdi (G1, BY-014-R2)
                Log::channel('yedekleme')->warning('İstek sonucunun diske eşitlendiği doğrulanamadı', ['istek' => $kimlik]);
            }
            $this->kutu->sil($yol);
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->error('İstek sonucu yazılamadı', ['istek' => $kimlik, 'hata' => $hata::class]);

            return false;
        }

        if ($sonuc->durum === IstekSonucu::BASARISIZ && $denetimSonra) {
            $denetle();
        }

        return true;
    }

    /**
     * Ölen süreçten `isleniyor/`'da kalan istekler "yarıda kaldı" olur. Sonuç
     * yazılamıyorsa (disk dolu, izin) denetim kaydı yazılmaz ve aynı süreçte
     * en çok TEMIZLIK_ARALIGI_SN'de bir yeniden denenir: servis döngüsü her
     * 2 saniyede denetim satırı ve hata günlüğü üretmesin (D8, YF-38).
     */
    private function yaridaKalanIstekleriSonuclandir(): void
    {
        $simdi = hrtime(true);
        if ($this->sonYarimHatasi !== null && $simdi - $this->sonYarimHatasi < self::TEMIZLIK_ARALIGI_SN * 1_000_000_000) {
            return;
        }
        $this->sonYarimHatasi = null;

        foreach ($this->kutu->isleniyorlar() as $yol) {
            $yazildi = $this->sonuclandir($yol, IstekSonucu::basarisiz('ISTEK_YARIDA_KALDI', 'Çalıştırıcı bu isteği işlerken durdu; sonucu bilinmiyor. Durumu listeden denetleyip yeniden isteyin.'), null, [], denetimSonra: true);
            if (! $yazildi) {
                $this->sonYarimHatasi = $simdi;
            }
        }
    }

    private function kopyalariTemizle(): void
    {
        // Monoton saat (D1): sistem saati ayarlansa da aralık kaymaz
        $simdi = hrtime(true);
        if ($this->sonTemizlik !== null && $simdi - $this->sonTemizlik < self::TEMIZLIK_ARALIGI_SN * 1_000_000_000) {
            return;
        }
        $this->sonTemizlik = $simdi;

        try {
            if (! $this->kopyalar->temizlenecekVarMi()) {
                return;
            }

            $durum = $this->yapilandirma->islemDurumu();
            $kilit = $durum->kilit();
            if (! $kilit->al()) {
                return;
            }

            try {
                if (! $durum->acikGeriYuklemeVarMi()) {
                    $this->kopyalar->temizle($kilit);
                }
            } finally {
                $kilit->birak();
            }
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->error('Geri yükleme kopyaları temizlenemedi', ['hata' => $hata::class]);
        }
    }
}
