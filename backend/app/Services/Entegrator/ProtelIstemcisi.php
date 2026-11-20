<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
 * Ek önlemler: modül kapalıyken (PROTEL_AKTIF) hiçbir istek gitmez; adres
 * tanımdan (ekrandan girilir; boşsa config'teki ortam varsayılanı) gelir ve
 * yalnız https + izinli alan adına çıkar; yönlendirme izlenmez. Reddedilen
 * kimlik otomatik işlerde yeniden denenmez (EntegratorTokenOnbellegi).
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

    /**
     * Serbest değerli alanların biçimi (PRT-F5): anahtar izinli diye değer
     * serbest değildir — dizi değer içinde yasak anahtar taşıyabilir. Bütün
     * değerler metindir; buradakiler ayrıca bu biçimde olmalı.
     */
    private const BICIMLER = [
        'uuid' => '/^[0-9a-fA-F-]{36}$/D',
        'startDate' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D',
        'endDate' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D',
    ];

    /**
     * Bağlantı hatası ve 5xx'te bekleme süreleri (ms). Fatura listesi toplu
     * iştir (zamanlayıcı / kuyruk): uzun süre, iki tekrar. Giriş ve PDF
     * kullanıcı isteğinde de çalışır: kısa süre, tek tekrar — Protel yavaşken
     * bir PDF tıklaması web işçisini dakikalarca tutmasın (PRT-F2).
     */
    private const LISTE_YENIDEN_DENEME_MS = [250, 1000];

    private const KISA_YENIDEN_DENEME_MS = [500];

    /** JWT okunamazsa varsayılan token ömrü (sn) — en kötü ihtimalle daha sık giriş yapılır. */
    private const VARSAYILAN_TOKEN_OMRU = 3600;

    public function __construct(
        private readonly EntegratorTokenOnbellegi $onbellek,
    ) {}

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
     * Kayıtlı tanım için geçerli token (EntegratorTokenOnbellegi): önbellekte
     * şifreli, eşzamanlı yenileme tek girişe indirilir, reddedilen kimlik
     * yeniden denenmez.
     */
    public function token(EntegratorBaglanti $tanim): string
    {
        return $this->onbellek->token($tanim, $this->kilitSuresi(), $this->guvenlikPayi(), function () use ($tanim): array {
            $token = $this->tokenAl($tanim);

            return [$token->erisimToken, $token->bitis];
        });
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
        $json = json_decode($this->istek($tanim, $yol, $govde), true);

        if (! is_array($json)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * `post()` gibi; ancak JSON'daki her float yerine yanıttaki metni taşıyan
     * `JsonSayisi` döner (PRT-R8). `json_decode` ondalıklı sayıyı float'a
     * çevirir: 15 anlamlı basamağı aşan tutar (`1234567890123456.23` →
     * `…56.25`) sessizce değişir ve PHP'nin ayrıştırıcısında ondalığı metin
     * veren bir seçenek yoktur. Tam sayı, metin, bayrak ve null olağan
     * çözümdeki türüyle kalır (PRT-R12): yalnız float'ın yerini tutan, metinle
     * karışmayan bir tür eklenir. Para tutarı taşıyan fatura listesi için;
     * PDF gibi tutarsız yanıtta gövde iki kez ayrıştırılmasın (PRT-R14).
     *
     * @param  array<string, mixed>  $govde
     * @return array<string, mixed>
     */
    public function postSayilariKoruyarak(EntegratorBaglanti $tanim, string $yol, array $govde): array
    {
        $ham = $this->istek($tanim, $yol, $govde);
        $olagan = json_decode($ham, true);

        if (! is_array($olagan)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        $metinli = json_decode($this->sayilariTirnakla($ham), true);

        if (! is_array($metinli)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        /** @var array<string, mixed> */
        return $this->floatlariMetinle($olagan, $metinli);
    }

    /**
     * Kilit, token ve hata eşlemesinden geçen ham yanıt gövdesi.
     *
     * @param  array<string, mixed>  $govde
     */
    private function istek(EntegratorBaglanti $tanim, string $yol, array $govde): string
    {
        if ($yol === self::YOL_GIRIS) {
            throw new InvalidArgumentException('Giriş ucu tokenAl() ile çağrılır.');
        }

        // Kilit token almadan ÖNCE: yasak bir çağrı için giriş isteği de gitmesin
        $this->kilitDenetle($yol, $govde);

        $token = $this->token($tanim);
        $yanit = $this->gonder($tanim, $yol, $govde, $token);

        if ($yanit->status() === 401) {
            $this->onbellek->unut($tanim, $token);
            $yanit = $this->gonder($tanim, $yol, $govde, $this->token($tanim));
        }

        if ($yanit->status() === 401) {
            throw EntegratorHatasi::kimlikHatali();
        }

        if (! $yanit->successful()) {
            throw $this->basarisizYanitHatasi($yanit);
        }

        return $yanit->body();
    }

    /**
     * Geçerli JSON'da dize dışındaki BÜTÜN sayı belirteçlerini tırnağa alır
     * (PHP tam sayısına sığmayan tam sayı da olağan çözümde float'tır).
     * Düzenli ifade değil doğrusal tarama: kaçışlı uzun dizede (.NET base64
     * PDF'teki `+`'yı unicode kaçışıyla yazar) PCRE geri izleme sınırına takılmaz.
     */
    private function sayilariTirnakla(string $govde): string
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
            $sonuc .= '"'.$sayi.'"';
            $i += strlen($sayi);
        }

        return $sonuc;
    }

    /**
     * Olağan çözümü esas alır; her float'ı, tırnaklı kopyada aynı konumdaki
     * metinle `JsonSayisi`'ye çevirir. İki çözüm uyuşmalı: yapı aynı, float
     * aynı sayıyı veren metin, tam sayı aynı tam sayıyı veren metin, gerisi
     * birebir — değilse yanıt geçersiz (dönüşüm hatası sessiz kalmasın — G3).
     */
    private function floatlariMetinle(mixed $olagan, mixed $metinli): mixed
    {
        $uyumlu = match (true) {
            is_float($olagan) => is_string($metinli) && is_numeric($metinli) && (float) $metinli === $olagan,
            is_int($olagan) => is_string($metinli) && is_numeric($metinli) && (int) $metinli === $olagan,
            is_array($olagan) => is_array($metinli) && array_keys($olagan) === array_keys($metinli),
            default => $olagan === $metinli,
        };

        if (! $uyumlu) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        if (is_float($olagan)) {
            /** @var string $metinli */
            return new JsonSayisi($metinli);
        }

        if (is_array($olagan)) {
            /** @var array<array-key, mixed> $metinli */
            foreach ($olagan as $anahtar => $deger) {
                $olagan[$anahtar] = $this->floatlariMetinle($deger, $metinli[$anahtar]);
            }
        }

        return $olagan;
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

        $istemci = $this->istemci($taban, $yol);
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

            $bicim = self::BICIMLER[$anahtar] ?? null;
            $gecersiz = ! is_string($deger)
                || (isset(self::SABIT_DEGERLER[$anahtar]) && ! in_array($deger, self::SABIT_DEGERLER[$anahtar], true))
                || ($bicim !== null && preg_match($bicim, $deger) !== 1);

            if ($gecersiz) {
                throw new InvalidArgumentException("Protel isteğinde izinsiz değer: {$anahtar}");
            }
        }
    }

    /**
     * `parse_url` ters eğik çizgi, `%2f`, boşluk içeren host'u da döndürür
     * (`evil.com\.protel.com.tr` "izinli alan adıyla biter"): alan adı
     * karakterleri ayrıca denetlenir (PRT-F4).
     */
    private function adresDenetle(string $taban): void
    {
        $parca = parse_url($taban);
        $alanAdi = is_array($parca) ? strtolower($parca['host'] ?? '') : '';
        $izinli = false;

        if (is_array($parca) && ($parca['scheme'] ?? '') === 'https' && EntegratorBaglanti::alanAdiGecerli($alanAdi)) {
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

    /**
     * Fatura listesi uzun süre ve iki tekrarla, giriş ve PDF kısa süre ve tek
     * tekrarla (PRT-F2). 429 (istek sınırı) girişte tekrarlanmaz: sınırdaki
     * hesaba art arda giriş göndermek kilitlenmeyi yaklaştırır (PRT-F1).
     */
    private function istemci(string $taban, string $yol): PendingRequest
    {
        $liste = $yol === self::YOL_FATURA_LISTESI;
        $giris = $yol === self::YOL_GIRIS;

        return Http::baseUrl($taban)
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('entegrator.protel.baglanti_zaman_asimi'))
            ->timeout((int) config($liste ? 'entegrator.protel.istek_zaman_asimi' : 'entegrator.protel.kisa_istek_zaman_asimi'))
            // Yönlendirme şifreli gövdeyi başka bir adrese taşıyabilir
            ->withoutRedirecting()
            ->retry(
                $liste ? self::LISTE_YENIDEN_DENEME_MS : self::KISA_YENIDEN_DENEME_MS,
                0,
                fn (?Throwable $hata): bool => $hata instanceof ConnectionException
                    || ($hata instanceof RequestException
                        && ($hata->response->serverError() || (! $giris && $hata->response->status() === 429))),
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

    private function guvenlikPayi(): int
    {
        return (int) config('entegrator.protel.token_guvenlik_payi_saniye');
    }

    /** Kilit altında yalnız giriş yapılır: girişin denemeleri + beklemeleri + pay. */
    private function kilitSuresi(): int
    {
        $denemeSayisi = count(self::KISA_YENIDEN_DENEME_MS) + 1;
        $istekSuresi = (int) config('entegrator.protel.baglanti_zaman_asimi') + (int) config('entegrator.protel.kisa_istek_zaman_asimi');

        return max(30, $denemeSayisi * $istekSuresi + (int) ceil(array_sum(self::KISA_YENIDEN_DENEME_MS) / 1000) + 5);
    }
}
