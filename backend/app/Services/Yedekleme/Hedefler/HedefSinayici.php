<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Closure;
use Throwable;

/**
 * "Bağlantıyı Sına" (README §4.6): yalnız bağlanmayı değil yaz – oku –
 * doğrula – sil döngüsünü sınar. Her adım ayrı raporlanır; bir adım
 * başarısızsa sonrakiler "atlandı" olur (silme yine denenir: hedefte sınama
 * dosyası kalmasın).
 *
 * Sınama web isteğinde çalışır: bütün adımlar TEK bir toplam süre bütçesini
 * paylaşır (D9, BY-016-R2). Her adımdan önce bağlayıcının okuduğu süre
 * sınırları (`sure_siniri.kopya` / `.baglanti`) kalan süreye göre kısaltılır;
 * bütçe biten adım çalışmaz (`SURE_DOLDU`). Adım süresince son zaman etkin
 * bütçedir (SureButcesi::icinde): bağlayıcının içindeki token yenileme, 401
 * sonrası tekrar, yeniden denemeler, beklemeler ve dış süreçler de ona uyar
 * (BY-016-R4). Silme için küçük bir pay ayrılır; önceki bir adım payı da
 * tüketmişse silme yine bağlayıcının kullanabileceği en kısa süreyle
 * (TEMIZLIK_ASGARI) denenir (BY-016-R5).
 */
final class HedefSinayici
{
    /** Toplam bütçe (sn): web sunucusunun istek sınırından kısa */
    public const TOPLAM_SURE = 30;

    /** Sınama dosyasını silmeye ayrılan pay (sn) */
    public const TEMIZLIK_PAYI = 5;

    /**
     * Temizliğin her durumda alacağı en kısa süre (sn). Bağlayıcı çağrıyı
     * ancak kalan süre en az 1 sn iken yapar (SureButcesi::sinirla): tam 1
     * sn'lik alt bütçe, ilk denetime kadar geçen bir anla tükenir ve silme
     * yapılmazdı (BY-016-R5). Olağan akışta temizlik TEMIZLIK_PAYI içinde
     * kalır (toplam bütçe aşılmaz); bu taban yalnız önceki bir adım payını
     * aşmışsa (süre sınırı alamayan çağrı) devreye girer.
     */
    public const TEMIZLIK_ASGARI = 2;

    /** Bütçe bittiği için çalıştırılmayan adımın kodu */
    public const SURE_DOLDU = 'HEDEF_SINAMA_SURESI_DOLDU';

    /**
     * Bir bağlayıcı çağrısı verilen süre sınırını en çok bu kadar kez
     * tüketebilir (3 deneme; dosya taşımayan işlemde bağlantı sınırı × 3):
     * adımın sınırı kalan sürenin bu kadarda biri olur.
     */
    private const BAGLAYICI_CARPANI = 3;

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @return list<array{adim: string, basarili: bool|null, kod: string|null, mesaj: string|null}>
     */
    public function sina(HedefBaglayicisi $baglayici, ?SureButcesi $butce = null): array
    {
        $butce ??= new SureButcesi(self::TOPLAM_SURE);
        $adimlar = [];
        $ekle = function (string $adim, ?bool $basarili, ?Throwable $hata = null) use (&$adimlar): void {
            $adimlar[] = [
                'adim' => $adim,
                'basarili' => $basarili,
                'kod' => $hata instanceof HedefHatasi ? $hata->kod : ($hata === null ? null : HedefHatasi::ERISILEMEDI),
                'mesaj' => $hata instanceof HedefHatasi ? $hata->getMessage() : null,
            ];
        };

        $klasor = sys_get_temp_dir().'/emor-hedef-sinama-'.bin2hex(random_bytes(6));
        mkdir($klasor, 0700);
        $kaynak = $klasor.'/kaynak';
        $geri = $klasor.'/geri';
        $icerik = random_bytes(4096);
        file_put_contents($kaynak, $icerik);
        $ad = 'erp_sinama_'.bin2hex(random_bytes(6)).'.dump';
        $uzak = null;

        try {
            try {
                $this->sinirli($butce, self::TEMIZLIK_PAYI, fn () => $baglayici->baglan());
                $ekle('baglan', true);
            } catch (Throwable $hata) {
                $ekle('baglan', false, $hata);
                foreach (['yaz', 'oku', 'dogrula', 'sil'] as $kalan) {
                    $ekle($kalan, null);
                }

                return $adimlar;
            }

            try {
                $uzak = $this->sinirli($butce, self::TEMIZLIK_PAYI, fn (): string => $baglayici->yaz($kaynak, $ad));
                $ekle('yaz', true);
            } catch (Throwable $hata) {
                $ekle('yaz', false, $hata);
            }

            if ($uzak !== null) {
                try {
                    $this->sinirli($butce, self::TEMIZLIK_PAYI, fn () => $baglayici->oku($uzak, $geri));
                    $ekle('oku', true);
                    $ayni = is_file($geri) && hash_equals(hash('sha256', $icerik), (string) hash_file('sha256', $geri));
                    $ekle('dogrula', $ayni, $ayni ? null : new HedefHatasi(HedefHatasi::OKUNAMADI, 'Hedeften okunan dosya yazılanla aynı değil.'));
                } catch (Throwable $hata) {
                    $ekle('oku', false, $hata);
                    $ekle('dogrula', null);
                }

                try {
                    $this->sinirli($butce, 0, fn () => $baglayici->sil($uzak), herDurumda: true);
                    $ekle('sil', true);
                } catch (Throwable $hata) {
                    $ekle('sil', false, $hata);
                }
            } else {
                foreach (['oku', 'dogrula', 'sil'] as $kalan) {
                    $ekle($kalan, null);
                }
            }

            return $adimlar;
        } finally {
            @unlink($kaynak);
            @unlink($geri);
            @rmdir($klasor);
        }
    }

