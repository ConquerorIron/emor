<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * e-Belge entegratörü bağlantı tanımları: İzibiz (inşaat) ve Protel (otel).
 * Sağlayıcı verilmezse İzibiz — mevcut çağıranlar (eFatura ekranları, alarm,
 * eMOR) yalnız İzibiz'i görür. Aktiflik sağlayıcı başınadır ve SQL'in aktif
 * ortamından bağımsızdır (EFAT-15, S5).
 */
final class EntegratorBaglantiServisi
{
    private const SAGLAYICI = EntegratorBaglanti::SAGLAYICI_IZIBIZ;

    /**
     * @return array<string, EntegratorBaglanti> ortam => tanım
     */
    public function listele(string $saglayici = self::SAGLAYICI): array
    {
        return EntegratorBaglanti::query()
            ->where('saglayici', $saglayici)
            ->orderBy('ortam')
            ->get()
            ->keyBy('ortam')
            ->all();
    }

    /** Statik kaynakta (Logo, e-Arşiv) tanım her zaman vardır ve aktiftir. */
    public function aktif(string $saglayici = self::SAGLAYICI): ?EntegratorBaglanti
    {
        if (in_array($saglayici, EntegratorBaglanti::STATIK_SAGLAYICILAR, true)) {
            return $this->statikTanim($saglayici);
        }

        return EntegratorBaglanti::query()
            ->where('saglayici', $saglayici)
            ->where('aktif', true)
            ->first();
    }

    /**
     * Gelen faturaların bütün kaynakları: sağlayıcı başına aktif tanım; statik
     * kaynaklar (Logo, e-Arşiv) her zaman. Tüm Faturalar ve pano aynı kapsamı
     * kullanır (sayılar ile liste ayrışmasın).
     *
     * @return list<EntegratorBaglanti>
     */
    public function gelenTanimlari(): array
    {
        return array_values(array_filter(array_map(
            fn (string $saglayici): ?EntegratorBaglanti => $this->aktif($saglayici),
            [EntegratorBaglanti::SAGLAYICI_IZIBIZ, EntegratorBaglanti::SAGLAYICI_PROTEL, ...EntegratorBaglanti::STATIK_SAGLAYICILAR],
        )));
    }

    /**
     * Statik kaynağın (EK-05) tanımı; ilk istenişte oluşturulur. Bağlantı
     * alanları (kullanıcı, şifre, VKN) kullanılmaz ve boş kalır: statik tanım
     * hiçbir dış sisteme istek çıkarmaz. Ortamdan bağımsızdır (`canli` satırı).
     * Önce okunur (var olan satıra yazma denenmez); eşzamanlı ilk istekte
     * tekil indeks çakışması mevcut satırı döndürür (firstOrCreate → createOrFirst).
     */
    public function statikTanim(string $saglayici): EntegratorBaglanti
    {
        return EntegratorBaglanti::query()->firstOrCreate(
            ['saglayici' => $saglayici, 'ortam' => EntegratorBaglanti::ORTAM_CANLI],
            ['kullanici_adi' => '', 'sifre' => '', 'vkn' => '', 'aktif' => true],
        );
    }

    /**
     * eFatura ekran uçlarının sağlayıcısı: Protel ve statik kaynak (Logo,
     * e-Arşiv) rotaları `saglayici` varsayılanını taşır (routes/api.php);
     * diğer her durumda İzibiz.
     */
    public static function rotaninSaglayicisi(Request $request): string
    {
        $saglayici = $request->route('saglayici');

        return in_array($saglayici, [EntegratorBaglanti::SAGLAYICI_PROTEL, ...EntegratorBaglanti::STATIK_SAGLAYICILAR], true)
            ? $saglayici
            : self::SAGLAYICI;
    }

    public function tanim(string $ortam, string $saglayici = self::SAGLAYICI): ?EntegratorBaglanti
    {
        return EntegratorBaglanti::query()
            ->where('saglayici', $saglayici)
            ->where('ortam', $ortam)
            ->first();
    }

