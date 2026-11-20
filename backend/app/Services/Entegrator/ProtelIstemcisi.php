<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Protel (Süper Entegratör) REST istemcisi — Protel'e istek çıkaran TEK yer.
 *
 * ALTIN KURAL (kullanıcı, 2026-11-18): Protel'de hiçbir şey değiştirilmez.
 * Kullanıcıya Protel tarafında yetki kısıtı tanımlanamıyor (2026-11-19
 * cevabı); tek koruma bu sınıftaki iki katmanlı kilittir:
 *
 * 1. Metot izin listesi: yalnız IZINLI_METOTLAR'daki yollar. Gerisi (yazan
 *    metotlar, bilinmeyen ya da ileride eklenecek uçlar) istek gönderilmeden reddedilir.
 * 2. Gövde alanı izin listesi: her metodun gövdesinde yalnız izinli anahtarlar,
 *    sabit alanlarda yalnız izinli değerler. `setErpStatus`,
 *    `setErpRecievedStatus`, `setIsRead` gibi alanlar hiçbir zaman gitmez.
 *
 * Ek önlemler: modül kapalıyken (PROTEL_AKTIF) hiçbir istek gitmez; adresler
 * config'ten gelir ve yalnız izinli alan adlarına çıkar; yönlendirme izlenmez.
 *
 * Doğrulanan davranış (docs/protel/api-notlari.md): token 24 saat (JWT `exp`),
 * `Authorization: Bearer`; hatalı/eksik token 401 gövdesiz; başarılı liste
 * yanıtında `isSuccess=false` — başarı ölçütü HTTP 200. Hata gövdeleri isteğin
 * BAŞLIKLARINI (token dahil) geri yansıtabilir: gövde hiçbir zaman günlüğe,
 * hata mesajına ya da istemciye taşınmaz.
 */
final class ProtelIstemcisi
{
    public const YOL_GIRIS = '/v1/Auth/AccessToken';

    public const YOL_FATURA_LISTESI = '/v1/Document/GetInvoiceList';

    public const YOL_FATURA_PDF = '/v1/Document/GetInvoicePdf';

    /**
     * Katman 1 + 2: izinli yol => gövdede bulunabilecek anahtarlar. Yeni bir
     * metot gerekirse önce durum değiştirmediği ölçülür (PRT-02 yöntemi), sonra eklenir.
     */
    private const IZINLI_METOTLAR = [
        self::YOL_GIRIS => ['clientType', 'eMail', 'password', 'customerIdentity'],
        self::YOL_FATURA_LISTESI => ['startDate', 'endDate', 'filterDateType', 'documentDirection', 'erpRecievedStatus', 'erpStatus'],
        // set* alanları gönderilmezse durum değişmez: Protel'in yazılı teyidi
        // (2026-11-19) + önce/sonra ölçümü (PRT-02)
        self::YOL_FATURA_PDF => ['uuidType', 'uuid', 'documentDirection', 'outputType'],
    ];

    /** Sabit alanların izinli değerleri: giden faturalar ve durum süzgeci kapsam dışı. */
    private const SABIT_DEGERLER = [
        'clientType' => ['WebService'],
        'documentDirection' => ['Incoming'],
        'erpRecievedStatus' => ['All'],
        'erpStatus' => ['All'],
        'filterDateType' => ['DocumentDate', 'CreateDate'],
        'uuidType' => ['DocumentUUID'],
        'outputType' => ['Pdf'],
    ];

    /** Bağlantı hatası, 5xx ve 429'da bekleme süreleri (ms) — yalnız okuma metotları tekrarlanır. */
    private const YENIDEN_DENEME_MS = [250, 1000];

    /** JWT okunamazsa varsayılan token ömrü (sn) — en kötü ihtimalle daha sık giriş yapılır. */
    private const VARSAYILAN_TOKEN_OMRU = 3600;

