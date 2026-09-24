<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EntegratorBaglanti;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * e-Belge entegratörü bağlantı tanımları (şimdilik yalnız İzibiz).
 * Aktif ortam SQL'in aktif ortamından bağımsızdır (EFAT-15, S5).
 */
final class EntegratorBaglantiServisi
{
    private const SAGLAYICI = EntegratorBaglanti::SAGLAYICI_IZIBIZ;

    /**
     * @return array<string, EntegratorBaglanti> ortam => tanım
     */
    public function listele(): array
    {
        return EntegratorBaglanti::query()
            ->where('saglayici', self::SAGLAYICI)
            ->orderBy('ortam')
            ->get()
            ->keyBy('ortam')
            ->all();
    }

    public function aktif(): ?EntegratorBaglanti
    {
        return EntegratorBaglanti::query()
            ->where('saglayici', self::SAGLAYICI)
            ->where('aktif', true)
            ->first();
    }

    public function tanim(string $ortam): ?EntegratorBaglanti
    {
        return EntegratorBaglanti::query()
            ->where('saglayici', self::SAGLAYICI)
            ->where('ortam', $ortam)
            ->first();
    }

    /**
     * Ortam tanımını oluşturur/günceller. Şifre yalnız gönderildiyse değişir;
     * kullanıcı adı değişiyorsa şifre de yeniden girilmelidir (kayıtlı şifre
     * başka bir hesabın şifresi olarak denenmesin).
     *
     * @param  array{kullanici_adi: string, sifre?: string|null, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null}  $veri
     */
    public function guncelle(string $ortam, array $veri): EntegratorBaglanti
    {
        try {
            return DB::transaction(fn (): EntegratorBaglanti => $this->kaydet($ortam, $veri));
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
    public function aktifYap(string $ortam): EntegratorBaglanti
    {
        return DB::transaction(function () use ($ortam): EntegratorBaglanti {
            // Tüm ortamları aynı sırada kilitle: eşzamanlı ters geçişte
            // süreçler birbirinin hedef satırında kilitlenip deadlock üretmesin.
            $tanimlar = EntegratorBaglanti::query()
                ->where('saglayici', self::SAGLAYICI)
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
                ->where('saglayici', self::SAGLAYICI)
                ->where('id', '!=', $hedef->id)
                ->update(['aktif' => false]);

            $hedef->aktif = true;
            $hedef->save();

            return $hedef;
        });
    }

    /**
     * @param  array{kullanici_adi: string, sifre?: string|null, vkn: string, posta_kutusu?: string|null, gonderici_birim?: string|null}  $veri
     */
    private function kaydet(string $ortam, array $veri): EntegratorBaglanti
    {
        $baglanti = EntegratorBaglanti::query()
            ->where('saglayici', self::SAGLAYICI)
            ->where('ortam', $ortam)
            ->lockForUpdate()
            ->first() ?? new EntegratorBaglanti(['saglayici' => self::SAGLAYICI, 'ortam' => $ortam]);

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

        $eskiTokenAnahtari = $baglanti->exists ? $baglanti->tokenOnbellekAnahtari() : null;

        $baglanti->fill([
            'kullanici_adi' => $veri['kullanici_adi'],
            'vkn' => $veri['vkn'],
            'posta_kutusu' => $veri['posta_kutusu'] ?? null,
            'gonderici_birim' => $veri['gonderici_birim'] ?? null,
        ]);

        if (! $sifreBos) {
            $baglanti->sifre = $sifre;
        }

        $kimlikDegisti = $baglanti->exists && ($kullaniciDegisti || ! $sifreBos);

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
}