    /**
     * Ortam tanımını oluşturur/günceller. Şifre yalnız gönderildiyse değişir;
     * kullanıcı adı değişiyorsa şifre de yeniden girilmelidir (kayıtlı şifre
     * başka bir hesabın şifresi olarak denenmesin).
     *
     * @param  array{kullanici_adi: string, sifre?: string|null, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null, api_url?: string|null, auth_url?: string|null, senkron_araligi_dakika?: int, sayfa_boyutu?: int}  $veri
     */
    public function guncelle(string $ortam, array $veri, string $saglayici = self::SAGLAYICI): EntegratorBaglanti
    {
        try {
            return DB::transaction(fn (): EntegratorBaglanti => $this->kaydet($ortam, $veri, $saglayici));
        } catch (UniqueConstraintViolationException) {
            // Aynı ortamın ilk kaydı eşzamanlı yapıldı; biri kazandı
            throw ValidationException::withMessages([
                'ortam' => __('hata.entegrator_eszamanli_guncelleme'),
            ]);
        }
    }

    /**
     * Global aktif ortamı değiştirir. SQL'in aktif ortamına dokunmaz.
     */
    public function aktifYap(string $ortam, string $saglayici = self::SAGLAYICI): EntegratorBaglanti
    {
        return DB::transaction(function () use ($ortam, $saglayici): EntegratorBaglanti {
            // Tüm ortamları aynı sırada kilitle: eşzamanlı ters geçişte
            // süreçler birbirinin hedef satırında kilitlenip deadlock üretmesin.
            $tanimlar = EntegratorBaglanti::query()
                ->where('saglayici', $saglayici)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $hedef = $tanimlar->firstWhere('ortam', $ortam);

            if ($hedef === null) {
                throw ValidationException::withMessages([
                    'ortam' => __('hata.entegrator_baglanti_tanimsiz'),
                ]);
            }

            EntegratorBaglanti::query()
                ->where('saglayici', $saglayici)
                ->where('id', '!=', $hedef->id)
                ->update(['aktif' => false]);

            $hedef->aktif = true;
            $hedef->save();

            return $hedef;
        });
    }

    /**
     * Boş adres NULL (varsayılan) saklanır; dolu adres normalleştirilir
     * (doğrulamadan geçmiş olmalı: `EntegratorApiAdresi`).
     */
    public static function saklanacakAdres(?string $adres): ?string
    {
        return $adres === null || trim($adres) === ''
            ? null
            : EntegratorBaglanti::adresNormallestir($adres);
    }

