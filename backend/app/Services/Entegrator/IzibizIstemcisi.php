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
 * İzibiz REST istemcisi — token yönetiminin TEK yeri.
 *
 * Doğrulanan API davranışı (docs/izibiz/api-notlari.md):
 * - `POST /v1/auth/token` → `{data: {accessToken, validity, customerType}, error: null}`
 * - Hatalı kimlik: HTTP 401, `error.code = "10004"`, `group = AUTHENTICATION`
 * - Geçersiz token: HTTP 403, boş gövde
 * - `validity` saat dilimi eki taşımayan İstanbul saatidir; ömür ~12 saat
 * - Uzatma (`PUT`) süreyi uzatmıyor → kullanılmaz, gerekince yeniden alınır
 *
 * Adres tanımdandır (ekrandan; yalnız https + izinli alan adı) ya da ortamın
 * varsayılanı (config/entegrator.php); kayıtlı şifre başka adrese gönderilemez.
 */
final class IzibizIstemcisi
{
    private const TOKEN_YOLU = '/v1/auth/token';

    /** Bağlantı hatası, 5xx ve 429'da bekleme süreleri (ms) — 2 yeniden deneme. */
    private const YENIDEN_DENEME_MS = [250, 1000];

    public function __construct(
        private readonly EntegratorTokenOnbellegi $onbellek,
    ) {}

