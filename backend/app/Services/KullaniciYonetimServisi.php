<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kullanıcı yönetimi (EFAT-18, kullanıcı kararı 2026-09-24): ERP'deki
 * kullanıcılar listelenir; hangilerinin uygulamaya girebileceği ve rolleri
 * burada tanımlanır. Giriş ERP şifresiyle yapılır, ama yalnız burada izin
 * verilmiş (aktif) kullanıcılar girebilir. Lokal kullanıcı açılmaz — ERP'de
 * olmayan biri gerekiyorsa ERP'de kullanıcı açılır.
 *
 * Kurallar:
 * - ERP kullanıcısının adı/kullanıcı adı/şifresi ERP'den gelir, burada değişmez.
 * - Kimse kendini pasife alamaz; `.env` yedek admini pasife alınamaz.
 * - `sistem_yoneticisi` bu ekrandan verilmez (ERP'den ya da yedek adminden gelir).
 * - Silme yok: izni kaldırma (geçmiş kayıtlarla bağ korunur).
 */
final class KullaniciYonetimServisi
{
    public function __construct(
        private readonly ErpKimlikDogrulayici $erp,
    ) {}

    /**
     * ERP kullanıcıları + uygulamadaki tanımları. ERP okunamazsa yalnız
     * uygulamadaki kullanıcılar döner ve `erp_okunamadi` işaretlenir.
     *
     * @return array{kullanicilar: list<array{id: int|null, erp_kullanici_id: int|null, kullanici_adi: string, ad: string, kaynak: string, sistem_yoneticisi: bool, aktif_mi: bool, rol_idleri: list<int>, erpde_yok: bool}>, erp_okunamadi: bool}
     */
    public function listele(): array
    {
        $tanimlilar = User::query()->with('roller:id')->get();

        try {
            $erpKullanicilari = $this->erp->kullanicilar();
            $erpOkunamadi = false;
        } catch (Throwable) {
            $erpKullanicilari = [];
            $erpOkunamadi = true;
        }

        $erpIdIle = $tanimlilar->whereNotNull('erp_kullanici_id')->keyBy('erp_kullanici_id');
        $satirlar = [];
        $gorulen = [];

        foreach ($erpKullanicilari as $erp) {
            $tanim = $erpIdIle->get($erp['erp_kullanici_id']);
            $satirlar[] = [
                'id' => $tanim?->id,
                'erp_kullanici_id' => $erp['erp_kullanici_id'],
                'kullanici_adi' => $erp['kullanici_adi'],
                'ad' => $erp['ad'],
                'kaynak' => User::KAYNAK_ERP,
                'sistem_yoneticisi' => $erp['sistem_yoneticisi'],
                // Tanımlanmamış ERP kullanıcısı giriş yapamaz
                'aktif_mi' => $tanim !== null && $tanim->aktif_mi,
                'rol_idleri' => $tanim?->roller->modelKeys() ?? [],
                'erpde_yok' => false,
            ];

            if ($tanim !== null) {
                $gorulen[$tanim->id] = true;
            }
        }

        // ERP listesinde olmayan tanımlar (yedek admin, ERP'den silinmiş kullanıcı)
        foreach ($tanimlilar as $tanim) {
            if (! isset($gorulen[$tanim->id])) {
                $satirlar[] = [
                    'id' => $tanim->id,
                    'erp_kullanici_id' => $tanim->erp_kullanici_id,
                    'kullanici_adi' => $tanim->kullanici_adi,
                    'ad' => $tanim->ad,
                    'kaynak' => $tanim->kaynak,
                    'sistem_yoneticisi' => $tanim->sistem_yoneticisi,
                    'aktif_mi' => $tanim->aktif_mi,
                    'rol_idleri' => $tanim->roller->modelKeys(),
                    'erpde_yok' => $tanim->kaynak === User::KAYNAK_ERP && ! $erpOkunamadi,
                ];
            }
        }

        usort($satirlar, fn (array $a, array $b): int => strcmp(mb_strtolower($a['ad']), mb_strtolower($b['ad'])));

        return ['kullanicilar' => $satirlar, 'erp_okunamadi' => $erpOkunamadi];
    }

    /**
     * ERP kullanıcısını uygulamaya tanımlar (giriş izni + roller). Kişinin daha
     * önce giriş yapmış olması gerekmez; bilgiler ERP'den okunur.
     *
     * @param  array{erp_kullanici_id: int, aktif_mi?: bool, rol_idleri?: list<int>}  $veri
     * @param  array{erp_kullanici_id: int, kullanici_adi: string, ad: string, sistem_yoneticisi: bool}  $erpKullanici
     */
    public function tanimla(array $erpKullanici, array $veri): User
    {
        if (User::query()->where('erp_kullanici_id', $erpKullanici['erp_kullanici_id'])->exists()) {
            throw ValidationException::withMessages(['erp_kullanici_id' => __('hata.kullanici_zaten_tanimli')]);
        }

        return DB::transaction(function () use ($erpKullanici, $veri): User {
            $user = User::query()->create([
                'kullanici_adi' => $erpKullanici['kullanici_adi'],
                'ad' => $erpKullanici['ad'],
                'kaynak' => User::KAYNAK_ERP,
                'erp_kullanici_id' => $erpKullanici['erp_kullanici_id'],
                'sistem_yoneticisi' => $erpKullanici['sistem_yoneticisi'],
                'aktif_mi' => filter_var($veri['aktif_mi'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);

            $user->roller()->sync($veri['rol_idleri'] ?? []);

            return $user;
        });
    }

    /**
     * ERP kullanıcısını bulur (tanımlarken); yoksa doğrulama hatası.
     *
     * @return array{erp_kullanici_id: int, kullanici_adi: string, ad: string, sistem_yoneticisi: bool}
     */
    public function erpKullanicisi(int $erpKullaniciId): array
    {
        try {
            $erpKullanicilari = $this->erp->kullanicilar();
        } catch (Throwable) {
            throw ValidationException::withMessages(['erp_kullanici_id' => __('hata.erp_kullanici_denetlenemedi')]);
        }

        foreach ($erpKullanicilari as $erp) {
            if ($erp['erp_kullanici_id'] === $erpKullaniciId) {
                return $erp;
            }
        }

        throw ValidationException::withMessages(['erp_kullanici_id' => __('hata.erp_kullanicisi_yok')]);
    }

    /**
     * Giriş izni ve roller. Ad/şifre ERP'den gelir, burada değişmez.
     *
     * @param  array{aktif_mi?: bool, rol_idleri?: list<int>}  $veri
     */
    public function guncelle(User $hedef, User $yapan, array $veri): User
    {
        // `boolean` kuralı 0/"0"/"false" da kabul eder ve ham değeri döndürür:
        // kilitler katı `=== false` ile değil normalize değerle denetlenir
        $aktif = array_key_exists('aktif_mi', $veri) ? filter_var($veri['aktif_mi'], FILTER_VALIDATE_BOOLEAN) : null;

        if ($aktif === false) {
            if ($hedef->is($yapan)) {
                throw ValidationException::withMessages(['aktif_mi' => __('hata.kendini_pasif_yapamaz')]);
            }

            if ($hedef->yedekAdminMi()) {
                throw ValidationException::withMessages(['aktif_mi' => __('hata.yedek_admin_pasif_yapilamaz')]);
            }
        }

        return DB::transaction(function () use ($hedef, $veri, $aktif): User {
            if ($aktif !== null) {
                $hedef->aktif_mi = $aktif;
                $hedef->save();
            }

            if (array_key_exists('rol_idleri', $veri)) {
                $hedef->roller()->sync($veri['rol_idleri']);
            }

            return $hedef;
        });
    }
}