    /**
     * @param  array{kullanici_adi: string, sifre?: string|null, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null, api_url?: string|null, auth_url?: string|null, senkron_araligi_dakika?: int, sayfa_boyutu?: int}  $veri
     */
    private function kaydet(string $ortam, array $veri, string $saglayici): EntegratorBaglanti
    {
        $baglanti = EntegratorBaglanti::query()
            ->where('saglayici', $saglayici)
            ->where('ortam', $ortam)
            ->lockForUpdate()
            ->first() ?? new EntegratorBaglanti(['saglayici' => $saglayici, 'ortam' => $ortam]);

        $sifre = $veri['sifre'] ?? null;
        $sifreBos = $sifre === null || $sifre === '';

        if (! $baglanti->exists && $sifreBos) {
            throw ValidationException::withMessages([
                'sifre' => __('hata.entegrator_sifre_zorunlu'),
            ]);
        }

        $kullaniciDegisti = $baglanti->exists && $baglanti->kullanici_adi !== $veri['kullanici_adi'];

        if ($kullaniciDegisti && $sifreBos) {
            throw ValidationException::withMessages([
                'sifre' => __('hata.entegrator_sifre_kullanici_degisti'),
            ]);
        }

        // Adres gönderilmediyse değişmez; boş = ortamın varsayılanı. Protel'in
        // giriş sunucusu ayrıdır (`auth_url`); İzibiz'de giriş adresi saklanmaz
        $protel = $saglayici === EntegratorBaglanti::SAGLAYICI_PROTEL;
        $yeniAdres = array_key_exists('api_url', $veri)
            ? self::saklanacakAdres($veri['api_url'])
            : $baglanti->api_url;
        $yeniGirisAdresi = ! $protel ? null : (array_key_exists('auth_url', $veri)
            ? self::saklanacakAdres($veri['auth_url'])
            : $baglanti->auth_url);
        $adresDegisti = $baglanti->exists
            && (($yeniAdres ?? $baglanti->varsayilanApiUrl()) !== $baglanti->apiUrl()
                || ($protel && ($yeniGirisAdresi ?? $baglanti->varsayilanAuthUrl()) !== $baglanti->authUrl()));

        // Kayıtlı şifre yeni (henüz doğrulanmamış) bir adrese gönderilmesin
        if ($adresDegisti && $sifreBos) {
            throw ValidationException::withMessages([
                'sifre' => __('hata.entegrator_sifre_adres_degisti'),
            ]);
        }

        // Protel'de VKN firmanın posta kutusudur; token ve fatura geçmişi o
        // firmaya aittir. Geçmişi olan tanımda VKN değişirse eski firmanın
        // faturaları yeni firmanınmış gibi görünür (PRT-R1) — reddedilir.
        $vknDegisti = $baglanti->exists && $baglanti->vkn !== $veri['vkn'];

        if ($protel && $vknDegisti && $this->gecmisiVar($baglanti)) {
            throw ValidationException::withMessages([
                'vkn' => __('hata.protel_vkn_gecmisli'),
            ]);
        }

        $eskiTokenAnahtari = $baglanti->exists ? $baglanti->tokenOnbellekAnahtari() : null;

        $baglanti->fill([
            'api_url' => $yeniAdres,
            'auth_url' => $yeniGirisAdresi,
            'kullanici_adi' => $veri['kullanici_adi'],
            'vkn' => $veri['vkn'],
            'posta_kutusu' => $protel ? null : ($veri['posta_kutusu'] ?? null),
            'gonderici_birim' => $protel ? null : ($veri['gonderici_birim'] ?? null),
        ]);

        // Senkron ayarları gönderilmediyse değişmez (tek seferlik içe aktarma göndermez)
        foreach (['senkron_araligi_dakika', 'sayfa_boyutu'] as $ayar) {
            if (array_key_exists($ayar, $veri)) {
                $baglanti->{$ayar} = (int) $veri[$ayar];
            }
        }

        if (! $sifreBos) {
            $baglanti->sifre = $sifre;
        }

        // Adres değişince eski token kullanılmasın. Protel'de token firmaya
        // (VKN) bağlıdır: VKN değişince de yeni giriş yapılır ve firma yeniden
        // doğrulanır (PRT-R1). İzibiz token'ı VKN'ye bağlı değildir.
        $kimlikDegisti = $baglanti->exists
            && ($kullaniciDegisti || $adresDegisti || ($protel && $vknDegisti) || ! $sifreBos);

        if ($kimlikDegisti) {
            $baglanti->kimlik_surumu++;
        }

        $baglanti->save();

        if ($kimlikDegisti && $eskiTokenAnahtari !== null) {
            // Anahtar sürümü zaten değişti; eski token'ı da önbellekte bırakma
            Cache::forget($eskiTokenAnahtari);
        }

        return $baglanti;
    }

    /** Tanıma yazılmış fatura ya da (süren dahil) senkron çalışması var mı. */
    private function gecmisiVar(EntegratorBaglanti $baglanti): bool
    {
        return EFatura::query()->where('entegrator_baglanti_id', $baglanti->id)->exists()
            || EFaturaSenkronCalismasi::query()->where('entegrator_baglanti_id', $baglanti->id)->exists();
    }
}