    /**
     * Önbellek ve kilit OLMADAN kimlikle token alır. Bağlantı sınaması
     * (kaydedilmemiş form değerleri) bunu kullanır: kayıtlı tanımın önbelleği kirlenmez.
     */
    public function tokenAl(EntegratorBaglanti $tanim): IzibizToken
    {
        $this->yolIzinliMi('POST', self::TOKEN_YOLU);

        try {
            $yanit = $this->istemci($tanim)
                ->post(self::TOKEN_YOLU, [
                    'username' => $tanim->kullanici_adi,
                    'password' => $tanim->sifre,
                ]);
        } catch (ConnectionException) {
            throw EntegratorHatasi::erisilemedi();
        }

        $govde = $yanit->json();
        $hata = is_array($govde) ? ($govde['error'] ?? null) : null;

        if ($yanit->status() === 401 || $this->kimlikHatasiMi($hata)) {
            throw EntegratorHatasi::kimlikHatali($this->hataKodu($hata));
        }

        if (! $yanit->successful()) {
            throw $this->basarisizYanitHatasi($yanit, $hata);
        }

        if ($hata !== null) {
            // HTTP 200 içinde dolu `error` da başarı sayılmaz
            throw EntegratorHatasi::saglayiciHatasi($this->hataKodu($hata) ?? 'BILINMIYOR', $yanit->status());
        }

        $veri = is_array($govde) ? ($govde['data'] ?? null) : null;
        $erisimToken = is_array($veri) ? ($veri['accessToken'] ?? null) : null;
        $gecerlilik = is_array($veri) ? ($veri['validity'] ?? null) : null;

        if (! is_string($erisimToken) || $erisimToken === '' || ! is_string($gecerlilik)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        return new IzibizToken(
            erisimToken: $erisimToken,
            bitis: $this->bitisZamani($gecerlilik),
            musteriTipi: is_string($veri['customerType'] ?? null) ? $veri['customerType'] : '',
        );
    }

    /**
     * Kayıtlı tanım için geçerli token (EntegratorTokenOnbellegi): önbellekte
     * şifreli, eşzamanlı yenileme (web + kuyruk) tek girişe indirilir,
     * reddedilen kimlik otomatik işlerde yeniden denenmez. Buradan çıkan hata
     * `girisHatasi` işaretlidir: çağıran onu isteğin hatasından ayırabilsin (C-3).
     */
    public function token(EntegratorBaglanti $tanim): string
    {
        try {
            return $this->onbellek->token($tanim, $this->kilitSuresi(), $this->guvenlikPayi(), function () use ($tanim): array {
                $token = $this->tokenAl($tanim);

                return [$token->erisimToken, $token->bitis];
            });
        } catch (EntegratorHatasi $hata) {
            throw $hata->giristen();
        }
    }

    /**
     * Token'lı GET isteği (listeleme uçları — EFAT-07). 401/403 gelirse token
     * bir kez yenilenip istek tekrarlanır; ikinci red kalıcı hata sayılır.
     * Hata hiçbir zaman boş başarılı yanıta çevrilmez.
     *
     * @param  array<string, mixed>  $sorgu
     * @return array<string, mixed>
     */
    public function getJson(EntegratorBaglanti $tanim, string $yol, array $sorgu = []): array
    {
        $yanit = $this->yenilemeliGet($tanim, $yol, $sorgu, 'application/json');

        $govde = $yanit->json();
        $hata = is_array($govde) ? ($govde['error'] ?? null) : null;

        $this->basarisizsaFirlat($yanit, $hata);

        if ($hata !== null) {
            throw EntegratorHatasi::saglayiciHatasi($this->hataKodu($hata) ?? 'BILINMIYOR', $yanit->status());
        }

        if (! is_array($govde) || ! is_array($govde['data'] ?? null)) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        /** @var array<string, mixed> $govde */
        return $govde;
    }

    /**
     * Token'lı PDF okuma (fatura görüntüleme — EFAT-11). Yalnız `preview/pdf`
     * gibi okuma uçları içindir; İzibiz okundu bayraklarını değiştirmediği
     * ölçüldü (api-notlari.md). Gövde `%PDF-` ile başlamıyorsa geçersiz sayılır
     * (İzibiz Content-Type başlığını boş döndürüyor).
     */
    public function getPdf(EntegratorBaglanti $tanim, string $yol): string
    {
        $yanit = $this->yenilemeliGet($tanim, $yol, [], 'application/pdf, application/json');

        $govde = $yanit->json();
        $this->basarisizsaFirlat($yanit, is_array($govde) ? ($govde['error'] ?? null) : null);

        $pdf = $yanit->body();

        if (! str_starts_with($pdf, '%PDF-')) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        return $pdf;
    }

    /**
     * Faturaların UBL'lerini TOPLU indirir (`POST .../{inbox|outbox}/download/ubl`,
     * gövde `[{id}]`); yanıttaki base64 zip'i ham bayt olarak döner. Yalnız
     * okuma: test hesabında ölçüldü, okundu bayrakları DEĞİŞMİYOR (2026-09-24).
     * Tek istekte en çok 100 fatura (İzibiz kuralı). Partide sorunlu bir fatura
     * varsa İzibiz tüm isteği HTTP 400 + kod 10008 ile reddeder — çağıran
     * yalnız bu durumda bölerek dener (IzibizUblServisi).
     *
     * @param  list<int>  $kaynakIdleri  İzibiz fatura kimlikleri (ETTN değil)
     */
    public function ublIndir(EntegratorBaglanti $tanim, FaturaYonu $yon, array $kaynakIdleri): string
    {
        if ($kaynakIdleri === [] || count($kaynakIdleri) > EntegratorBaglanti::ENCOK_SAYFA_BOYUTU) {
            throw new InvalidArgumentException('Toplu indirmede 1–100 fatura olmalı.');
        }

        $yol = '/v1/einvoices/'.$yon->izibizKutusu().'/download/ubl';
        $govde = array_map(fn (int $id): array => ['id' => $id], $kaynakIdleri);

        $gonder = function (string $token) use ($tanim, $yol, $govde): Response {
            try {
                return $this->istemci($tanim)->withToken($token)->acceptJson()->post($yol, $govde);
            } catch (ConnectionException) {
                throw EntegratorHatasi::erisilemedi();
            }
        };

        $this->yolIzinliMi('POST', $yol);
        $token = $this->token($tanim);
        $yanit = $gonder($token);

        if (in_array($yanit->status(), [401, 403], true)) {
            $this->onbellek->unut($tanim, $token);
            $yanit = $gonder($this->token($tanim));
        }

        $json = $yanit->json();
        $hata = is_array($json) ? ($json['error'] ?? null) : null;
        $this->basarisizsaFirlat($yanit, $hata);

        if ($hata !== null) {
            throw EntegratorHatasi::saglayiciHatasi($this->hataKodu($hata) ?? 'BILINMIYOR', $yanit->status());
        }

        $zip = base64_decode((string) ($json['data']['content'] ?? ''), true);

        if ($zip === false || ! str_starts_with($zip, 'PK')) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        return $zip;
    }

    /**
     * Güvenlik kilidi — İZİN LİSTESİ: bu uygulama İzibiz'de yalnız okur.
     * Yalnız aşağıdaki yöntem + yol kalıplarına istek çıkabilir; listede
     * olmayan HER uç (okundu işaretleme, kabul/red, gönderme, iptal ve
     * İzibiz'in ileride ekleyeceği bilinmeyen uçlar) kod düzeyinde reddedilir.
     * Okundu işareti mevcut ERP entegrasyonunun işidir (kullanıcı kuralı).
     * Yeni bir uç gerekirse önce okuma olduğu doğrulanır, sonra buraya eklenir.
     */
    private const IZINLI_UCLAR = [
        'POST' => [
            // Giriş
            '#^/v1/auth/token$#D',
            // Toplu UBL indirme — okundu bayrağına dokunmadığı ölçüldü (api-notlari.md)
            '#^/v1/einvoices/(inbox|outbox)/download/ubl$#D',
        ],
        'GET' => [
            // Sayfalı fatura listesi
            '#^/v1/einvoices/(inbox|outbox)$#D',
            // PDF önizleme — okundu bayrağına dokunmadığı ölçüldü
            '#^/v1/einvoices/(inbox|outbox)/[0-9]+/preview/pdf$#D',
        ],
    ];

    private function yolIzinliMi(string $yontem, string $yol): void
    {
        foreach (self::IZINLI_UCLAR[$yontem] ?? [] as $kalip) {
            if (preg_match($kalip, $yol) === 1) {
                return;
            }
        }

        throw new InvalidArgumentException("Bu İzibiz ucu bu uygulamada kullanılamaz (yalnız okuma): {$yontem} {$yol}");
    }

    /**
     * Token'lı GET; 401/403 gelirse token bir kez yenilenip tekrarlanır.
     * Bearer token yalnız İzibiz'in göreli API yollarına gönderilebilir.
     *
     * @param  array<string, mixed>  $sorgu
     */
    private function yenilemeliGet(EntegratorBaglanti $tanim, string $yol, array $sorgu, string $kabul): Response
    {
        $this->yolIzinliMi('GET', $yol);

        $token = $this->token($tanim);
        $yanit = $this->tokenliGet($tanim, $yol, $sorgu, $token, $kabul);

        if (in_array($yanit->status(), [401, 403], true)) {
            $this->onbellek->unut($tanim, $token);
            $yanit = $this->tokenliGet($tanim, $yol, $sorgu, $this->token($tanim), $kabul);
        }

        return $yanit;
    }

    /** İkinci red kalıcı hatadır; başarısız yanıt hiçbir zaman boş başarıya çevrilmez. */
    private function basarisizsaFirlat(Response $yanit, mixed $hata): void
    {
        if (in_array($yanit->status(), [401, 403], true)) {
            throw $yanit->status() === 401
                ? EntegratorHatasi::kimlikHatali($this->hataKodu($hata))
                : EntegratorHatasi::saglayiciHatasi($this->hataKodu($hata) ?? 'YETKI_YOK', 403);
        }

        if (! $yanit->successful()) {
            throw $this->basarisizYanitHatasi($yanit, $hata);
        }
    }

    /**
     * @param  array<string, mixed>  $sorgu
     */
    private function tokenliGet(EntegratorBaglanti $tanim, string $yol, array $sorgu, string $token, string $kabul): Response
    {
        try {
            return $this->istemci($tanim)->withToken($token)->accept($kabul)->get($yol, $sorgu);
        } catch (ConnectionException) {
            throw EntegratorHatasi::erisilemedi();
        }
    }

    private function istemci(EntegratorBaglanti $tanim): PendingRequest
    {
        return Http::baseUrl($tanim->apiUrl())
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('entegrator.izibiz.baglanti_zaman_asimi'))
            ->timeout((int) config('entegrator.izibiz.istek_zaman_asimi'))
            // Adres tanımdan (izinli alan adı) ya da config varsayılanından; başka hedefe yönlendirme izlenmez
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

    /**
     * `validity` İstanbul saatidir; UTC'ye çevrilir. Bozuk ya da güvenlik payı
     * içinde bitecek değer başarı kabul edilmez.
     */
    private function bitisZamani(string $gecerlilik): CarbonImmutable
    {
        try {
            $bitis = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $gecerlilik,
                (string) config('entegrator.izibiz.saat_dilimi'),
            );
        } catch (Throwable) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        // DateTime 31 Eylül gibi tarihleri sonraki aya normalize edebilir.
        if (! $bitis instanceof CarbonImmutable || $bitis->format('Y-m-d H:i:s') !== $gecerlilik) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        $bitis = $bitis->utc();

        // Önbellekte en az bir dakika kalabilecek kadar ömrü olmalı
        if ($bitis->getTimestamp() - CarbonImmutable::now()->getTimestamp() < $this->guvenlikPayi() + 60) {
            throw EntegratorHatasi::yanitGecersiz();
        }

        return $bitis;
    }