    /**
     * Önbellek ve kilit OLMADAN token alır. Bağlantı sınaması (kaydedilmemiş
     * form değerleri) bunu kullanır. Giriş yanıtındaki firma VKN'si tanımdakiyle
     * aynı olmalı: başka bir firmanın kutusu okunmasın.
     */
    public function tokenAl(EntegratorBaglanti $tanim): ProtelToken
    {
        $yanit = $this->gonder($tanim, self::YOL_GIRIS, [
            'clientType' => 'WebService',
            'eMail' => $tanim->kullanici_adi,
            'password' => $tanim->sifre,
            'customerIdentity' => $tanim->vkn,
        ]);

        $govde = $yanit->json();
        $sonuc = is_array($govde) && is_string($govde['result'] ?? null) ? $govde['result'] : null;

        if ($yanit->status() === 401 || $sonuc === 'LoginFailed') {
            throw EntegratorHatasi::kimlikHatali($this->guvenliKod($sonuc));
        }

        if (! $yanit->successful()) {
            throw $this->basarisizYanitHatasi($yanit);
        }

        $erisimToken = is_array($govde) ? ($govde['accessToken'] ?? null) : null;

        if (! is_array($govde) || ($govde['isSuccessed'] ?? null) !== true || ! is_string($erisimToken) || $erisimToken === '') {
            throw EntegratorHatasi::saglayiciHatasi($this->guvenliKod($sonuc) ?? 'GIRIS_BASARISIZ', $yanit->status());
        }

        $kullanici = is_array($govde['userInfo'] ?? null) ? $govde['userInfo'] : [];
        $firmaVkn = is_string($kullanici['loggedCustomerIdentity'] ?? null) ? $kullanici['loggedCustomerIdentity'] : null;

        if ($firmaVkn !== $tanim->vkn) {
            throw EntegratorHatasi::saglayiciHatasi('VKN_UYUSMUYOR', $yanit->status());
        }

        return new ProtelToken(
            erisimToken: $erisimToken,
            bitis: $this->bitisZamani($erisimToken),
            firmaUnvani: is_string($kullanici['loggedCustomerTitle'] ?? null) ? $kullanici['loggedCustomerTitle'] : '',
        );
    }

    /**
     * Kayıtlı tanım için geçerli token: önbellekte şifreli; anahtar kimlik
     * sürümünü içerir; eşzamanlı yenileme kilitle tek isteğe indirilir.
     */
    public function token(EntegratorBaglanti $tanim): string
    {
        $tanim->refresh();
        $anahtar = $tanim->tokenOnbellekAnahtari();

        $onbellekte = $this->onbellektenOku($anahtar);
        if ($onbellekte !== null) {
            return $onbellekte;
        }

        try {
            $kilitSuresi = $this->kilitSuresi();

            return Cache::lock($anahtar.':kilit', $kilitSuresi)->block($kilitSuresi, function () use ($anahtar, $tanim): string {
                $onbellekte = $this->onbellektenOku($anahtar);
                if ($onbellekte !== null) {
                    return $onbellekte;
                }

                $token = $this->tokenAl($tanim);
                $sure = $token->bitis->getTimestamp() - CarbonImmutable::now()->getTimestamp() - $this->guvenlikPayi();

                Cache::put($anahtar, Crypt::encryptString($token->erisimToken), max(60, $sure));

                return $token->erisimToken;
            });
        } catch (LockTimeoutException) {
            throw EntegratorHatasi::erisilemedi();
        }
    }

    public function tokenUnut(EntegratorBaglanti $tanim): void
    {
        Cache::forget($tanim->tokenOnbellekAnahtari());
    }

    /**
     * Token'lı istek (fatura sunucusu). 401 gelirse token bir kez yenilenip
     * tekrarlanır; ikinci red kalıcı hata. HTTP 200 dışı her yanıt istisnadır —
     * hiçbir zaman boş başarılı yanıta çevrilmez.
     *
     * @param  array<string, mixed>  $govde
     * @return array<string, mixed>
     */
    public function post(EntegratorBaglanti $tanim, string $yol, array $govde): array
    {
        if ($yol === self::YOL_GIRIS) {
            throw new InvalidArgumentException('Giriş ucu tokenAl() ile çağrılır.');
        }

        // Kilit token almadan ÖNCE: yasak bir çağrı için giriş isteği de gitmesin
        $this->kilitDenetle($yol, $govde);

        $yanit = $this->gonder($tanim, $yol, $govde, $this->token($tanim));

        if ($yanit->status() === 401) {
            $this->tokenUnut($tanim);
            $yanit = $this->gonder($tanim, $yol, $govde, $this->token($tanim));
        }

        if ($yanit->status() === 401) {
            throw EntegratorHatasi::kimlikHatali();
        }

        if (! $yanit->successful()) {
            throw $this->basarisizYanitHatasi($yanit);
        }

        return $this->ondalikKoruyarakCoz($yanit->body());
    }