    /**
     * Ekranın sonucu: adımlar + (başarılıysa) kalan alan + S3'te silinme
     * koruması (nesne kilidi; BY-023-R2). Hepsi aynı bütçeden; bütçe kalmadıysa
     * kalan alan bilinmez, koruma "sorgulanamadı" olur.
     *
     * @return array{basarili: bool, adimlar: list<array{adim: string, basarili: bool|null, kod: string|null, mesaj: string|null}>, kalan_alan: int|null, silinme_korumasi: 'etkin'|'devre_disi'|'sorgulanamadi'|null}
     */
    public function sonuc(HedefBaglayicisi $baglayici, ?SureButcesi $butce = null): array
    {
        $butce ??= new SureButcesi(self::TOPLAM_SURE);
        $adimlar = $this->sina($baglayici, $butce);
        $basarili = self::basariliMi($adimlar);

        $kalan = null;
        if ($basarili) {
            try {
                $kalan = $this->sinirli($butce, 0, fn (): ?int => $baglayici->kalanAlan());
            } catch (Throwable) {
                $kalan = null;
            }
        }

        // Koruma bilgisi yalnız S3'te var; bağlanılamayan kovada sorulmaz
        $koruma = null;
        if ($baglayici instanceof S3Baglayicisi && $adimlar[0]['basarili'] === true) {
            try {
                $varMi = $this->sinirli($butce, 0, fn (): ?bool => $baglayici->silinmeKorumasiVarMi());
            } catch (Throwable) {
                $varMi = null;
            }
            $koruma = match ($varMi) {
                true => 'etkin',
                false => 'devre_disi',
                null => 'sorgulanamadi',
            };
        }

        return ['basarili' => $basarili, 'adimlar' => $adimlar, 'kalan_alan' => $kalan, 'silinme_korumasi' => $koruma];
    }

    /**
     * @param  list<array{basarili: bool|null}>  $adimlar
     */
    public static function basariliMi(array $adimlar): bool
    {
        return array_filter($adimlar, fn (array $a): bool => $a['basarili'] !== true) === [];
    }

    /**
     * Çağrıyı kalan süreyle sınırlı çalıştırır: bağlayıcının okuduğu süre
     * sınırları çağrı boyunca kısaltılır, sonra eski değerine döner. Ayrıca
     * adımın son zamanı (kalan − pay) çağrı boyunca etkin bütçedir: bağlayıcının
     * içindeki her istek, yeniden deneme, bekleme ve dış süreç ona uyar
     * (BY-016-R4) — üçte bir kuralı yalnız yeniden denemeye yer bırakır.
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     *
     * @throws HedefHatasi Bütçe bitti (SURE_DOLDU); çağrı yapılmadı
     */
    private function sinirli(SureButcesi $butce, int $pay, Closure $is, bool $herDurumda = false): mixed
    {
        $kullanilabilir = $butce->kalanSaniye() - $pay;
        if ($kullanilabilir < 1 && ! $herDurumda) {
            throw new HedefHatasi(self::SURE_DOLDU, 'Sınamanın süresi doldu; bu adım çalıştırılmadı.');
        }

        // Temizlik, bütçe tükenmiş olsa da bağlayıcının gerçekten kullanabileceği sürede çalışır (BY-016-R5)
        $sure = $herDurumda ? max((float) self::TEMIZLIK_ASGARI, $kullanilabilir) : $kullanilabilir;
        // Süre sınırı en az 1 sn: 0 Symfony Process'te "sınırsız" demektir (G9)
        $sinir = max(1, (int) floor($sure / self::BAGLAYICI_CARPANI));
        $onceki = ['yedekleme.sure_siniri.kopya' => config('yedekleme.sure_siniri.kopya'), 'yedekleme.sure_siniri.baglanti' => config('yedekleme.sure_siniri.baglanti')];
        config([
            'yedekleme.sure_siniri.kopya' => min($this->yapilandirma->sureSiniri('kopya'), $sinir),
            'yedekleme.sure_siniri.baglanti' => min($this->yapilandirma->sureSiniri('baglanti'), $sinir),
        ]);

        try {
            return $butce->alt($sure)->icinde($is);
        } finally {
            config($onceki);
        }
    }
}