    private function basarisizYanitHatasi(Response $yanit, mixed $hata): EntegratorHatasi
    {
        $kod = $this->hataKodu($hata);

        if ($kod !== null) {
            return EntegratorHatasi::saglayiciHatasi($kod, $yanit->status());
        }

        // Gövdesiz 5xx/429 (yeniden denemeler tükendi) → ulaşılamadı
        return $yanit->serverError() || $yanit->status() === 429
            ? EntegratorHatasi::erisilemedi($yanit->status())
            : EntegratorHatasi::saglayiciHatasi('HTTP_'.$yanit->status(), $yanit->status());
    }

    private function kimlikHatasiMi(mixed $hata): bool
    {
        return is_array($hata) && ($hata['group'] ?? null) === 'AUTHENTICATION';
    }

    private function hataKodu(mixed $hata): ?string
    {
        if (! is_array($hata) || ! isset($hata['code'])) {
            return null;
        }

        return is_scalar($hata['code']) ? (string) $hata['code'] : null;
    }

    private function guvenlikPayi(): int
    {
        return (int) config('entegrator.izibiz.token_guvenlik_payi_saniye');
    }

    /** Kilit, üç HTTP denemesi ve iki beklemenin toplamından uzun yaşamalı. */
    private function kilitSuresi(): int
    {
        $denemeSayisi = count(self::YENIDEN_DENEME_MS) + 1;
        $istekSuresi = (int) config('entegrator.izibiz.istek_zaman_asimi');

        return max(30, $denemeSayisi * $istekSuresi + (int) ceil(array_sum(self::YENIDEN_DENEME_MS) / 1000) + 10);
    }
}