    /**
     * JSON yanıtını ondalıklı sayıları METİN olarak koruyarak çözer (PRT-R8).
     * `json_decode` ondalıklı sayıyı float'a çevirir: 15 anlamlı basamağı aşan
     * tutar (`1234567890123456.23` → `…56.25`) sessizce değişir ve PHP'nin
     * ayrıştırıcısında ondalığı metin veren bir seçenek yoktur. Bu yüzden ancak
     * olağan çözümden geçen (geçerli) gövdede dize dışındaki ondalıklı / üslü
     * sayı belirteçleri tırnağa alınır; sonuç olağan
     * çözümle karşılaştırılır — yapı ve her değer aynı olmalı, yoksa yanıt
     * geçersiz sayılır (dönüşüm hatası sessiz kalmasın — G3).
     *
     * @return array<string, mixed>
     */
    private function ondalikKoruyarakCoz(string $govde): array
    {
        $olagan = json_decode($govde, true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($olagan)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        $korunan = json_decode($this->ondalikSayilariTirnakla($govde), true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($korunan) || ! $this->ayniDeger($olagan, $korunan)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        /** @var array<string, mixed> $korunan */
        return $korunan;
    }

    /**
     * Geçerli JSON'da dize dışındaki ondalıklı / üslü sayıları tırnağa alır.
     * Düzenli ifade değil doğrusal tarama: kaçışlı uzun dizede (.NET base64
     * PDF'teki `+`'yı unicode kaçışıyla yazar) PCRE geri izleme sınırına takılmaz.
     */
    private function ondalikSayilariTirnakla(string $govde): string
    {
        $sonuc = '';
        $uzunluk = strlen($govde);
        $i = 0;

        while ($i < $uzunluk) {
            $atlanan = strcspn($govde, '"-0123456789', $i);
            $sonuc .= substr($govde, $i, $atlanan);
            $i += $atlanan;

            if ($i >= $uzunluk) {
                break;
            }

            if ($govde[$i] === '"') {
                $bas = $i++;
                while ($i < $uzunluk) {
                    $i += strcspn($govde, '"\\', $i);
                    if ($i < $uzunluk && $govde[$i] === '\\') {
                        $i += 2;

                        continue;
                    }
                    $i++;

                    break;
                }
                $sonuc .= substr($govde, $bas, $i - $bas);

                continue;
            }

            $sayi = substr($govde, $i, strspn($govde, '-+0123456789.eE', $i));
            $sonuc .= strpbrk($sayi, '.eE') === false ? $sayi : '"'.$sayi.'"';
            $i += strlen($sayi);
        }

        return $sonuc;
    }

    /** Olağan çözümdeki her float, korunan çözümde aynı sayıyı veren metin olmalı; gerisi birebir. */
    private function ayniDeger(mixed $olagan, mixed $korunan): bool
    {
        if (is_float($olagan)) {
            return is_string($korunan) && is_numeric($korunan) && (float) $korunan === $olagan;
        }

        if (! is_array($olagan)) {
            return $olagan === $korunan;
        }

        if (! is_array($korunan) || array_keys($olagan) !== array_keys($korunan)) {
            return false;
        }

        foreach ($olagan as $anahtar => $deger) {
            if (! $this->ayniDeger($deger, $korunan[$anahtar])) {
                return false;
            }
        }

        return true;
    }

    /**
     * İstek çıkaran TEK nokta: kilit, modül anahtarı ve adres denetimi
     * istekten ÖNCE yapılır.
     *
     * @param  array<string, mixed>  $govde
     */
    private function gonder(EntegratorBaglanti $tanim, string $yol, array $govde, ?string $token = null): Response
    {
        $this->kilitDenetle($yol, $govde);

        if (! config('entegrator.protel.aktif')) {
            throw EntegratorHatasi::protelKapali();
        }

        if ($tanim->saglayici !== EntegratorBaglanti::SAGLAYICI_PROTEL) {
            throw new InvalidArgumentException('Protel istemcisi yalnız Protel tanımıyla çalışır.');
        }

        $taban = $yol === self::YOL_GIRIS ? $tanim->authUrl() : $tanim->apiUrl();
        $this->adresDenetle($taban);

        $istemci = $this->istemci($taban);
        if ($token !== null) {
            $istemci = $istemci->withToken($token);
        }

        try {
            return $istemci->post($yol, $govde);
        } catch (ConnectionException) {
            throw EntegratorHatasi::erisilemedi();
        }
    }

    /**
     * İki katmanlı kilit (PRT-04). İhlal programlama hatasıdır: istisna
     * fırlatılır, istek gönderilmez.
     *
     * @param  array<string, mixed>  $govde
     */
    private function kilitDenetle(string $yol, array $govde): void
    {
        $izinliAnahtarlar = self::IZINLI_METOTLAR[$yol] ?? null;

        if ($izinliAnahtarlar === null) {
            throw new InvalidArgumentException("Bu Protel ucu bu uygulamada kullanılamaz (yalnız okuma): {$yol}");
        }

        foreach ($govde as $anahtar => $deger) {
            $anahtar = (string) $anahtar;

            if (str_starts_with(strtolower($anahtar), 'set') || ! in_array($anahtar, $izinliAnahtarlar, true)) {
                throw new InvalidArgumentException("Protel isteğinde izinsiz alan: {$anahtar}");
            }

            if (isset(self::SABIT_DEGERLER[$anahtar]) && ! in_array($deger, self::SABIT_DEGERLER[$anahtar], true)) {
                throw new InvalidArgumentException("Protel isteğinde izinsiz değer: {$anahtar}");
            }
        }
    }

    private function adresDenetle(string $taban): void
    {
        $parca = parse_url($taban);
        $alanAdi = is_array($parca) ? strtolower($parca['host'] ?? '') : '';
        $izinli = false;

        if (is_array($parca) && ($parca['scheme'] ?? '') === 'https' && $alanAdi !== '') {
            foreach ((array) config('entegrator.protel.izinli_alan_adlari') as $kok) {
                if (is_string($kok) && ($alanAdi === $kok || str_ends_with($alanAdi, '.'.$kok))) {
                    $izinli = true;
                }
            }
        }

        if (! $izinli) {
            throw new InvalidArgumentException('Protel adresi izinli değil (config/entegrator.php).');
        }
    }

    private function istemci(string $taban): PendingRequest
    {
        return Http::baseUrl($taban)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('entegrator.protel.baglanti_zaman_asimi'))
            ->timeout((int) config('entegrator.protel.istek_zaman_asimi'))
            // Yönlendirme şifreli gövdeyi başka bir adrese taşıyabilir
            ->withoutRedirecting()
            ->retry(
                self::YENIDEN_DENEME_MS,
                0,
                fn (?Throwable $hata): bool => $hata instanceof ConnectionException
                    || ($hata instanceof RequestException
                        && ($hata->response->serverError() || $hata->response->status() === 429)),
                throw: false,
            );
    }

    /** JWT'nin `exp` alanı (imza doğrulanmaz; yalnız önbellek süresi için okunur). */
    private function bitisZamani(string $token): CarbonImmutable
    {
        $parcalar = explode('.', $token);
        $yuk = count($parcalar) === 3
            ? json_decode((string) base64_decode(strtr($parcalar[1], '-_', '+/'), true), true)
            : null;
        $exp = is_array($yuk) && is_int($yuk['exp'] ?? null) ? $yuk['exp'] : null;
        $simdi = CarbonImmutable::now();

        // Bozuk ya da çok kısa ömürlü değer: kısa süreyle önbelleğe al
        if ($exp === null || $exp - $simdi->getTimestamp() < $this->guvenlikPayi() + 60) {
            return $simdi->addSeconds(self::VARSAYILAN_TOKEN_OMRU + $this->guvenlikPayi());
        }

        return CarbonImmutable::createFromTimestampUTC($exp);
    }

    /**
     * Gövde günlüğe/hataya taşınmaz (token yansıtabilir); yalnız HTTP durumu
     * ve gövdedeki kısa durum kodu (`InputValidationFailed` gibi).
     */
    private function basarisizYanitHatasi(Response $yanit): EntegratorHatasi
    {
        if ($yanit->serverError() || $yanit->status() === 429) {
            return EntegratorHatasi::erisilemedi($yanit->status());
        }

        $govde = $yanit->json();
        $kod = is_array($govde) && is_string($govde['status'] ?? null) ? $this->guvenliKod($govde['status']) : null;

        return EntegratorHatasi::saglayiciHatasi($kod ?? 'HTTP_'.$yanit->status(), $yanit->status());
    }

    /** Yalnız kısa harf dizisi kod sayılır; serbest metin (yansıtılan başlık olabilir) alınmaz. */
    private function guvenliKod(?string $deger): ?string
    {
        return $deger !== null && preg_match('/^[A-Za-z]{1,40}$/D', $deger) === 1 ? $deger : null;
    }

    private function onbellektenOku(string $anahtar): ?string
    {
        $sifreli = Cache::get($anahtar);

        if (! is_string($sifreli)) {
            return null;
        }

        try {
            return Crypt::decryptString($sifreli);
        } catch (DecryptException) {
            Cache::forget($anahtar);

            return null;
        }
    }

    private function guvenlikPayi(): int
    {
        return (int) config('entegrator.protel.token_guvenlik_payi_saniye');
    }

    private function kilitSuresi(): int
    {
        $denemeSayisi = count(self::YENIDEN_DENEME_MS) + 1;
        $istekSuresi = (int) config('entegrator.protel.istek_zaman_asimi');

        return max(30, $denemeSayisi * $istekSuresi + (int) ceil(array_sum(self::YENIDEN_DENEME_MS) / 1000) + 10);
    }
}
